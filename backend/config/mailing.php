<?php

use App\Services\Mail\Strategies\ResendMailStrategy;
use App\Services\Mail\Strategies\SendGridMailStrategy;
use App\Services\Mail\Strategies\SmtpMailStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Network Timeout Budget
    |--------------------------------------------------------------------------
    |
    | Seconds a strategy may spend inside the network, per phase. These values
    | are the whole mitigation of the VPS port-block freeze: when a host DROPS
    | the packets of a blocked submission port, nothing answers and the socket
    | waits for its timeout. PHP defaults `default_socket_timeout` to 60, so
    | without an explicit bound the "Probar Conexion" button hangs the browser
    | for a full minute and then reports nothing useful.
    |
    | test    -> synchronous health check. Short on purpose: an administrator is
    |            watching a spinner, and a blocked port must come back as an
    |            error they can read, not as a frozen tab.
    | send    -> queue worker. Longer, because nobody is waiting and the job
    |            retries with backoff; a slow relay is not a failure there.
    | connect -> TCP/TLS handshake bound for the HTTP strategies (cURL
    |            connect_timeout). This is the one that fires against a
    |            filtered port, before a single byte is exchanged.
    |
    */

    'timeouts' => [
        'test' => (int) env('MAIL_TEST_TIMEOUT', 8),
        'send' => (int) env('MAIL_SEND_TIMEOUT', 30),
        'connect' => (int) env('MAIL_CONNECT_TIMEOUT', 5),
    ],

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
    | RESOLUTION ORDER. A skeleton is an *override layer*, never a complete
    | transport: DynamicMailerFactory reads it on top of the mailer named by
    | `inherits`, which is the one config/mail.php builds out of the MAIL_*
    | variables of the .env. Priority, per field:
    |
    |     database row  >  this skeleton  >  config/mail.php (.env)
    |
    | Therefore a key left null here is NOT "no mail server", it means "keep
    | whatever the server's .env declares". Do not reintroduce literal
    | fallbacks (127.0.0.1, 587, ...) on keys the deployment is expected to
    | own: a hardcoded default here silently outranks a correct MAIL_HOST and
    | the application dials loopback while the .env points at the real relay.
    |
    | credentials:
    |   "api_key"  -> SendGrid's SMTP relay expects the literal username
    |                 "apikey" and the API key as the password.
    |   "password" -> generic servers authenticate with a plain login; the
    |                 stored secret is the password, and the username falls
    |                 back to the sender address only when neither this
    |                 skeleton nor MAIL_USERNAME names one.
    |
    | inherits:
    |   Name of the mailer in config/mail.php this provider completes itself
    |   with. Only meaningful for the SMTP family; an HTTP provider builds no
    |   Symfony transport and therefore inherits nothing.
    |
    | strategy:
    |   Class that actually talks to the provider, resolved by
    |   App\Services\Mail\MailStrategyFactory. This key is what makes the
    |   Strategy pattern configurable: adding a provider is a block here plus
    |   one class implementing MailStrategyInterface, with no edit to the queue
    |   job, the controller or the factory.
    |
    | driver:
    |   "smtp" -> the block below is an override layer for a Symfony transport
    |             and is read by DynamicMailerFactory.
    |   "http" -> the provider is reached over HTTPS and has no transport to
    |             merge; DynamicMailerFactory refuses to build one from it.
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
         *
         * SendGrid is a named endpoint, so this skeleton legitimately pins host
         * and port: picking "SendGrid" in the admin UI is an explicit request
         * for smtp.sendgrid.net, not for whatever MAIL_HOST happens to be.
         */
        'sendgrid' => [
            'driver' => 'smtp',
            'strategy' => SendGridMailStrategy::class,
            'transport' => 'smtp',
            'inherits' => 'smtp',
            'host' => env('SENDGRID_SMTP_HOST', 'smtp.sendgrid.net'),
            'port' => (int) env('SENDGRID_SMTP_PORT', 2525),
            'encryption' => env('SENDGRID_SMTP_ENCRYPTION', 'tls'),
            'username' => 'apikey',
            'credentials' => 'api_key',
            'timeout' => (int) env('SENDGRID_SMTP_TIMEOUT', 15),
        ],

        /*
         * Generic relay: the provider that deliberately declares NO endpoint.
         *
         * "SMTP Generico" means "the server's own mail server", so every
         * endpoint key is null and the transport is completed from the MAIL_*
         * block of the .env. The DYNAMIC_SMTP_* variables stay available for
         * the deployment that needs the queue to relay through a host
         * different from the rest of the application, and they are read only
         * when they are actually set.
         *
         * This is the key that used to read env('DYNAMIC_SMTP_HOST',
         * '127.0.0.1'). Since no deployment defines DYNAMIC_SMTP_HOST, that
         * literal won every time and every generic-provider send was routed to
         * loopback — where nothing listens — no matter what .env.production
         * declared in MAIL_HOST. The whole fix is that these values are now
         * absent instead of wrong.
         *
         * If you do point DYNAMIC_SMTP_HOST at an external server, the egress
         * block described above applies to it too: prefer the relay's
         * alternate submission port over 587.
         */
        'smtp' => [
            'driver' => 'smtp',
            'strategy' => SmtpMailStrategy::class,
            'transport' => 'smtp',
            'inherits' => 'smtp',
            'host' => env('DYNAMIC_SMTP_HOST'),
            'port' => env('DYNAMIC_SMTP_PORT'),
            'encryption' => env('DYNAMIC_SMTP_ENCRYPTION'),
            'username' => env('DYNAMIC_SMTP_USERNAME'),
            'credentials' => 'password',
            'timeout' => env('DYNAMIC_SMTP_TIMEOUT'),
        ],

        /*
         * Resend over its native HTTP API — the provider that never opens an
         * SMTP socket.
         *
         * Everything above shares one failure mode that has nothing to do with
         * mail: the host blocks outbound submission ports. 587 is filtered by
         * default on DigitalOcean, AWS, GCP and Azure, and hosts that also
         * filter 2525 leave the SMTP family with no route at all. Resend's REST
         * endpoint answers on HTTPS/443, the port the application already uses
         * for every other request, so picking this provider removes the egress
         * problem instead of routing around it.
         *
         * There is deliberately no host / port / encryption / inherits block:
         * this provider builds no Symfony transport, so it has nothing to merge
         * over config/mail.php. `driver => http` is what stops
         * DynamicMailerFactory from trying.
         */
        'resend' => [
            'driver' => 'http',
            'strategy' => ResendMailStrategy::class,
            'transport' => 'resend',
            'endpoint' => env('RESEND_API_ENDPOINT', 'https://api.resend.com/emails'),
            'credentials' => 'api_key',
        ],

    ],

];
