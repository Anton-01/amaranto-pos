<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSessionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FALaravel\Google2FA;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_INVALID_CREDENTIALS',
                'message' => 'Credenciales incorrectas.',
                'errors' => [],
                'metadata' => null,
            ], 401);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_USER_SUSPENDED',
                'message' => 'Tu cuenta ha sido suspendida. Contacta al administrador.',
                'errors' => [],
                'metadata' => null,
            ], 403);
        }

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $tempToken = $user->createToken('2fa-challenge', ['2fa-challenge'], now()->addMinutes(5));

            return response()->json([
                'status' => '2FA_CHALLENGE',
                'code' => 'AUTH_2FA_REQUIRED',
                'message' => 'Se requiere verificación de dos factores.',
                'errors' => [],
                'metadata' => [
                    'temp_token' => $tempToken->plainTextToken,
                ],
            ], 200);
        }

        return $this->issueFullSession($user, $request);
    }

    public function verify2fa(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (! $user->tokenCan('2fa-challenge')) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_INVALID_TOKEN',
                'message' => 'Token inválido para verificación 2FA.',
                'errors' => [],
                'metadata' => null,
            ], 403);
        }

        $google2fa = app(Google2FA::class);
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (! $valid) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_INVALID_2FA_CODE',
                'message' => 'El código de verificación es incorrecto.',
                'errors' => [],
                'metadata' => null,
            ], 401);
        }

        $user->currentAccessToken()->delete();

        return $this->issueFullSession($user, $request);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessionLog = UserSessionLog::where('user_id', $user->id)
            ->whereNull('logout_at')
            ->latest('login_at')
            ->first();

        if ($sessionLog) {
            $sessionLog->update(['logout_at' => now()]);
        }

        $user->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'code' => 'AUTH_LOGOUT_SUCCESS',
            'message' => 'Sesión cerrada correctamente.',
            'errors' => [],
            'metadata' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $user->roles->pluck('name'),
                'has_2fa' => (bool) $user->two_factor_confirmed_at,
            ],
        ]);
    }

    private function issueFullSession(User $user, Request $request): JsonResponse
    {
        $token = $user->createToken('pos-session', ['*']);

        UserSessionLog::create([
            'user_id' => $user->id,
            'login_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() ?? 'unknown',
        ]);

        $user->load('roles');

        return response()->json([
            'status' => 'success',
            'code' => 'AUTH_LOGIN_SUCCESS',
            'message' => 'Inicio de sesión exitoso.',
            'data' => [
                'token' => $token->plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'roles' => $user->roles->pluck('name'),
                    'has_2fa' => (bool) $user->two_factor_confirmed_at,
                ],
            ],
        ]);
    }
}
