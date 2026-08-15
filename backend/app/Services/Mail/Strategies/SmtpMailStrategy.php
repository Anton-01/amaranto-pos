<?php

namespace App\Services\Mail\Strategies;

use App\Mail\TestConnectionMail;
use App\Models\EmailConfiguration;
use App\Services\Mail\Contracts\MailStrategyInterface;
use App\Services\Mail\DynamicMailerFactory;
use App\Services\Mail\Support\MailDiagnostic;
use Closure;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Traditional SMTP delivery (ports 587 / 465 / 2525, local or external).
 *
 * It is the strategy for the "SMTP Generico" provider and the base class of
 * SendGridMailStrategy, since both dial a relay through Symfony's ESMTP
 * transport. The transport itself is not built here: DynamicMailerFactory
 * already merges the database row over config/mailing.php and config/mail.php,
 * and duplicating that resolution would create a second builder able to drift
 * from the one production uses.
 *
 * HOW THE 60-SECOND FREEZE IS REMOVED. A blocked outbound port does not answer
 * with a refusal — cloud hosts DROP the packets — so the TCP handshake simply
 * never completes and the socket waits for its timeout. PHP's default is
 * `default_socket_timeout = 60`, and Symfony's SocketStream falls back to that
 * ini value when the transport does not carry one of its own. The request then
 * hangs for a full minute, the browser gives up, and the administrator learns
 * nothing. Two bounds are applied here, together, because either one alone
 * leaves a hole:
 *
 *   1. `timeout` on the mailer configuration — Laravel forwards it to
 *      SocketStream::setTimeout(), which is what the connect attempt uses.
 *      Capped rather than overwritten, so a deployment that deliberately
 *      configured 5 seconds keeps its own value.
 *   2. `default_socket_timeout` around the send, restored in a finally block.
 *      This one covers everything the first cannot reach: a transport resolved
 *      from a MAIL_URL DSN, a TLS negotiation that stalls after the socket
 *      opened, and any stream the mailer library opens on its own.
 *
 * The health check uses the short bound (config('mailing.timeouts.test')); the
 * queue worker uses the long one, because a job that waits 30 seconds and
 * retries with backoff is behaving correctly — nobody is watching a spinner.
 */
class SmtpMailStrategy implements MailStrategyInterface
{
    /** Transport keys that describe the route and never the credential. */
    private const ROUTE_KEYS = ['transport', 'host', 'port', 'encryption', 'timeout'];

    /**
     * Submission port blocked by default on DigitalOcean, AWS, GCP and Azure.
     * Reaching it here means the test is about to hang until its timeout.
     */
    private const FIREWALLED_PORT = 587;

    public function __construct(
        protected readonly DynamicMailerFactory $mailers,
        protected readonly MailFactory $manager,
    ) {
    }

    public function provider(): string
    {
        return EmailConfiguration::PROVIDER_SMTP;
    }

    public function send(Mailable $mail, array $config): bool
    {
        $mailerName = $this->prepareMailer($config, $this->timeout('send'));

        /*
         * sendNow(), not send(): the caller is the queue worker and the
         * Mailable is a ShouldQueue instance, so send() would push it back to
         * Redis — where the run-time transport injected above no longer exists.
         */
        $this->withBoundedSocketTimeout(
            $this->timeout('send'),
            fn () => Mail::mailer($mailerName)->to($config['target_emails'])->sendNow($mail)
        );

        return true;
    }

    public function testConnection(array $config): array
    {
        $startedAt = microtime(true);
        $recipients = $config['target_emails'] ?? [];
        $transport = [];

        try {
            $timeout = $this->timeout('test');
            $mailerName = $this->prepareMailer($config, $timeout);
            $transport = $this->routeOf($mailerName);

            /*
             * A real message through the real transport. Anything cheaper —
             * opening a socket, issuing an EHLO — proves the port is open and
             * says nothing about whether the credential authenticates or the
             * sender identity is accepted, which is where these configurations
             * actually fail.
             */
            $this->withBoundedSocketTimeout($timeout, fn () => Mail::mailer($mailerName)
                ->to($recipients)
                ->send(
                    (new TestConnectionMail($config['process_type'], $transport))
                        ->from($config['from_email'], $config['from_name'])
                ));
        } catch (Throwable $e) {
            return MailDiagnostic::failure(
                $config['provider'] ?? $this->provider(),
                class_basename($this),
                $transport,
                $recipients,
                $startedAt,
                $e,
                $this->hints($transport, $config),
            )->toArray();
        }

        return MailDiagnostic::success(
            $config['provider'] ?? $this->provider(),
            class_basename($this),
            $transport,
            $recipients,
            $startedAt,
            $this->hints($transport, $config),
        )->toArray();
    }

