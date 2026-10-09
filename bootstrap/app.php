<?php

use App\Http\Middleware\EnsureUserIsHr;
use App\Http\Middleware\EnsureUserIsHrAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'hr' => EnsureUserIsHr::class,
            'hr.admin' => EnsureUserIsHrAdmin::class,
        ]);

        // The API is stateless and token authenticated, so the session and
        // CSRF defaults for web routes must not apply to it.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients expect JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
