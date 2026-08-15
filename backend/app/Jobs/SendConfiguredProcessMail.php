<?php

namespace App\Jobs;

use App\Models\EmailConfiguration;
use App\Services\Mail\DynamicMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
 * If this job starts failing with "TransportException: Operation timed out",
 * check the port of the transport before suspecting the provider: hosts block
 * outbound 587 by default, which is why the SendGrid skeleton in
 * config/mailing.php relays through the alternate port 2525.
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

    public function __construct(
        public string $processType,
        public Mailable $mailable,
    ) {
    }

    public function handle(DynamicMailerFactory $factory): void
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

        $recipients = $configuration->deliverableEmails();

        if ($recipients === []) {
            Log::warning('Envio omitido: la configuracion activa no tiene destinatarios validos.', [
                'process_type' => $this->processType,
                'mailable' => $this->mailable::class,
            ]);

            return;
        }

        $mailerName = $factory->register($configuration);

        /*
         * Sender and subject come from the database. Both are applied on the
         * Mailable instance rather than on the transport so the message keeps
         * a single source of truth, and so a Mailable whose envelope() honours
         * $this->subject picks up the configured wording.
         */
        $this->mailable
            ->from($configuration->from_email, $configuration->from_name)
            ->subject($configuration->subject);

        // sendNow (instead of send/queue) is deliberate: the Mailable is a
        // ShouldQueue instance, and queueing it again from here would push it
        // back to Redis, where the run-time transport no longer exists. We are
        // already inside the worker — this is the asynchronous leg.
        Mail::mailer($mailerName)->to($recipients)->sendNow($this->mailable);

        Log::info('Correo de proceso enviado.', [
            'process_type' => $this->processType,
            'provider' => $configuration->provider,
            'recipients' => count($recipients),
            'mailable' => $this->mailable::class,
        ]);
    }
}
