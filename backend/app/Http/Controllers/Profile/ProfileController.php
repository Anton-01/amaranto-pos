<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\UserSessionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'phone_country_code' => $user->phone_country_code,
                'avatar_url' => $user->avatar_url,
                'roles' => $user->roles->pluck('name'),
                'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'phone' => 'nullable|string|max:20',
            'phone_country_code' => 'nullable|string|max:5',
            'avatar_url' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $user->update($request->only(['name', 'phone', 'phone_country_code', 'avatar_url']));

        return response()->json([
            'status' => 'success',
            'data' => $user->fresh(),
            'metadata' => ['message' => 'Perfil actualizado correctamente.'],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|max:255|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La contraseña actual es incorrecta.',
                'code' => 'ERR_PROFILE_WRONG_PASSWORD',
            ], 422);
        }

        $user->update([
            'password' => $request->password,
            'password_restored_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Contraseña actualizada correctamente.'],
        ]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = UserSessionLog::where('user_id', $request->user()->id)
            ->orderByDesc('login_at')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $sessions,
        ]);
    }

    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        $session = UserSessionLog::where('id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->whereNull('logout_at')
            ->first();

        if (!$session) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sesión no encontrada o ya cerrada.',
            ], 404);
        }

        $session->update(['logout_at' => now()]);

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Sesión revocada correctamente.'],
        ]);
    }
}
