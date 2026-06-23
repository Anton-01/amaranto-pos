<?php

namespace App\Mail;

use App\Models\GlobalSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CashRegisterClosingReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $closingId,
        public string $operatorName,
        public string $closingDate,
        public float $expectedAmount,
        public float $declaredAmount,
        public float $differenceAmount,
        public array $paymentBreakdown = [],
        public ?string $pdfPath = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cronos POS - Reporte de Cierre de Caja',
        );
    }

    public function content(): Content
    {
        $fiscal = GlobalSetting::where('key', 'fiscal_data')->first();

        return new Content(
            view: 'mail.cash-register-closing-report',
            with: [
                'operatorName' => $this->operatorName,
                'closingDate' => $this->closingDate,
                'expectedAmount' => $this->expectedAmount,
                'declaredAmount' => $this->declaredAmount,
                'differenceAmount' => $this->differenceAmount,
                'paymentBreakdown' => $this->paymentBreakdown,
                'footerFiscal' => $fiscal?->value,
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                Attachment::fromPath($this->pdfPath)
                    ->as('arqueo-caja-' . strtoupper(substr($this->closingId, 0, 8)) . '.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
