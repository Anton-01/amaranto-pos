<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSessionLog;
use App\Services\Security\TurnstileVerifier;
use App\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FALaravel\Google2FA;

class AuthController extends Controller
{
    public function __construct(private readonly TurnstileVerifier $turnstile) {}

    /**
     * Puerta de entrada del POS. Orden de las capas de defensa — deliberado,
     * de la mas barata a la mas cara:
     *
     *   1. Candado de fuerza bruta (Redis, sin I/O de red ni de base de datos).
     *   2. Escudo anti-bots Turnstile (una llamada HTTP a Cloudflare).
     *   3. Verificacion de credenciales contra PostgreSQL + bcrypt.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'cf_turnstile_token' => 'nullable|string|max:4096',
        ]);

        if ($seconds = LoginThrottle::lockedSeconds($request)) {
            return $this->throttledResponse($request, $seconds);
        }

        if ($captcha = $this->rejectedByTurnstile($request)) {
            return $captcha;
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            LoginThrottle::recordFailure($request);

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_INVALID_CREDENTIALS',
                'message' => 'Credenciales incorrectas.',
                'errors' => [],
                'metadata' => [
                    'remaining_attempts' => LoginThrottle::remainingAttempts($request),
                ],
            ], 401);
        }

        // Credenciales validas: se libera el contador aunque falte el 2FA.
        LoginThrottle::clear($request);

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

    /**
     * Respuesta 429 del candado de login. El encabezado `Retry-After` es la
     * fuente de verdad que consume el temporizador regresivo del frontend.
     */
    private function throttledResponse(Request $request, int $seconds): JsonResponse
    {
        Log::warning('[Seguridad] Login bloqueado por exceso de intentos.', [
            'email' => $request->input('email'),
            'ip' => $request->ip(),
            'retry_after' => $seconds,
        ]);

        $minutes = (int) ceil($seconds / 60);

        return response()->json([
            'status' => 'error',
            'code' => 'ERR_AUTH_TOO_MANY_ATTEMPTS',
            'message' => "Por seguridad, tu acceso ha sido bloqueado temporalmente. Intenta nuevamente en {$minutes} minutos.",
            'errors' => [],
            'metadata' => [
                'retry_after_seconds' => $seconds,
                'retry_after_minutes' => $minutes,
                'max_attempts' => LoginThrottle::maxAttempts(),
            ],
        ], 429, [
            'Retry-After' => (string) $seconds,
        ]);
    }

    /**
     * Devuelve una respuesta de error si Turnstile rechaza la peticion, o
     * null si el escudo esta inactivo o el token es legitimo.
     */
    private function rejectedByTurnstile(Request $request): ?JsonResponse
    {
        if (! $this->turnstile->enabled()) {
            return null;
        }

        $token = $request->input('cf_turnstile_token')
            ?? $request->input('cf-turnstile-response');

        $result = $this->turnstile->verify($token, $request->ip());

        if ($result['success']) {
            return null;
        }

        [$code, $status, $message] = match ($result['code']) {
            'missing-input-response' => [
                'ERR_AUTH_CAPTCHA_REQUIRED',
                422,
                'No se recibio la verificacion de seguridad. Recarga la pagina e intenta de nuevo.',
            ],
            'verification-unavailable' => [
                'ERR_AUTH_CAPTCHA_UNAVAILABLE',
                503,
                'El servicio de verificacion de seguridad no esta disponible. Intenta en unos momentos.',
            ],
            default => [
                'ERR_AUTH_CAPTCHA_FAILED',
                403,
                'La verificacion de seguridad no fue superada. Recarga la pagina e intenta de nuevo.',
            ],
        };

        return response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
            'errors' => [],
            'metadata' => [
                'turnstile_error' => $result['code'],
            ],
        ], $status);
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
