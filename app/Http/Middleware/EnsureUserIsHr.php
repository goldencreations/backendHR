<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to HR staff (hr_admin and hr_officer).
 */
class EnsureUserIsHr
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isHr()) {
            return response()->json([
                'message' => 'This action is restricted to HR staff.',
            ], 403);
        }

        return $next($request);
    }
}
