<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            $user->tokens()->delete();

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_USER_SUSPENDED',
                'message' => 'Tu cuenta ha sido suspendida. Todos tus tokens han sido revocados.',
                'errors' => [],
                'metadata' => null,
            ], 403);
        }

        return $next($request);
    }
}
