<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows admin, super_admin, manager, and supervisor through. Used for routes both
 * tiers can reach (e.g. listing members/branches) — the controller itself
 * is responsible for narrowing a supervisor's results to their own branch
 * (managers, like admins, see every branch).
 * Anything admin-only (creating/editing branches, company settings,
 * invites, reports) stays behind EnsureUserIsAdmin instead.
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->user()?->role, ['admin', 'super_admin', 'manager', 'supervisor'], true)) {
            return response()->json(['message' => 'Forbidden — staff access required.'], 403);
        }

        return $next($request);
    }
}