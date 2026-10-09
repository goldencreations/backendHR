<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to hr_admin only, for settings and role changes.
 */
class EnsureUserIsHrAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isHrAdmin()) {
            return response()->json([
                'message' => 'This action requires an HR administrator.',
            ], 403);
        }

        return $next($request);
    }
}
