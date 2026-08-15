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

        /*
         * Port 2525 is deliberate and must not be "corrected" back to 587.
         *
         * Cloud providers (DigitalOcean Droplets, AWS EC2, GCP Compute, Azure
         * VMs) block outbound port 587 by default as an anti-spam policy. The
         * block is silent: the packets are dropped rather than rejected, so the
         * SMTP socket never completes its handshake and the worker dies with
         * "TransportException: Operation timed out" against
         * smtp.sendgrid.net:587 — which reads like a SendGrid outage but is the
         * host's egress firewall.
         *
         * SendGrid publishes 2525 as an alternate relay port, identical in
         * behaviour (same credentials, same STARTTLS upgrade) and not covered by
         * those anti-spam rules, so it traverses the block. Use it as the
         * default everywhere; SENDGRID_SMTP_PORT only exists for hosts that
         * whitelist a different one.
         */
        'sendgrid' => [
            'transport' => 'smtp',
            'host' => env('SENDGRID_SMTP_HOST', 'smtp.sendgrid.net'),
            'port' => (int) env('SENDGRID_SMTP_PORT', 2525),
            'encryption' => env('SENDGRID_SMTP_ENCRYPTION', 'tls'),
            'username' => 'apikey',
            'credentials' => 'api_key',
            'timeout' => (int) env('SENDGRID_SMTP_TIMEOUT', 15),
        ],

        /*
         * Generic relays default to loopback, where no egress firewall applies.
         * Point DYNAMIC_SMTP_HOST at an external server and the same blocking
         * applies: prefer the relay's alternate submission port over 587.
         */
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
