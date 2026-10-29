<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Staff-only area: active user with a staff role. Ability checks happen per route via `can:`. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if (! $u || ! $u->is_active || ! $u->isStaff()) {
            return response()->json(['message' => 'Staff access only'], 403);
        }

        return $next($request);
    }
}
