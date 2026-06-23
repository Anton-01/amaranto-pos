<?php

namespace App\Mail;

use App\Models\GlobalSetting;
use App\Models\PettyCashTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PettyCashWithdrawalMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private static array $reasonLabels = [
        'provider_payment' => 'Pago a proveedor',
        'supplies_purchase' => 'Compra de insumos',
        'change_delivery' => 'Entrega de cambio',
        'emergency' => 'Emergencia',
    ];

    public function __construct(
        public PettyCashTransaction $transaction,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cronos POS - Movimiento de Caja Chica',
        );
    }

    public function content(): Content
    {
        $fiscal = GlobalSetting::where('key', 'fiscal_data')->first();
        $snapshot = $this->transaction->immutable_snapshot ?? [];

        return new Content(
            view: 'mail.petty-cash-withdrawal',
            with: [
                'amount' => (float) $this->transaction->amount,
                'reasonLabel' => self::$reasonLabels[$this->transaction->reason] ?? $this->transaction->reason,
                'description' => $this->transaction->description,
                'operatorName' => $snapshot['operator_name'] ?? $this->transaction->user?->name ?? 'N/A',
                'operatorEmail' => $snapshot['operator_email'] ?? $this->transaction->user?->email ?? 'N/A',
                'timestamp' => $this->transaction->created_at->format('d/m/Y H:i:s'),
                'sealHash' => $snapshot['security_seal'] ?? null,
                'footerFiscal' => $fiscal?->value,
            ],
        );
    }
}
