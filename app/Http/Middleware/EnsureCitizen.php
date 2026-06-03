<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCitizen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCitizen()) {
            return response()->json([
                'success' => false,
                'message' => __('messages.middleware.citizen_only'),
                'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
