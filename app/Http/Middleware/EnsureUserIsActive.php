<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every authenticated request from a deactivated account, so an
 * already-issued token stops working the moment an admin sets the member
 * to inactive (tokens are also revoked at that point, this is the backstop).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            return response()->json([
                'message' => 'This account has been deactivated. Contact your admin.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}