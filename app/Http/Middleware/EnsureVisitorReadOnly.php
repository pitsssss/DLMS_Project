<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Dashboard\Support\VisitorAccess;
use App\Modules\Dashboard\Support\VisitorResponseRedactor;
use App\Support\RequestLocaleResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side read-only gate for the public visitor role.
 * Runs for every API request that presents a visitor token, including routes
 * outside the dashboard UI.
 */
class EnsureVisitorReadOnly
{
    /**
     * Authentication lifecycle calls that do not mutate business data.
     *
     * @var list<string>
     */
    private const ALLOWED_POST_PATHS = [
        'api/dashboard/auth/login',
        'api/dashboard/auth/logout',
        'api/auth/login',
        'api/auth/logout',
    ];

    /**
     * Responses that describe the visitor account itself.
     *
     * @var list<string>
     */
    private const SELF_PATHS = [
        'api/dashboard/auth/login',
        'api/dashboard/auth/me',
        'api/dashboard/auth/logout',
        'api/auth/login',
        'api/auth/me',
        'api/auth/logout',
    ];

    public function __construct(
        private readonly RequestLocaleResolver $locales,
        private readonly VisitorResponseRedactor $redactor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken() && $request->cookies->count() === 0) {
            return $next($request);
        }

        $user = $this->visitorFromRequest($request);
        if (! $user instanceof User) {
            return $next($request);
        }

        if (! VisitorAccess::enabled() && ! $this->pathIs($request, self::ALLOWED_POST_PATHS)) {
            return $this->deny($request, 'messages.visitor.unavailable');
        }

        if (! $this->isAllowed($request)) {
            return $this->deny($request, 'messages.visitor.read_only');
        }

        $this->stripIdentityFilters($request);

        $response = $next($request);

        if ($response instanceof JsonResponse && ! $this->isSelfPath($request)) {
            $this->withLocale($request, function () use ($response): void {
                $data = $response->getData(true);
                if (is_array($data)) {
                    $response->setData($this->redactor->redact($data));
                }
            });
        }

        return $response;
    }

    private function visitorFromRequest(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            $token = PersonalAccessToken::findToken($bearer);
            $user = $token?->tokenable;
        } else {
            $user = $request->user('sanctum');
        }

        if (! $user instanceof User) {
            return null;
        }

        $user->loadMissing('role');

        return $user->isVisitor() ? $user : null;
    }

    private function isAllowed(Request $request): bool
    {
        $method = strtoupper($request->getMethod());

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return ! $this->isSensitiveRead($request);
        }

        if ($method === 'POST' && $this->pathIs($request, self::ALLOWED_POST_PATHS)) {
            return true;
        }

        return false;
    }

    private function isSensitiveRead(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        $patterns = [
            '#/audit-logs(?:/|$)#',
            '#/portrait(?:/|$)#',
            '#/preview(?:/|$)#',
            '#/print(?:/|$)#',
            '#/download(?:/|$)#',
            '#/employee-sessions(?:/|$)#',
            '#/access-control(?:/|$)#',
            '#/documents/\d+/(?:approve|reject|preview|download)(?:/|$)#',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     */
    private function pathIs(Request $request, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    private function isSelfPath(Request $request): bool
    {
        return $this->pathIs($request, self::SELF_PATHS);
    }

    private function stripIdentityFilters(Request $request): void
    {
        $query = $request->query->all();
        foreach ([
            'national_id',
            'phone',
            'email',
            'name',
            'mobile',
            'address',
            'citizen_name',
            'citizen_id',
            'user_id',
        ] as $key) {
            unset($query[$key]);
        }

        foreach (['search', 'q'] as $key) {
            if (! isset($query[$key]) || ! is_string($query[$key])) {
                continue;
            }

            $value = trim($query[$key]);
            if ($value === '' || preg_match('/^(APP|LIC|PAY|FINE)-[A-Za-z0-9\-]{4,}$/', $value) !== 1) {
                unset($query[$key]);
            }
        }

        $request->query->replace($query);
    }

    private function deny(Request $request, string $messageKey): JsonResponse
    {
        $message = $this->withLocale($request, fn (): string => __($messageKey));

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) [],
        ], 403);
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLocale(Request $request, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($this->locales->resolve($request));

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
