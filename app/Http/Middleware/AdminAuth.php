<?php

namespace App\Http\Middleware;

use App\Support\AdminToken;
use Closure;
use Illuminate\Http\Request;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!AdminToken::valid($request->bearerToken())) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        return $next($request);
    }
}