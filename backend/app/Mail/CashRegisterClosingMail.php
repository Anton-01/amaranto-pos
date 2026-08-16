<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Cash closings history report, sent by hand from the closings screen.
 *
 * It leaves through SendConfiguredProcessMail like every other business
 * message, so the credential, the sender identity and the subject are the ones
 * stored for the `sales` process — this class only decides what the report
 * says, never how it travels.
 */
class CashRegisterClosingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Used when the sending process has no subject configured. */
    public const DEFAULT_SUBJECT = 'Reporte de Cierres de Caja — Cronos POS';

    public function __construct(
        public Collection $closings,
        public array $filters = []
    ) {}

    public function envelope(): Envelope
    {
        /*
         * $this->subject is what SendConfiguredProcessMail copies from
         * email_configurations. Envelope hydration runs after that call and
         * would overwrite it, so the configured subject is honoured here and
         * the constant only covers the unconfigured case.
         */
        return new Envelope(
            subject: $this->subject ?: self::DEFAULT_SUBJECT,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.cash-register-closing',
            with: [
                'closings' => $this->closings,
                'filters'  => $this->filters,
                'total'    => $this->closings->count(),
            ],
        );
    }
}
