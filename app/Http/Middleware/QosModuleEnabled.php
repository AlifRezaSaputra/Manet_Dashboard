<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class QosModuleEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('qos.enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'Not found'
            ], 404);
        }

        return $next($request);
    }
}