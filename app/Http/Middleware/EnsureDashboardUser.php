<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny(__('messages.http.unauthenticated'), 401);
        }

        if ($user->isCitizen() || ! $user->isDashboardUser()) {
            return $this->deny(__('messages.dashboard.citizen_not_allowed'));
        }

        if (! $user->is_active) {
            return $this->deny(__('messages.dashboard.inactive_account'));
        }

        if (! $user->isSuperAdmin() && ! $user->hasPermission('access_dashboard')) {
            return $this->deny(__('messages.dashboard.forbidden'));
        }

        return $next($request);
    }

    private function deny(string $message, int $status = 403): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) [],
        ], $status);
    }
}
