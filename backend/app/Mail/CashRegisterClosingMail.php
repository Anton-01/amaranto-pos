<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CashRegisterClosingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $closings,
        public array $filters = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reporte de Cierres de Caja — Cronos POS',
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
