<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!in_array($request->user()?->role, ['admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Forbidden — admin access required.'], 403);
        }

        return $next($request);
    }
}
