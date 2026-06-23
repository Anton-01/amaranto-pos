<?php

namespace App\Mail;

use App\Models\GlobalSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LowStockAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $products,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->products);

        return new Envelope(
            subject: "Cronos POS - Alerta de Stock Critico ({$count} producto" . ($count !== 1 ? 's' : '') . ")",
        );
    }

    public function content(): Content
    {
        $fiscal = GlobalSetting::where('key', 'fiscal_data')->first();

        return new Content(
            view: 'mail.low-stock-alert',
            with: [
                'products' => $this->products,
                'footerFiscal' => $fiscal?->value,
            ],
        );
    }
}
