<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the project's authoritative Super Admin bypass check (super_admin OR admin role).
 * Do not replace with assignable permissions such as manage_roles.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSuperAdmin()) {
            abort(403, __('messages.access_control.super_admin_required'));
        }

        return $next($request);
    }
}