    public function describeTransport(array $config): array
    {
        // preview() resolves the same values register() would inject without
        // touching config('mail.mailers'), which is what keeps
        // `app:debug-mail-config` a read-only command.
        $preview = $this->mailers->preview($this->carrier($config));

        return $this->summarize($preview['mailer'], $preview['transport']);
    }

    /**
     * Registers the run-time transport and bounds how long it may block.
     *
     * @param  array<string, mixed>  $config
     */
    protected function prepareMailer(array $config, int $timeout): string
    {
        $mailerName = $this->mailers->register($this->carrier($config));

        $this->capTimeout($mailerName, $timeout);

        return $mailerName;
    }

    /**
     * Lowers the mailer's socket timeout when it is missing or too generous.
     *
     * Only ever lowers it: a deployment that configured MAIL_TIMEOUT=5 knows
     * something about its network that this default does not.
     */
    protected function capTimeout(string $mailerName, int $timeout): void
    {
        $mailer = config("mail.mailers.{$mailerName}");

        if (! is_array($mailer) || ($mailer['transport'] ?? 'smtp') !== 'smtp') {
            return;
        }

        $current = $mailer['timeout'] ?? null;

        if (filled($current) && (int) $current <= $timeout) {
            return;
        }

        Config::set("mail.mailers.{$mailerName}.timeout", $timeout);

        /*
         * MailManager caches every mailer it has already resolved, so a
         * transport built earlier in this process would keep the old timeout.
         * The instance check mirrors DynamicMailerFactory: under Mail::fake()
         * the manager is a test double with no cache to flush.
         */
        if ($this->manager instanceof MailManager) {
            $this->manager->forgetMailers();
        }
    }

    /**
     * Runs the send with a bounded `default_socket_timeout`, always restored.
     *
     * The ini value is process-wide, which is precisely why it is set for the
     * duration of one call and put back in a finally block: PHP-FPM reuses the
     * worker for the next request, and a leaked 8-second socket timeout would
     * silently shorten every unrelated stream in the application.
     */
    protected function withBoundedSocketTimeout(int $timeout, Closure $callback): mixed
    {
        $previous = ini_get('default_socket_timeout');

        ini_set('default_socket_timeout', (string) $timeout);

        try {
            return $callback();
        } finally {
            ini_set('default_socket_timeout', $previous === false ? '60' : $previous);
        }
    }

    /**
     * Unsaved model used as the carrier of the payload.
     *
     * DynamicMailerFactory speaks EmailConfiguration and never asks whether the
     * row exists, so an in-memory instance lets the health check validate
     * credentials that were never persisted through the exact code path the
     * queue uses.
     *
     * @param  array<string, mixed>  $config
     */
    protected function carrier(array $config): EmailConfiguration
    {
        return new EmailConfiguration([
            'process_type' => $config['process_type'] ?? EmailConfiguration::PROCESS_JOBS,
            'provider' => $config['provider'] ?? $this->provider(),
            'api_key' => $config['api_key'] ?? null,
            'from_email' => $config['from_email'] ?? null,
            'from_name' => $config['from_name'] ?? null,
            'target_emails' => $config['target_emails'] ?? [],
        ]);
    }

    /** Route of a mailer already present in the configuration. */
    protected function routeOf(string $mailerName): array
    {
        return $this->summarize($mailerName, (array) config("mail.mailers.{$mailerName}"));
    }

    /**
     * Route summary, credential-free by construction: only the keys of
     * self::ROUTE_KEYS survive, so `password` cannot ride along into a JSON
     * response or a log line.
     *
     * @param  array<string, mixed>  $mailer
     * @return array<string, mixed>
     */
    protected function summarize(string $mailerName, array $mailer): array
    {
        return array_merge(
            ['channel' => 'smtp', 'mailer' => $mailerName],
            Arr::only($mailer, self::ROUTE_KEYS)
        );
    }

    /**
     * Practical notes attached to the diagnostic.
     *
     * They are hints, not errors: the send may well have succeeded. Port 587 is
     * called out because it works on a laptop and times out on a VPS, which is
     * the single most expensive misconfiguration in this module.
     *
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function hints(array $transport, array $config): array
    {
        $hints = [];

        if ((int) ($transport['port'] ?? 0) === self::FIREWALLED_PORT) {
            $hints[] = 'El puerto 587 esta bloqueado por defecto en la mayoria de los VPS '
                .'(DigitalOcean, AWS, GCP, Azure). Si la prueba agota el tiempo, usa el puerto '
                .'alterno del proveedor (2525) o cambia a Resend, que sale por HTTPS/443.';
        }

        return $hints;
    }

    /** Bound in seconds for a given phase ("test" or "send"). */
    protected function timeout(string $phase): int
    {
        return (int) config("mailing.timeouts.{$phase}", $phase === 'test' ? 8 : 30);
    }
}
