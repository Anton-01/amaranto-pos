<?php

namespace App\Mail;

use App\Models\EmailConfiguration;
use App\Models\GlobalSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Proof-of-delivery message for the mailing health check.
 *
 * Its arrival in the administrator's inbox is the assertion: it proves the
 * credential authenticated, the sender identity was accepted by the provider
 * and the outbound port survived the host's egress firewall. The body is a
 * diagnostic sheet rather than a notification — whoever receives it is
 * debugging the configuration, not reading business news.
 *
 * Deliberately NOT a ShouldQueue Mailable, unlike every other Mailable in the
 * application. A queued diagnostic would answer "queued" instead of "it
 * works": the administrator would get an HTTP 200 while the actual SMTP
 * failure surfaced minutes later in a worker log they cannot see. This one is
 * pushed through the transport inside the request so the exception — a timed
 * out socket, a rejected API key — travels back to the browser.
 */
class TestConnectionMail extends Mailable
{
    public const DEFAULT_SUBJECT = 'Cronos POS - Prueba de Conexion SMTP';

    /**
     * @param  string  $processType  Process key being validated ("jobs", "users", ...).
     * @param  array<string, mixed>  $transport  Host, port and encryption actually dialled.
     */
    public function __construct(
        public string $processType,
        public array $transport = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject ?: self::DEFAULT_SUBJECT,
        );
    }

    public function content(): Content
    {
        /*
         * The timestamp is resolved while rendering, not while constructing.
         * For this Mailable both instants are the same — nothing queues it —
         * and that is exactly the point: the date printed in the body is the
         * moment the message left the server, so an administrator comparing it
         * against the inbox timestamp measures real delivery latency.
         */
        $sentAt = Carbon::now();

        $fiscal = GlobalSetting::where('key', 'fiscal_data')->value('value') ?? [];

        return new Content(
            view: 'mail.test-connection',
            with: [
                'processType' => $this->processType,
                'processLabel' => EmailConfiguration::PROCESS_TYPES[$this->processType] ?? $this->processType,
                'sentAt' => $sentAt->format('d/m/Y H:i:s'),
                'timezone' => $sentAt->getTimezone()->getName(),
                'transport' => $this->transport,
                'footerFiscal' => is_array($fiscal) ? $fiscal : [],
            ],
        );
    }
}
