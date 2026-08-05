<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile (Escudo anti-bots del login — Fase 9)
    |--------------------------------------------------------------------------
    |
    | site_key   -> publico, consumido por el frontend (VITE_TURNSTILE_SITE_KEY).
    | secret_key -> privado, jamas sale del backend. Sin el, el escudo queda
    |               inactivo y el login opera con las demas capas (throttling).
    | fail_open  -> que hacer si Cloudflare no responde. Por defecto se cierra.
    |
    */

    'turnstile' => [
        'enabled' => (bool) env('TURNSTILE_ENABLED', true),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
        'fail_open' => (bool) env('TURNSTILE_FAIL_OPEN', false),
    ],

];
