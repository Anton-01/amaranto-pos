<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_UNAUTHENTICATED',
                'message' => 'No autenticado.',
                'errors' => [],
                'metadata' => null,
            ], 401);
        }

        $user->loadMissing('roles');
        $userRoles = $user->roles->pluck('name')->toArray();

        foreach ($roles as $role) {
            if (in_array($role, $userRoles)) {
                return $next($request);
            }
        }

        return response()->json([
            'status' => 'error',
            'code' => 'ERR_AUTH_FORBIDDEN_ROLE',
            'message' => 'No tienes permisos para realizar esta accion.',
            'errors' => [],
            'metadata' => [
                'required_roles' => $roles,
            ],
        ], 403);
    }
}
