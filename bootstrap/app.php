<?php

use App\Http\Middleware\EnsureUserIsHr;
use App\Http\Middleware\EnsureUserIsHrAdmin;
use Illuminate\Auth\AuthenticationException;
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

        // Laravel's default redirect-to-login assumes a web app. There is no
        // login page here, so an unauthenticated API call would throw
        // RouteNotFoundException (a 500) instead of the 401 a client needs.
        $middleware->redirectGuestsTo(fn () => null);

        // The container sits behind Cloudflare and the host reverse proxy, so
        // X-Forwarded-* is the only record of the real scheme and client.
        // Without this, generated URLs come back as http://.
        //
        // Scoped to loopback and the Docker bridge rather than '*': the
        // container's port is not published publicly, and its only legitimate
        // client is the host proxy on that network. Trusting every address
        // would let any caller forge X-Forwarded-For.
        $middleware->trustProxies(
            at: ['127.0.0.1', '172.16.0.0/12'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients expect JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
