<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Escudo anti fuerza bruta del login (Fase 9).
 *
 * Politica de DOS NIVELES sobre la clave compuesta email + IP:
 *
 *   Nivel 1 — Ventana de conteo (60 s): tolera N intentos FALLIDOS.
 *   Nivel 2 — Candado (900 s): al desbordar la ventana se activa un bloqueo
 *             largo. Mientras dure, ninguna credencial se compara contra la
 *             base de datos: el request muere en el borde con 429.
 *
 * Identificar por email + IP evita dos fallos clasicos: bloquear a toda la
 * sucursal por un solo cajero torpe (throttle por IP pura) y permitir el
 * rociado de credenciales desde una botnet (throttle por email puro).
 */
class LoginThrottle
{
    /**
     * Segundos restantes de bloqueo, o null si el acceso esta permitido.
     *
     * Efecto colateral deliberado: si la ventana de intentos fallidos ya
     * esta desbordada, esta llamada ARMA el candado de 15 minutos.
     */
    public static function lockedSeconds(Request $request): ?int
    {
        $lockKey = self::lockKey($request);

        if (RateLimiter::tooManyAttempts($lockKey, 1)) {
            return max(1, RateLimiter::availableIn($lockKey));
        }

        $attemptsKey = self::attemptsKey($request);

        if (RateLimiter::tooManyAttempts($attemptsKey, self::maxAttempts())) {
            $lockout = self::lockoutSeconds();

            RateLimiter::hit($lockKey, $lockout);
            RateLimiter::clear($attemptsKey);

            return $lockout;
        }

        return null;
    }

    /**
     * Contabiliza un intento fallido dentro de la ventana corta.
     */
    public static function recordFailure(Request $request): void
    {
        RateLimiter::hit(self::attemptsKey($request), self::windowSeconds());
    }

    /**
     * Intentos que aun restan antes de activar el candado.
     */
    public static function remainingAttempts(Request $request): int
    {
        return max(0, RateLimiter::remaining(self::attemptsKey($request), self::maxAttempts()));
    }

    /**
     * Autenticacion exitosa: se libera el contador y cualquier candado previo.
     */
    public static function clear(Request $request): void
    {
        RateLimiter::clear(self::attemptsKey($request));
        RateLimiter::clear(self::lockKey($request));
    }

    public static function maxAttempts(): int
    {
        return (int) config('security.throttle.login.max_attempts', 5);
    }

    public static function lockoutSeconds(): int
    {
        return (int) config('security.throttle.login.lockout_seconds', 900);
    }

    private static function windowSeconds(): int
    {
        return (int) config('security.throttle.login.window_seconds', 60);
    }

    private static function attemptsKey(Request $request): string
    {
        return 'auth:login:attempts:'.self::signature($request);
    }

    private static function lockKey(Request $request): string
    {
        return 'auth:login:lock:'.self::signature($request);
    }

    /**
     * Huella email+IP. Se hashea para no persistir correos en las claves de
     * Redis y se normaliza a minusculas para que Admin@X y admin@x compartan
     * el mismo contador.
     */
    private static function signature(Request $request): string
    {
        $email = Str::lower(trim((string) $request->input('email')));

        return sha1($email.'|'.$request->ip());
    }
}
