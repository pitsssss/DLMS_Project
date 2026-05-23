<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsureDevDashboardAllowed
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment(['local', 'staging', 'testing']), 404);

        // Keep generated URLs on the same host/port as the current request (e.g. 127.0.0.1:8000).
        URL::forceRootUrl($request->getSchemeAndHttpHost());

        return $next($request);
    }
}
