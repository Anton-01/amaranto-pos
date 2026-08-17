<?php

namespace App\Services\Mail\Strategies;

use App\Exceptions\Mail\MailTransportException;
use App\Mail\TestConnectionMail;
use App\Models\EmailConfiguration;
use App\Services\Mail\Contracts\MailStrategyInterface;
use App\Services\Mail\Support\MailDiagnostic;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resend delivery through its native HTTP API — no SMTP socket at all.
 *
 * WHY THIS STRATEGY EXISTS. Every SMTP provider in this module shares one
 * failure mode that has nothing to do with mail: the VPS blocks outbound
 * submission ports. DigitalOcean, AWS, GCP and Azure filter 587 by default and
 * some hosts filter 2525 too, dropping the packets instead of rejecting them,
 * so the connection hangs until the socket expires and the only diagnosis the
 * administrator ever sees is "Operation timed out". Resend publishes a REST API
 * at https://api.resend.com/emails, which travels over HTTPS on port 443 — the
 * same port the application already uses for everything else, and the one port
 * no provider blocks. Choosing "Resend" in the admin UI therefore removes the
 * entire class of egress-firewall failures rather than working around it.
 *
 * HOW IT CANNOT FREEZE. The request carries two explicit cURL bounds:
 * `connect_timeout` (how long the TCP/TLS handshake may take) and `timeout`
 * (the whole exchange), both read from config('mailing.timeouts'). A dropped
 * packet therefore fails in seconds with a ConnectionException instead of
 * inheriting PHP's 60-second default socket timeout.
 *
 * WHAT IS SENT. The Mailable is rendered to HTML here and posted as a JSON
 * document; Laravel's Mail facade is not involved, because there is no Symfony
 * transport in this path. The rendered body is byte-for-byte the one the SMTP
 * strategies would have handed to the relay, so switching providers never
 * changes what the recipient sees.
 */
class ResendMailStrategy implements MailStrategyInterface
{
    /** Documented Resend endpoint; only overridden by tests and proxies. */
    private const DEFAULT_ENDPOINT = 'https://api.resend.com/emails';

    public function provider(): string
    {
        return EmailConfiguration::PROVIDER_RESEND;
    }

    public function send(Mailable $mail, array $config): bool
    {
        $this->dispatch($mail, $config, $this->timeout('send'));

        return true;
    }

    public function testConnection(array $config): array
    {
        $startedAt = microtime(true);
        $transport = $this->describeTransport($config);
        $recipients = $config['target_emails'] ?? [];

        try {
            $probe = (new TestConnectionMail($config['process_type'], $transport))
                ->from($config['from_email'], $config['from_name']);

            $response = $this->dispatch($probe, $config, $this->timeout('test'));
        } catch (Throwable $e) {
            return MailDiagnostic::failure(
                $this->provider(),
                class_basename($this),
                $transport,
                $recipients,
                $startedAt,
                $e,
                $this->hints($config, $e),
            )->toArray();
        }

        return MailDiagnostic::success(
            $this->provider(),
            class_basename($this),
            // The message id proves the API accepted the message, which is the
            // strongest evidence a synchronous check can return.
            $transport + array_filter(['message_id' => $response['id'] ?? null]),
            $recipients,
            $startedAt,
        )->toArray();
    }

    public function describeTransport(array $config): array
    {
        $endpoint = $this->endpoint();

        return [
            'channel' => 'https',
            'mailer' => 'resend-api',
            'transport' => 'resend',
            'host' => parse_url($endpoint, PHP_URL_HOST) ?: 'api.resend.com',
            'port' => (int) (parse_url($endpoint, PHP_URL_PORT) ?: 443),
            'encryption' => 'https',
            'timeout' => $this->timeout('test'),
            'endpoint' => $endpoint,
        ];
    }

