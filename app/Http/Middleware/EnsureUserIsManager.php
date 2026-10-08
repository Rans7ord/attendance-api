<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows admin, super_admin and manager through. Used for company-wide,
 * read-only areas such as reports.
 */
class EnsureUserIsManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->user()?->role, ['admin', 'super_admin', 'manager'], true)) {
            return response()->json(['message' => 'Forbidden — manager access required.'], 403);
        }

        return $next($request);
    }
}