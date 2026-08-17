<?php

namespace App\Services\Mail\Strategies;

use App\Models\EmailConfiguration;

/**
 * SendGrid delivery through its secured SMTP relay.
 *
 * It extends the SMTP strategy because the wire protocol is the same one, and
 * exists as its own class because the policy is not: SendGrid authenticates
 * with the literal username "apikey" plus the key as password, and relays
 * through the alternate submission port 2525 instead of 587 — both encoded in
 * the `sendgrid` skeleton of config/mailing.php, which this strategy reaches
 * through the provider key it declares below.
 *
 * WHY 2525 AND NOT 587. Cloud providers block outbound 587 as an anti-spam
 * policy and drop the packets rather than rejecting them, so the handshake
 * hangs until the socket expires. SendGrid publishes 2525 as an alternate port
 * with identical behaviour — same credentials, same STARTTLS upgrade — and it
 * is not covered by those rules.
 *
 * WHEN TO MOVE THIS TO THE HTTP API. SendGrid also exposes
 * https://api.sendgrid.com/v3/mail/send, which would sidestep SMTP ports
 * altogether the way ResendMailStrategy does. The relay is kept because it is
 * what the deployments in production are already authenticated against, and
 * because 2525 traverses the block today. Should a host start filtering 2525
 * as well, the switch is confined to this class: the factory, the job and the
 * controller only know the interface.
 */
class SendGridMailStrategy extends SmtpMailStrategy
{
    /** Every SendGrid API key is issued with this prefix. */
    private const KEY_PREFIX = 'SG.';

    public function provider(): string
    {
        return EmailConfiguration::PROVIDER_SENDGRID;
    }

    /**
     * Adds the two mistakes SendGrid reports as opaque authentication errors.
     *
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function hints(array $transport, array $config): array
    {
        $hints = parent::hints($transport, $config);

        $apiKey = (string) ($config['api_key'] ?? '');

        if (filled($apiKey) && ! str_starts_with($apiKey, self::KEY_PREFIX)) {
            // SendGrid answers "authentication failed" for a password typed
            // where an API key belongs, which reads like a revoked key.
            $hints[] = 'La credencial no empieza con "SG.": SendGrid espera una API Key, '
                .'no la contrasena de la cuenta.';
        }

        if (filled($transport['host'] ?? null) && ! str_contains((string) $transport['host'], 'sendgrid')) {
            $hints[] = 'El transporte resuelto no apunta a smtp.sendgrid.net ('
                .$transport['host'].'): revisa SENDGRID_SMTP_HOST en el .env.';
        }

        return $hints;
    }
}