    /**
     * Posts the rendered message and returns the decoded API answer.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws MailTransportException when the credential is missing or the API rejects the message.
     * @throws ConnectionException when the endpoint is unreachable within the bounds below.
     */
    private function dispatch(Mailable $mail, array $config, int $timeout): array
    {
        $recipients = array_values($config['target_emails'] ?? []);

        if (blank($config['api_key'] ?? null)) {
            throw new MailTransportException(
                'ERR_MAIL_RESEND_NO_CREDENTIAL: Resend requiere una API Key (re_...) para autenticar la peticion HTTPS.'
            );
        }

        if ($recipients === []) {
            throw new MailTransportException(
                'ERR_MAIL_RESEND_NO_RECIPIENTS: la configuracion no tiene destinatarios validos.'
            );
        }

        $response = $this->client($config['api_key'], $timeout)->post($this->endpoint(), [
            'from' => $this->sender($config),
            'to' => $recipients,
            'subject' => $this->subjectFor($mail, $config),
            'html' => $mail->render(),
        ]);

        if ($response->failed()) {
            // A 4xx is a normal Response object, not an exception: normalizing
            // it here is what lets the diagnostic layer treat "API key invalid"
            // and "socket timed out" with the same machinery.
            throw MailTransportException::fromResponse('Resend', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * HTTP client with the timeouts that make this strategy non-blocking.
     *
     * `connectTimeout` is the one that matters against a filtered port: without
     * it, a dropped SYN waits for the operating system's own retransmission
     * budget. `timeout` covers the rest of the exchange, so a provider that
     * accepts the connection and then stalls cannot hold the request either.
     */
    private function client(string $apiKey, int $timeout): PendingRequest
    {
        return Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->timeout('connect'))
            ->timeout($timeout);
    }

    /**
     * RFC 5322 sender: `Name <address>`.
     *
     * Quotes are stripped from the display name rather than escaped — a name
     * carrying one is a typo, and the header is worth more intact than exact.
     *
     * @param  array<string, mixed>  $config
     */
    private function sender(array $config): string
    {
        $email = (string) ($config['from_email'] ?? '');
        $name = trim(str_replace(['"', '<', '>'], '', (string) ($config['from_name'] ?? '')));

        return $name === '' ? $email : sprintf('%s <%s>', $name, $email);
    }

    /**
     * Subject actually carried by the message.
     *
     * The queue job applies the configured subject on the Mailable before
     * handing it over, so the property wins. A Mailable that declares its
     * subject in envelope() — the diagnostic one does — is asked for it, and
     * the stored subject is the last resort.
     *
     * @param  array<string, mixed>  $config
     */
    private function subjectFor(Mailable $mail, array $config): string
    {
        if (filled($mail->subject ?? null)) {
            return (string) $mail->subject;
        }

        if (method_exists($mail, 'envelope') && filled($subject = $mail->envelope()->subject)) {
            return (string) $subject;
        }

        return (string) ($config['subject'] ?? config('app.name', 'Cronos POS'));
    }

    /**
     * Notes for the two Resend failures that read as something else.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function hints(array $config, Throwable $e): array
    {
        $hints = [];

        $apiKey = (string) ($config['api_key'] ?? '');

        if (filled($apiKey) && ! str_starts_with($apiKey, 're_')) {
            $hints[] = 'La credencial no empieza con "re_": Resend emite sus API Keys con ese prefijo.';
        }

        if ($e instanceof MailTransportException && $e->statusCode() === 403) {
            $hints[] = 'Resend responde 403 cuando el dominio del remitente no esta verificado en su panel.';
        }

        return $hints;
    }

    /** Endpoint of the transactional email resource. */
    private function endpoint(): string
    {
        $endpoint = config('mailing.providers.'.EmailConfiguration::PROVIDER_RESEND.'.endpoint');

        return filled($endpoint) ? (string) $endpoint : self::DEFAULT_ENDPOINT;
    }

    /** Bound in seconds for a given phase ("test", "send" or "connect"). */
    private function timeout(string $phase): int
    {
        return (int) config("mailing.timeouts.{$phase}", match ($phase) {
            'connect' => 5,
            'test' => 8,
            default => 30,
        });
    }
}
