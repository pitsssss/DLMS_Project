<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'citizen' => \App\Http\Middleware\EnsureCitizen::class,
            'dashboard' => \App\Http\Middleware\EnsureDashboardUser::class,
            'profile.approved' => \App\Http\Middleware\EnsureProfileApproved::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'root_super_admin' => \App\Http\Middleware\EnsureRootSuperAdmin::class,
            'employee.session.track' => \App\Http\Middleware\TrackEmployeeSessionActivity::class,
            'dev.dashboard' => \App\Http\Middleware\EnsureDevDashboardAllowed::class,
            'locale' => \App\Http\Middleware\ResolveRequestLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('validation.failed'),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('messages.http.unauthenticated'),
                'errors' => (object) [],
            ], 401);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: __('messages.http.forbidden'),
                'errors' => (object) [],
            ], 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => __('messages.http.not_found'),
                'errors' => (object) [],
            ], 404);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (
                $e instanceof ApiException
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AccessDeniedHttpException
                || $e instanceof NotFoundHttpException
            ) {
                return null;
            }

            if (config('app.debug')) {
                return null;
            }

            report($e);

            return response()->json([
                'success' => false,
                'message' => __('messages.http.server_error'),
                'errors' => (object) [],
            ], 500);
        });
    })->create();
