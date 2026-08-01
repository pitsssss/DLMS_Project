<?php

namespace App\Http\Middleware;

use App\Modules\Dashboard\Services\EmployeeSessions\EmployeeSessionLastSeenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttled last-seen tracking for authenticated Dashboard employees only.
 */
class TrackEmployeeSessionActivity
{
    public function __construct(
        private readonly EmployeeSessionLastSeenService $lastSeen,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user === null || ! $user->isDashboardUser() || $user->isCitizen()) {
            return $response;
        }

        try {
            $this->lastSeen->touchCurrentSession($user, $request);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }
}
