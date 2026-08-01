<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the true protected root Super Admin role (super_admin only).
 * Does not grant access for the editable "admin" role or permission-based bypass.
 */
class EnsureRootSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isRootSuperAdmin()) {
            abort(403, __('messages.employee_sessions.root_super_admin_required'));
        }

        return $next($request);
    }
}
