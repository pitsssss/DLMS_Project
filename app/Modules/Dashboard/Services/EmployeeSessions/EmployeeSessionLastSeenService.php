<?php

namespace App\Modules\Dashboard\Services\EmployeeSessions;

use App\Models\EmployeeSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class EmployeeSessionLastSeenService
{
    public function __construct(
        private readonly EmployeeSessionDeviceParser $deviceParser,
        private readonly EmployeeSessionStatusResolver $statusResolver,
    ) {}

    public function resolveCurrentSession(User $user, Request $request): ?EmployeeSession
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return EmployeeSession::query()
            ->where('user_id', $user->id)
            ->where('personal_access_token_id', $token->id)
            ->first();
    }

    /**
     * Throttled last-seen / IP update for the authenticated Dashboard session.
     *
     * @return array{session: EmployeeSession|null, updated: bool}
     */
    public function touchCurrentSession(User $user, Request $request, bool $force = false): array
    {
        $session = $this->resolveCurrentSession($user, $request);
        if ($session === null) {
            return ['session' => null, 'updated' => false];
        }

        if (! $this->statusResolver->isStillOpen($session)) {
            return ['session' => $session, 'updated' => false];
        }

        $intervalMinutes = max(1, (int) config('employee_sessions.last_seen_write_interval_minutes', 3));
        $cacheKey = 'employee_session:last_seen:'.$session->id;

        if (! $force && Cache::has($cacheKey)) {
            return ['session' => $session, 'updated' => false];
        }

        $ip = $request->ip();
        $ua = $request->userAgent();
        $dirty = false;

        $session->last_seen_at = now();
        $dirty = true;

        if ($ip !== null && $ip !== '' && $session->last_ip_address !== $ip) {
            $session->last_ip_address = $ip;
        }

        if ($ua !== null && $ua !== '' && ($session->user_agent === null || $session->user_agent === '')) {
            $session->user_agent = $ua;
            $meta = $this->deviceParser->parse($ua);
            $session->device_type = $meta['device_type'];
            $session->operating_system = $meta['operating_system'];
            $session->browser = $meta['browser'];
            $session->browser_version = $meta['browser_version'];
        }

        if ($dirty) {
            $session->save();
            Cache::put($cacheKey, true, now()->addMinutes($intervalMinutes));
        }

        return ['session' => $session->fresh(), 'updated' => $dirty];
    }
}
