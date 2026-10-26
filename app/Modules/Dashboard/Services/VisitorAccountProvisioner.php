<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Dashboard\Support\PermissionRegistry;
use App\Modules\Dashboard\Support\VisitorAccess;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Idempotent provisioning for the single public visitor account.
 * Never overwrites a user who does not already hold the visitor role.
 */
class VisitorAccountProvisioner
{
    public function __construct(
        private readonly RbacBootstrapService $rbac,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function provision(bool $apply = true): array
    {
        if (! VisitorAccess::enabled()) {
            return $this->result(false, 'disabled', false, 'DEMO_VISITOR_ENABLED is false. No visitor account was written.');
        }

        $email = strtolower(trim((string) config('demo.visitor.email')));
        $password = (string) config('demo.visitor.password');
        $name = trim((string) config('demo.visitor.name'));
        $phone = trim((string) config('demo.visitor.phone'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->result(false, 'invalid_email', false, 'DEMO_VISITOR_EMAIL is not a valid email address.');
        }

        if ($password === '' || strlen($password) < 8) {
            return $this->result(false, 'invalid_password', false, 'DEMO_VISITOR_PASSWORD must be at least 8 characters.');
        }

        if ($phone === '') {
            return $this->result(false, 'invalid_phone', false, 'DEMO_VISITOR_PHONE is empty.');
        }

        $byEmail = User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($byEmail !== null && ! $byEmail->isVisitor()) {
            return $this->result(
                false,
                'email_collision',
                false,
                'Refusing to provision: that email already belongs to a non-visitor account.'
            );
        }

        $visitorRoleId = Role::query()->where('name', VisitorAccess::ROLE)->value('id');
        $existingVisitors = $visitorRoleId === null
            ? collect()
            : User::withTrashed()->where('role_id', $visitorRoleId)->get();

        if ($visitorRoleId !== null && $existingVisitors->count() > 1) {
            return $this->result(false, 'duplicate_visitors', false, 'Refusing to provision: more than one visitor account already exists.');
        }

        $existingVisitor = $existingVisitors->first();
        if ($existingVisitor !== null && strtolower((string) $existingVisitor->email) !== $email) {
            return $this->result(
                false,
                'visitor_email_mismatch',
                false,
                'A visitor account already exists with a different email. Refusing to create a duplicate or retarget it.'
            );
        }

        if ($byEmail === null && $existingVisitors->isEmpty()) {
            $phoneOwner = User::withTrashed()->where('phone', $phone)->first();
            if ($phoneOwner !== null) {
                return $this->result(
                    false,
                    'phone_collision',
                    false,
                    'Refusing to provision: DEMO_VISITOR_PHONE is already used by another account.'
                );
            }
        }

        if (! $apply) {
            return $this->result(true, $byEmail === null ? 'would_create' : 'would_update', false, 'Dry run only. No records were written.');
        }

        try {
            $summary = DB::transaction(function () use ($email, $password, $name, $phone, $byEmail): array {
                $this->rbac->bootstrap(true);
                $role = $this->syncVisitorRole();
                $user = $byEmail ?? User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->first();
                $created = $user === null;

                if ($user === null) {
                    $user = new User([
                        'name' => $name !== '' ? $name : 'زائر تجريبي',
                        'email' => $email,
                        'phone' => $phone,
                        'national_id' => null,
                    ]);
                }

                if ($user->trashed()) {
                    $user->restore();
                }

                $user->role_id = $role->id;
                $user->user_type = UserType::Employee;
                $user->is_active = true;
                if ($created) {
                    $user->profile_completed = true;
                    $user->profile_status = ProfileStatus::Approved;
                    $user->email_verified_at = now();
                }

                if ($created || ! Hash::check($password, (string) $user->password)) {
                    $user->password = $password;
                }

                $user->save();
                $user->directPermissions()->detach();

                $this->auditLogs->log(
                    null,
                    $created ? 'visitor.account_created' : 'visitor.account_updated',
                    'user',
                    $user->id,
                    null,
                    [
                        'email' => $user->email,
                        'role' => VisitorAccess::ROLE,
                    ]
                );

                return [
                    'created' => $created,
                    'user_id' => $user->id,
                    'email' => $user->email,
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return $this->result(false, 'failed', false, 'Visitor provisioning failed without changing unrelated accounts.');
        }

        return $this->result(
            true,
            $summary['created'] ? 'created' : 'updated',
            true,
            $summary['created']
                ? 'Visitor account created.'
                : 'Visitor account updated.',
            [
                'user_id' => $summary['user_id'],
                'email' => $summary['email'],
                'role' => VisitorAccess::ROLE,
            ]
        );
    }

    private function syncVisitorRole(): Role
    {
        $definition = PermissionRegistry::systemRole(VisitorAccess::ROLE);
        $role = Role::query()->firstOrCreate(
            ['name' => VisitorAccess::ROLE],
            [
                'display_name' => $definition['label'] ?? 'زائر تجريبي',
                'description' => $definition['description'] ?? null,
                'is_system' => true,
                'is_protected' => true,
                'is_assignable' => false,
                'is_archived' => false,
                'version' => 1,
            ]
        );

        $role->forceFill([
            'is_system' => true,
            'is_protected' => true,
            'is_assignable' => false,
            'is_archived' => false,
        ])->save();

        $names = VisitorAccess::permissionNames();
        foreach ($names as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }

        $ids = Permission::query()->whereIn('name', $names)->pluck('id');
        $role->permissions()->sync($ids);

        return $role;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function result(bool $ok, string $status, bool $written, string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => $ok,
            'status' => $status,
            'written' => $written,
            'message' => $message,
            'role' => VisitorAccess::ROLE,
        ], $extra);
    }
}
