<?php

/*
|--------------------------------------------------------------------------
| Escudo de Seguridad — Fase 9
|--------------------------------------------------------------------------
|
| Punto unico de verdad para las politicas de throttling y las cabeceras
| de seguridad HTTP del POS. Todo es parametrizable por entorno para que
| el hardening de produccion no rompa el desarrollo local (Vite HMR,
| agente de impresion en 127.0.0.1, Reverb sin TLS, etc.).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Politicas de Rate Limiting
    |--------------------------------------------------------------------------
    |
    | api    -> Escudo global anti-saturacion / bucles infinitos del frontend.
    | login  -> Escudo estricto anti fuerza bruta sobre /api/auth/login.
    |
    | La politica de login es de dos niveles: una ventana corta que cuenta
    | los intentos fallidos y un candado largo que se activa al desbordarla.
    |
    */

    'throttle' => [

        'api' => [
            // Peticiones permitidas por minuto y por identidad (usuario o IP).
            'max_attempts' => (int) env('THROTTLE_API_MAX_ATTEMPTS', 100),
        ],

        'login' => [
            // Intentos FALLIDOS tolerados dentro de la ventana.
            'max_attempts' => (int) env('THROTTLE_LOGIN_MAX_ATTEMPTS', 5),

            // Duracion de la ventana de conteo, en segundos (1 minuto).
            'window_seconds' => (int) env('THROTTLE_LOGIN_WINDOW', 60),

            // Bloqueo aplicado al desbordar la ventana, en segundos (15 minutos).
            'lockout_seconds' => (int) env('THROTTLE_LOGIN_LOCKOUT', 900),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cabeceras de Seguridad HTTP
    |--------------------------------------------------------------------------
    |
    | Inyectadas por App\Http\Middleware\SecurityHeaders en TODA respuesta.
    |
    */

    'headers' => [

        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

        // HSTS solo tiene sentido sobre TLS. En local (http) se omite salvo
        // que se fuerce explicitamente.
        'hsts' => [
            'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
            'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
            'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
            'force' => (bool) env('SECURITY_HSTS_FORCE', false),
        ],

        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

        'permissions_policy' => env(
            'SECURITY_PERMISSIONS_POLICY',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        ),

    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Origenes permitidos, calibrados para la realidad operativa del POS:
    |
    |  - React/Vite compilado se sirve desde el mismo origen ('self').
    |  - Tailwind v4 y PrimeReact inyectan estilos en linea -> 'unsafe-inline'
    |    es obligatorio en style-src (no en script-src).
    |  - Cloudflare Turnstile carga script + iframe desde challenges.cloudflare.com.
    |  - El Cronos POS Agent vive en http://127.0.0.1:9100 (impresion local).
    |  - Reverb habla WebSocket (ws:// en local, wss:// en produccion).
    |
    */

    'csp' => [

        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

        // En modo report-only el navegador reporta pero no bloquea. Util para
        // calibrar la politica antes de aplicarla en produccion.
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        'turnstile_origin' => 'https://challenges.cloudflare.com',

        // Origenes extra separados por espacio (ej. un CDN de imagenes).
        'extra_script_src' => env('SECURITY_CSP_EXTRA_SCRIPT_SRC', ''),
        'extra_connect_src' => env('SECURITY_CSP_EXTRA_CONNECT_SRC', ''),
        'extra_img_src' => env('SECURITY_CSP_EXTRA_IMG_SRC', ''),

        // Agentes locales autorizados (impresion ESC/POS via HTTP local).
        'local_agents' => [
            'http://127.0.0.1:9100',
            'http://localhost:9100',
        ],

    ],

];
