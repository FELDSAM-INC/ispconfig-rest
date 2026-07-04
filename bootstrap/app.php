<?php

use App\Http\Middleware\ApiKeyAuth;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireAdminOrReseller;
use App\Http\Middleware\RequireClientLimit;
use App\Support\Problem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key' => ApiKeyAuth::class,
            'scope.admin' => RequireAdmin::class,
            'scope.reseller' => RequireAdminOrReseller::class,
            'scope.limit' => RequireClientLimit::class,
        ]);

        // Auth must run before route-model binding: otherwise a missing id
        // 404s pre-auth, leaking resource (non)existence to unauthenticated
        // callers while an existing id correctly 401s. The module/limit
        // gates (spec 011) sit between the two: a gated request is denied
        // with 403 before any binding query runs.
        $middleware->priority([
            ApiKeyAuth::class,
            RequireAdmin::class,
            RequireAdminOrReseller::class,
            RequireClientLimit::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return Problem::fromThrowable($e);
            }

            return null;
        });
    })->create();
