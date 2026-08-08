<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Runtime Mailer Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix of the mailer names injected into config('mail.mailers') at run
    | time by App\Services\Mail\DynamicMailerFactory. Keeping them namespaced
    | guarantees a database-driven transport can never shadow a mailer declared
    | in config/mail.php.
    |
    */

    'runtime_mailer_prefix' => 'dynamic',

    /*
    |--------------------------------------------------------------------------
    | Provider Templates
    |--------------------------------------------------------------------------
    |
    | Skeleton transport configuration per provider stored in
    | email_configurations.provider. Everything that is a secret (the API key)
    | or tenant-specific (sender identity, recipients) lives in the database;
    | only the fixed endpoint details live here.
    |
    | credentials:
    |   "api_key"  -> SendGrid's SMTP relay expects the literal username
    |                 "apikey" and the API key as the password.
    |   "password" -> generic servers authenticate with the sender address as
    |                 username and the stored secret as password.
    |
    */

    'providers' => [

        'sendgrid' => [
            'transport' => 'smtp',
            'host' => env('SENDGRID_SMTP_HOST', 'smtp.sendgrid.net'),
            'port' => (int) env('SENDGRID_SMTP_PORT', 587),
            'encryption' => env('SENDGRID_SMTP_ENCRYPTION', 'tls'),
            'username' => 'apikey',
            'credentials' => 'api_key',
            'timeout' => (int) env('SENDGRID_SMTP_TIMEOUT', 15),
        ],

        'smtp' => [
            'transport' => 'smtp',
            'host' => env('DYNAMIC_SMTP_HOST', '127.0.0.1'),
            'port' => (int) env('DYNAMIC_SMTP_PORT', 587),
            'encryption' => env('DYNAMIC_SMTP_ENCRYPTION', 'tls'),
            'username' => null,
            'credentials' => 'password',
            'timeout' => (int) env('DYNAMIC_SMTP_TIMEOUT', 15),
        ],

    ],

];
