<?php

namespace App\Modules\Dashboard\Services\EmployeeSessions;

use App\Enums\EmployeeSessionEndedReason;
use App\Enums\EmployeeSessionStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\EmployeeSession;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class EmployeeSessionService
{
    public function __construct(
        private readonly EmployeeSessionStatusResolver $statusResolver,
        private readonly EmployeeSessionDeviceParser $deviceParser,
        private readonly EmployeeSessionLastSeenService $lastSeen,
        private readonly AuditLogService $audit,
        private readonly BusinessClock $clock,
    ) {}

    /**
     * Create Sanctum token + linked employee session atomically.
     *
     * @return array{token: string, user: User, session: EmployeeSession}
     */
    public function startDashboardSession(User $user, Request $request): array
    {
        return DB::transaction(function () use ($user, $request) {
            /** @var NewAccessToken $newToken */
            $newToken = $user->createToken((string) config('employee_sessions.token_name', 'dashboard-token'));
            $tokenModel = $newToken->accessToken;

            try {
                $ua = $request->userAgent();
                $meta = $this->deviceParser->parse($ua);
                $ip = $request->ip();
                $now = now();

                $session = EmployeeSession::query()->create([
                    'session_uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'auth_driver' => (string) config('employee_sessions.auth_driver', 'sanctum'),
                    'personal_access_token_id' => $tokenModel->id,
                    'logged_in_at' => $now,
                    'last_seen_at' => $now,
                    'expires_at' => $tokenModel->expires_at,
                    'initial_ip_address' => $ip,
                    'last_ip_address' => $ip,
                    'user_agent' => $ua,
                    'device_type' => $meta['device_type'],
                    'operating_system' => $meta['operating_system'],
                    'browser' => $meta['browser'],
                    'browser_version' => $meta['browser_version'],
                ]);
            } catch (\Throwable $e) {
                $tokenModel->delete();
                report($e);
                throw new ApiException('messages.employee_sessions.session_start_failed', 500);
            }

            $this->audit->log(
                $user,
                'employee_session.started',
                'employee_session',
                $session->id,
                null,
                $this->auditPayload($session),
                $request
            );

            return [
                'token' => $newToken->plainTextToken,
                'user' => $user,
                'session' => $session,
            ];
        });
    }

    public function markExplicitLogout(User $user, Request $request): void
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        DB::transaction(function () use ($user, $token, $request) {
            /** @var EmployeeSession|null $session */
            $session = EmployeeSession::query()
                ->where('personal_access_token_id', $token->id)
                ->lockForUpdate()
                ->first();

            if ($session !== null
                && $session->revoked_at === null
                && $session->logged_out_at === null
            ) {
                $old = $this->lifecycleSnapshot($session);
                $session->logged_out_at = now();
                $session->last_seen_at = now();
                $session->ended_reason = EmployeeSessionEndedReason::ExplicitLogout->value;
                if ($request->ip()) {
                    $session->last_ip_address = $request->ip();
                }
                $session->save();

                $this->audit->log(
                    $user,
                    'employee_session.logged_out',
                    'employee_session',
                    $session->id,
                    $old,
                    $this->auditPayload($session),
                    $request
                );
            }

            // Always invalidate the credential (idempotent logout).
            $token->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $perPage = min(
            max(1, (int) ($filters['per_page'] ?? config('employee_sessions.default_per_page', 20))),
            (int) config('employee_sessions.max_per_page', 100)
        );

        $query = $this->filteredQuery($filters)
            ->with(['user.role', 'revokedByUser', 'personalAccessToken']);

        $this->applySort($query, $filters['sort'] ?? null);

        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        $paginator->getCollection()->transform(function (EmployeeSession $session) use ($actor) {
            $session->setAttribute('_resolved_status', $this->statusResolver->resolve($session)->value);
            $session->setAttribute('_is_current', $this->isCurrentSession($session, $actor));

            return $session;
        });

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function stats(array $filters = []): array
    {
        $base = $this->filteredQuery($filters, applyStatus: false);
        $threshold = now()->subMinutes((int) config('employee_sessions.active_threshold_minutes', 5));
        $now = now();

        $total = (clone $base)->count();

        $revoked = (clone $base)->whereNotNull('employee_sessions.revoked_at')->count();

        $loggedOut = (clone $base)
            ->whereNull('employee_sessions.revoked_at')
            ->whereNotNull('employee_sessions.logged_out_at')
            ->count();

        $expired = (clone $base)
            ->whereNull('employee_sessions.revoked_at')
            ->whereNull('employee_sessions.logged_out_at')
            ->where(function (Builder $q) use ($now) {
                $q->where(function (Builder $inner) use ($now) {
                    $inner->whereNotNull('employee_sessions.expires_at')
                        ->where('employee_sessions.expires_at', '<=', $now);
                })->orWhereNull('employee_sessions.personal_access_token_id')
                    ->orWhereIn('employee_sessions.ended_reason', [
                        EmployeeSessionEndedReason::Expired->value,
                        EmployeeSessionEndedReason::CredentialMissing->value,
                    ]);
            })
            ->count();

        $openBase = (clone $base)
            ->whereNull('employee_sessions.revoked_at')
            ->whereNull('employee_sessions.logged_out_at')
            ->whereNotNull('employee_sessions.personal_access_token_id')
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('employee_sessions.expires_at')
                    ->orWhere('employee_sessions.expires_at', '>', $now);
            });

        $active = (clone $openBase)
            ->where('employee_sessions.last_seen_at', '>', $threshold)
            ->count();

        $idle = (clone $openBase)
            ->where(function (Builder $q) use ($threshold) {
                $q->whereNull('employee_sessions.last_seen_at')
                    ->orWhere('employee_sessions.last_seen_at', '<=', $threshold);
            })
            ->count();

        $activeEmployees = (int) (clone $openBase)
            ->toBase()
            ->cloneWithout(['columns', 'orders', 'groups', 'havings'])
            ->selectRaw('COUNT(DISTINCT employee_sessions.user_id) as aggregate')
            ->value('aggregate');

        $multi = (int) (clone $openBase)
            ->toBase()
            ->cloneWithout(['columns', 'orders', 'groups', 'havings'])
            ->select('employee_sessions.user_id')
            ->groupBy('employee_sessions.user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $todayStart = $this->clock->toUtc($this->clock->now()->startOfDay());
        $todayEnd = $this->clock->toUtc($this->clock->now()->endOfDay()->addSecond());

        $startedToday = (clone $base)
            ->where('employee_sessions.logged_in_at', '>=', $todayStart)
            ->where('employee_sessions.logged_in_at', '<', $todayEnd)
            ->count();

        $revokedToday = (clone $base)
            ->whereNotNull('employee_sessions.revoked_at')
            ->where('employee_sessions.revoked_at', '>=', $todayStart)
            ->where('employee_sessions.revoked_at', '<', $todayEnd)
            ->count();

        $mobile = (clone $base)->where('employee_sessions.device_type', 'mobile')->count();
        $desktop = (clone $base)->where('employee_sessions.device_type', 'desktop')->count();

        return [
            'total_sessions' => $total,
            'active_sessions' => $active,
            'idle_sessions' => $idle,
            'expired_sessions' => $expired,
            'logged_out_sessions' => $loggedOut,
            'revoked_sessions' => $revoked,
            'active_employees' => $activeEmployees,
            'employees_with_multiple_active_sessions' => $multi,
            'sessions_started_today' => $startedToday,
            'sessions_revoked_today' => $revokedToday,
            'mobile_sessions' => $mobile,
            'desktop_sessions' => $desktop,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $roles = Role::query()
            ->where('name', '!=', 'citizen')
            ->orderBy('display_name')
            ->get(['name', 'display_name'])
            ->map(fn (Role $r) => [
                'value' => $r->name,
                'label' => $r->display_name ?: $r->name,
            ])
            ->values()
            ->all();

        $perPage = [];
        foreach ([10, 20, 50, 100] as $n) {
            if ($n <= (int) config('employee_sessions.max_per_page', 100)) {
                $perPage[] = ['value' => $n, 'label' => (string) $n];
            }
        }

        return [
            'statuses' => EmployeeSessionStatus::options(),
            'device_types' => $this->deviceParser->deviceTypeOptions(),
            'operating_systems' => $this->deviceParser->operatingSystemOptions(),
            'browsers' => $this->deviceParser->browserOptions(),
            'roles' => $roles,
            'per_page' => $perPage,
            'sort_options' => [
                ['value' => 'last_seen_desc', 'label' => __('messages.employee_sessions.sort.last_seen_desc')],
                ['value' => 'logged_in_desc', 'label' => __('messages.employee_sessions.sort.logged_in_desc')],
                ['value' => 'logged_in_asc', 'label' => __('messages.employee_sessions.sort.logged_in_asc')],
            ],
        ];
    }

    public function findForManagement(string $sessionUuid): EmployeeSession
    {
        $session = EmployeeSession::query()
            ->with(['user.role', 'revokedByUser', 'personalAccessToken'])
            ->where('session_uuid', $sessionUuid)
            ->first();

        if ($session === null || $session->user === null || ! $session->user->isDashboardUser()) {
            throw new ApiException('messages.employee_sessions.not_found', 404);
        }

        return $session;
    }

    public function assertDashboardEmployee(User $employee): User
    {
        if ($employee->isCitizen() || ! $employee->isDashboardUser()) {
            throw new ApiException('messages.employee_sessions.invalid_employee', 404);
        }

        return $employee;
    }

    /**
     * @param  array{reason: string, password_confirmation?: string|null, confirm_current_session?: bool}  $data
     */
    public function revokeOne(EmployeeSession $session, User $actor, array $data, Request $request): EmployeeSession
    {
        return DB::transaction(function () use ($session, $actor, $data, $request) {
            /** @var EmployeeSession $locked */
            $locked = EmployeeSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load(['personalAccessToken', 'user.role']);

            if (! $this->statusResolver->isStillOpen($locked)) {
                throw new ApiException(
                    'messages.employee_sessions.already_ended',
                    409,
                    [],
                    [],
                    'session_already_ended',
                    ['session' => $this->presentSession($locked, $actor, detailed: true)]
                );
            }

            $isCurrent = $this->isCurrentSession($locked, $actor);
            if ($isCurrent) {
                if (! ($data['confirm_current_session'] ?? false)) {
                    throw new ApiException('messages.employee_sessions.current_session_confirmation_required', 422);
                }
                $this->assertPasswordConfirmation($actor, $data['password_confirmation'] ?? null);
            } else {
                // Sensitive administrative revoke still requires password confirmation.
                $this->assertPasswordConfirmation($actor, $data['password_confirmation'] ?? null);
            }

            $old = $this->lifecycleSnapshot($locked);
            $now = now();

            $locked->revoked_at = $now;
            $locked->revoked_by = $actor->id;
            $locked->revoke_reason = $data['reason'];
            $locked->ended_reason = EmployeeSessionEndedReason::Revoked->value;
            $locked->last_seen_at = $now;
            $locked->save();

            $tokenId = $locked->personal_access_token_id;
            if ($tokenId !== null) {
                PersonalAccessToken::query()->whereKey($tokenId)->delete();
            }
            $locked->personal_access_token_id = null;
            $locked->save();

            $this->audit->log(
                $actor,
                'employee_session.revoked',
                'employee_session',
                $locked->id,
                $old,
                array_merge($this->auditPayload($locked), [
                    'reason' => $data['reason'],
                    'was_current_session' => $isCurrent,
                ]),
                $request
            );

            return $locked->fresh(['user.role', 'revokedByUser', 'personalAccessToken']);
        });
    }

    /**
     * @param  array{reason: string, include_current_actor_session?: bool, password_confirmation?: string|null}  $data
     * @return array{targeted: int, revoked: int, already_ended: int, preserved_current: int, employee: User}
     */
    public function revokeAllForEmployee(User $employee, User $actor, array $data, Request $request): array
    {
        $this->assertDashboardEmployee($employee);

        $includeCurrent = (bool) ($data['include_current_actor_session'] ?? false);
        $targetingSelf = $actor->id === $employee->id;
        $targetIsRoot = $employee->isRootSuperAdmin();

        if ($targetingSelf || $targetIsRoot || $includeCurrent) {
            $this->assertPasswordConfirmation($actor, $data['password_confirmation'] ?? null);
        }

        return DB::transaction(function () use ($employee, $actor, $data, $request, $includeCurrent, $targetingSelf) {
            $sessions = EmployeeSession::query()
                ->where('user_id', $employee->id)
                ->lockForUpdate()
                ->with('personalAccessToken')
                ->orderBy('id')
                ->get();

            $targeted = 0;
            $revoked = 0;
            $alreadyEnded = 0;
            $preserved = 0;
            $currentTokenId = null;

            $current = $actor->currentAccessToken();
            if ($current instanceof PersonalAccessToken) {
                $currentTokenId = $current->id;
            }

            $now = now();

            foreach ($sessions as $session) {
                if (! $this->statusResolver->isStillOpen($session)) {
                    $alreadyEnded++;

                    continue;
                }

                $targeted++;
                $isCurrentActorSession = $targetingSelf
                    && $currentTokenId !== null
                    && (int) $session->personal_access_token_id === (int) $currentTokenId;

                if ($isCurrentActorSession && ! $includeCurrent) {
                    $preserved++;

                    continue;
                }

                $session->revoked_at = $now;
                $session->revoked_by = $actor->id;
                $session->revoke_reason = $data['reason'];
                $session->ended_reason = EmployeeSessionEndedReason::Revoked->value;
                $session->last_seen_at = $now;
                $session->save();

                $tokenId = $session->personal_access_token_id;
                if ($tokenId !== null) {
                    PersonalAccessToken::query()->whereKey($tokenId)->delete();
                }
                $session->personal_access_token_id = null;
                $session->save();
                $revoked++;
            }

            $this->audit->log(
                $actor,
                'employee_sessions.revoked_all',
                'user',
                $employee->id,
                null,
                [
                    'target_employee_id' => $employee->id,
                    'target_employee_name' => $employee->name,
                    'reason' => $data['reason'],
                    'targeted_session_count' => $targeted,
                    'revoked_session_count' => $revoked,
                    'already_ended_count' => $alreadyEnded,
                    'preserved_current_session_count' => $preserved,
                    'include_current_actor_session' => $includeCurrent,
                ],
                $request
            );

            return [
                'targeted' => $targeted,
                'revoked' => $revoked,
                'already_ended' => $alreadyEnded,
                'preserved_current' => $preserved,
                'employee' => $employee,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForEmployee(User $employee, array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $filters['employee_id'] = $employee->id;

        return $this->paginate($filters, $actor);
    }

    /**
     * @return LengthAwarePaginator<int, \App\Models\AuditLog>
     */
    public function auditLogsForSession(EmployeeSession $session, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max(1, $perPage), (int) config('employee_sessions.max_per_page', 100));

        return \App\Models\AuditLog::query()
            ->with('user')
            ->where('entity_type', 'employee_session')
            ->where('entity_id', $session->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function isCurrentSession(EmployeeSession $session, ?User $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        $token = $actor->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return (int) $session->personal_access_token_id === (int) $token->id
            && (int) $session->user_id === (int) $actor->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSession(EmployeeSession $session, ?User $actor, bool $detailed = false): array
    {
        $session->loadMissing(['user.role', 'revokedByUser', 'personalAccessToken']);
        $status = $this->statusResolver->resolve($session);
        $isCurrent = $this->isCurrentSession($session, $actor);
        $open = $this->statusResolver->isStillOpen($session);

        $lastSeen = $session->last_seen_at;
        $ageSeconds = $session->logged_in_at
            ? max(0, $session->logged_in_at->diffInSeconds(now()))
            : null;

        $payload = [
            'id' => $session->session_uuid,
            'employee' => $session->user ? [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'email' => $session->user->email,
                'user_type' => $session->user->user_type?->value,
                'is_active' => (bool) $session->user->is_active,
            ] : null,
            'role' => $session->user?->role ? [
                'name' => $session->user->role->name,
                'display_name' => $session->user->role->display_name,
            ] : null,
            'logged_in_at' => $session->logged_in_at?->toIso8601String(),
            'last_seen_at' => $lastSeen?->toIso8601String(),
            'last_seen_human' => $lastSeen?->diffForHumans(),
            'logged_out_at' => $session->logged_out_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'revoked_at' => $session->revoked_at?->toIso8601String(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'initial_ip_address' => $session->initial_ip_address,
            'last_ip_address' => $session->last_ip_address,
            'device_type' => $session->device_type,
            'device_type_label' => $this->deviceParser->deviceTypeLabel($session->device_type ?? 'unknown'),
            'operating_system' => $session->operating_system,
            'browser' => $session->browser,
            'is_current_session' => $isCurrent,
            'session_age_seconds' => $ageSeconds,
            'session_age_minutes' => $ageSeconds !== null ? (int) floor($ageSeconds / 60) : null,
            'actions' => [
                'can_view' => true,
                'can_revoke' => $open,
                'can_revoke_all_for_employee' => $session->user !== null,
                'requires_current_session_confirmation' => $isCurrent && $open,
            ],
        ];

        if ($detailed) {
            $payload['browser_version'] = $session->browser_version;
            $payload['user_agent'] = $session->user_agent;
            $payload['auth_driver'] = $session->auth_driver;
            $payload['ended_reason'] = $session->ended_reason;
            $payload['revoke_reason'] = $session->revoke_reason;
            $payload['revoked_by'] = $session->revokedByUser ? [
                'id' => $session->revokedByUser->id,
                'name' => $session->revokedByUser->name,
                'email' => $session->revokedByUser->email,
            ] : null;
            $payload['lifecycle'] = $this->lifecycleTimeline($session);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters, bool $applyStatus = true): Builder
    {
        $query = EmployeeSession::query()
            ->select('employee_sessions.*')
            ->join('users', 'users.id', '=', 'employee_sessions.user_id')
            ->whereIn('users.user_type', [UserType::Admin->value, UserType::Employee->value])
            ->whereNull('users.deleted_at');

        if (! empty($filters['employee_id'])) {
            $query->where('employee_sessions.user_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['role'])) {
            $query->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('roles.name', (string) $filters['role']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('users.phone', 'like', $term)
                    ->orWhere('employee_sessions.initial_ip_address', 'like', $term)
                    ->orWhere('employee_sessions.last_ip_address', 'like', $term)
                    ->orWhere('employee_sessions.session_uuid', 'like', $term);
            });
        }

        if (! empty($filters['device_type'])) {
            $query->where('employee_sessions.device_type', (string) $filters['device_type']);
        }

        if (! empty($filters['operating_system'])) {
            $query->where('employee_sessions.operating_system', (string) $filters['operating_system']);
        }

        if (! empty($filters['browser'])) {
            $query->where('employee_sessions.browser', (string) $filters['browser']);
        }

        if (! empty($filters['ip_address'])) {
            $ip = (string) $filters['ip_address'];
            $query->where(function (Builder $q) use ($ip) {
                $q->where('employee_sessions.initial_ip_address', $ip)
                    ->orWhere('employee_sessions.last_ip_address', $ip);
            });
        }

        foreach (['logged_in_from' => '>=', 'logged_in_to' => '<='] as $key => $op) {
            if (! empty($filters[$key])) {
                $query->where('employee_sessions.logged_in_at', $op, $filters[$key]);
            }
        }

        foreach (['last_seen_from' => '>=', 'last_seen_to' => '<='] as $key => $op) {
            if (! empty($filters[$key])) {
                $query->where('employee_sessions.last_seen_at', $op, $filters[$key]);
            }
        }

        if ($applyStatus && ! empty($filters['status'])) {
            $this->applyStatusFilter($query, (string) $filters['status']);
        }

        return $query;
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $threshold = now()->subMinutes((int) config('employee_sessions.active_threshold_minutes', 5));
        $now = now();

        match ($status) {
            EmployeeSessionStatus::Revoked->value => $query->whereNotNull('employee_sessions.revoked_at'),
            EmployeeSessionStatus::LoggedOut->value => $query
                ->whereNull('employee_sessions.revoked_at')
                ->whereNotNull('employee_sessions.logged_out_at'),
            EmployeeSessionStatus::Expired->value => $query
                ->whereNull('employee_sessions.revoked_at')
                ->whereNull('employee_sessions.logged_out_at')
                ->where(function (Builder $q) use ($now) {
                    $q->where(function (Builder $inner) use ($now) {
                        $inner->whereNotNull('employee_sessions.expires_at')
                            ->where('employee_sessions.expires_at', '<=', $now);
                    })->orWhereNull('employee_sessions.personal_access_token_id')
                        ->orWhereIn('employee_sessions.ended_reason', [
                            EmployeeSessionEndedReason::Expired->value,
                            EmployeeSessionEndedReason::CredentialMissing->value,
                        ]);
                }),
            EmployeeSessionStatus::Active->value => $query
                ->whereNull('employee_sessions.revoked_at')
                ->whereNull('employee_sessions.logged_out_at')
                ->whereNotNull('employee_sessions.personal_access_token_id')
                ->where(function (Builder $q) use ($now) {
                    $q->whereNull('employee_sessions.expires_at')
                        ->orWhere('employee_sessions.expires_at', '>', $now);
                })
                ->where('employee_sessions.last_seen_at', '>', $threshold),
            EmployeeSessionStatus::Idle->value => $query
                ->whereNull('employee_sessions.revoked_at')
                ->whereNull('employee_sessions.logged_out_at')
                ->whereNotNull('employee_sessions.personal_access_token_id')
                ->where(function (Builder $q) use ($now) {
                    $q->whereNull('employee_sessions.expires_at')
                        ->orWhere('employee_sessions.expires_at', '>', $now);
                })
                ->where(function (Builder $q) use ($threshold) {
                    $q->whereNull('employee_sessions.last_seen_at')
                        ->orWhere('employee_sessions.last_seen_at', '<=', $threshold);
                }),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $sort
     */
    private function applySort(Builder $query, mixed $sort): void
    {
        $sort = is_string($sort) ? $sort : 'last_seen_desc';

        match ($sort) {
            'logged_in_desc' => $query->orderByDesc('employee_sessions.logged_in_at')->orderByDesc('employee_sessions.id'),
            'logged_in_asc' => $query->orderBy('employee_sessions.logged_in_at')->orderBy('employee_sessions.id'),
            default => $query
                ->orderByDesc('employee_sessions.last_seen_at')
                ->orderByDesc('employee_sessions.logged_in_at')
                ->orderByDesc('employee_sessions.id'),
        };
    }

    private function assertPasswordConfirmation(User $actor, mixed $password): void
    {
        if (! is_string($password) || $password === '' || ! Hash::check($password, $actor->password)) {
            throw new ApiException('messages.employee_sessions.password_confirmation_failed', 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycleSnapshot(EmployeeSession $session): array
    {
        return [
            'logged_out_at' => $session->logged_out_at?->toIso8601String(),
            'revoked_at' => $session->revoked_at?->toIso8601String(),
            'ended_reason' => $session->ended_reason,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(EmployeeSession $session): array
    {
        return [
            'session_uuid' => $session->session_uuid,
            'user_id' => $session->user_id,
            'logged_in_at' => $session->logged_in_at?->toIso8601String(),
            'device_type' => $session->device_type,
            'operating_system' => $session->operating_system,
            'browser' => $session->browser,
            'initial_ip_address' => $session->initial_ip_address,
            'last_ip_address' => $session->last_ip_address,
            'ended_reason' => $session->ended_reason,
            'revoke_reason' => $session->revoke_reason,
        ];
    }

    /**
     * @return list<array{event: string, at: string|null}>
     */
    private function lifecycleTimeline(EmployeeSession $session): array
    {
        $events = [
            ['event' => 'logged_in', 'at' => $session->logged_in_at?->toIso8601String()],
        ];

        if ($session->last_seen_at) {
            $events[] = ['event' => 'last_seen', 'at' => $session->last_seen_at->toIso8601String()];
        }
        if ($session->logged_out_at) {
            $events[] = ['event' => 'logged_out', 'at' => $session->logged_out_at->toIso8601String()];
        }
        if ($session->revoked_at) {
            $events[] = ['event' => 'revoked', 'at' => $session->revoked_at->toIso8601String()];
        }
        if ($session->expires_at) {
            $events[] = ['event' => 'expires', 'at' => $session->expires_at->toIso8601String()];
        }

        return $events;
    }
}
