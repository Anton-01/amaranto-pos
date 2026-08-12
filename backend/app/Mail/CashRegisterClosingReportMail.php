<?php

namespace App\Mail;

use App\Models\GlobalSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Formal cash closing report delivered as a self-contained email.
 *
 * The message carries no attachment: the body itself is the document, headed
 * by the company's fiscal identity (legal name, RFC and address) taken from
 * the `fiscal_data` global setting. It also reports a single figure — the
 * total registered by the shift. Reconciliation of physical cash against that
 * total happens during the next shift's audit, not here, so no declared /
 * difference arithmetic is performed or displayed.
 */
class CashRegisterClosingReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Used when no subject was configured for the sending process. */
    public const DEFAULT_SUBJECT = 'Cronos POS - Reporte de Cierre de Caja';

    /**
     * @param  array<string, float>  $paymentBreakdown  Amount registered per payment method label.
     */
    public function __construct(
        public string $closingId,
        public string $operatorName,
        public string $closingDate,
        public float $totalAmount,
        public array $paymentBreakdown = [],
        public bool $isAutomated = false,
    ) {
    }

    public function envelope(): Envelope
    {
        /*
         * $this->subject is the value SendConfiguredProcessMail copies from
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
        // Fiscal identity is resolved at render time, not at construction:
        // the Mailable travels through the queue, and the report must show the
        // company data in force when it is actually sent.
        $fiscal = GlobalSetting::where('key', 'fiscal_data')->value('value') ?? [];

        return new Content(
            view: 'mail.cash-register-closing-report',
            with: [
                'folio' => strtoupper(substr($this->closingId, 0, 8)),
                'operatorName' => $this->operatorName,
                'closingDate' => $this->closingDate,
                'totalAmount' => $this->totalAmount,
                'paymentBreakdown' => $this->paymentBreakdown,
                'isAutomated' => $this->isAutomated,
                'fiscal' => is_array($fiscal) ? $fiscal : [],
            ],
        );
    }
}
