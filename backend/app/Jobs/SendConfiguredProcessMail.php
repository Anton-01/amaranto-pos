<?php

namespace App\Jobs;

use App\Models\EmailConfiguration;
use App\Services\Mail\MailStrategyFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue bridge between a business process and its database-driven mailbox.
 *
 * Producers (the cash auto-closing command, for instance) only dispatch this
 * job with a process type and a ready Mailable; they never touch credentials
 * and never block on SMTP. Everything that needs the configuration —
 * transport, sender identity, subject and recipients — is resolved here,
 * inside the worker.
 *
 * Why the resolution happens in the worker and not at dispatch time: the
 * transport is injected into config('mail.mailers') at run time and dies with
 * the process. If the producer registered it and merely queued the Mailable,
 * the worker would later look for a mailer name that does not exist in its own
 * configuration. Resolving here keeps the transport and the send in the same
 * process, and has the side benefit of reading the freshest row: a key rotated
 * while the message sat in Redis is still honoured.
 *
 * HOW THE PROVIDER IS CHOSEN. The job does not know what SMTP or HTTPS are: it
 * asks MailStrategyFactory for the strategy of the stored provider and hands it
 * a Mailable. Whether the message then leaves through a Symfony transport
 * (SmtpMailStrategy, SendGridMailStrategy) or an HTTPS request
 * (ResendMailStrategy) is decided entirely inside that class, so migrating a
 * process from a blocked SMTP port to Resend is a change of one column in the
 * database.
 *
 * AD-HOC RECIPIENTS. `$recipientsOverride` lets a caller aim one message
 * somewhere else without touching the saved configuration: the closings
 * history uses it when an operator edits the pre-loaded list before sending.
 * Only the destination changes — provider, credential, sender identity and
 * subject still come from the row, so an ad-hoc report is indistinguishable
 * from a scheduled one to whoever receives it, and the stored list is never
 * rewritten as a side effect of a one-off send.
 *
 * If this job starts failing with "TransportException: Operation timed out" on
 * an SMTP provider, check the port before suspecting the provider: hosts block
 * outbound 587 by default, which is why the SendGrid skeleton in
 * config/mailing.php relays through the alternate port 2525, and why Resend
 * exists as an option that never opens an SMTP socket at all. If it fails
 * against 127.0.0.1 while the .env points somewhere else, the transport was
 * overridden rather than inherited — run `php artisan app:debug-mail-config`
 * to see the route the worker resolves in a live process.
 */
class SendConfiguredProcessMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Transient SMTP failures are common; spread the retries out. */
    public int $tries = 3;

    /** @var array<int, int> Seconds to wait before each retry. */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<int, string>|null  $recipientsOverride  Ad-hoc recipient list. Null means
     *                                                       "use the ones stored in the configuration".
     */
    public function __construct(
        public string $processType,
        public Mailable $mailable,
        public ?array $recipientsOverride = null,
    ) {
    }

    public function handle(MailStrategyFactory $strategies): void
    {
        $configuration = EmailConfiguration::activeFor($this->processType);

        // No active configuration means the administrator turned notifications
        // off for this process. That is a valid state, not a failure: the job
        // completes quietly instead of burning retries.
        if ($configuration === null) {
            Log::info('Envio omitido: sin configuracion de correo activa.', [
                'process_type' => $this->processType,
                'mailable' => $this->mailable::class,
            ]);

            return;
        }

        $isAdHoc = $this->recipientsOverride !== null;
        $recipients = $isAdHoc
            ? $this->cleanRecipients($this->recipientsOverride)
            : $configuration->deliverableEmails();

        if ($recipients === []) {
            Log::warning('Envio omitido: no hay destinatarios validos para el proceso.', [
                'process_type' => $this->processType,
                'ad_hoc' => $isAdHoc,
                'mailable' => $this->mailable::class,
            ]);

            return;
        }

        $strategy = $strategies->for($configuration);

        /*
         * Sender and subject come from the database. Both are applied on the
         * Mailable instance rather than on the transport so the message keeps
         * a single source of truth: the SMTP strategies hand this object to
         * Symfony and the HTTP one reads these same properties to build its
         * JSON payload, so the two produce an identical message.
         */
        $this->mailable
            ->from($configuration->from_email, $configuration->from_name)
            ->subject($configuration->subject);

        /*
         * Credentials, sender and subject always come from the row; only the
         * destination may be replaced. An ad-hoc send is "this same official
         * mailbox, pointed somewhere else once" — it never becomes a second
         * transport, and it never writes back to the stored recipient list.
         */
        $config = $configuration->toStrategyConfig();
        $config['target_emails'] = $recipients;

        $strategy->send($this->mailable, $config);

        /*
         * The resolved route is logged because it is the answer to "which
         * configuration actually won": a "dynamic-*" mailer means the database
         * row supplied the transport, any other name means the row had nothing
         * to override and the message left through the mailer declared in the
         * .env, and a "resend-api" mailer means it never touched SMTP. Without
         * it, all three cases produce an identical success line.
         */
        Log::info('Correo de proceso enviado.', [
            'process_type' => $this->processType,
            'provider' => $configuration->provider,
            'strategy' => class_basename($strategy),
            'route' => $strategy->describeTransport($config),
            'recipients' => count($recipients),
            // Distinguishes a scheduled report from an operator's one-off send
            // when auditing where a financial report ended up.
            'ad_hoc' => $isAdHoc,
            'mailable' => $this->mailable::class,
        ]);
    }

    /**
     * Cleans an ad-hoc list the same way the model cleans the stored one:
     * trimmed, de-duplicated and free of syntactically invalid addresses, so a
     * typo cannot make the whole send fail inside the transport.
     *
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private function cleanRecipients(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }
}
