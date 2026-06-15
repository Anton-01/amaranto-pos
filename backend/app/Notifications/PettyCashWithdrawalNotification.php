<?php

namespace App\Notifications;

use App\Models\PettyCashTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PettyCashWithdrawalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private PettyCashTransaction $transaction)
    {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $pref = $notifiable->notificationPreferences()
            ->whereHas('notificationType', fn ($q) => $q->where('slug', 'petty_cash_withdrawal'))
            ->where('channel', 'mail')
            ->where('is_enabled', true)
            ->exists();

        if ($pref) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reasonLabels = [
            'provider_payment' => 'Pago a proveedor',
            'supplies_purchase' => 'Compra de insumos',
            'change_delivery' => 'Entrega de cambio',
            'emergency' => 'Emergencia',
        ];

        return (new MailMessage)
            ->subject('Alerta: Retiro de Caja Chica - Cronos POS')
            ->greeting('Retiro de Caja Chica Registrado')
            ->line("Operador: {$this->transaction->user->name}")
            ->line("Monto: \${$this->transaction->amount}")
            ->line("Motivo: " . ($reasonLabels[$this->transaction->reason] ?? $this->transaction->reason))
            ->line("Descripcion: {$this->transaction->description}")
            ->line("Fecha: {$this->transaction->created_at->format('d/m/Y H:i:s')}")
            ->action('Ver Auditoria', url('/petty-cash'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'reason' => $this->transaction->reason,
            'description' => $this->transaction->description,
            'operator_name' => $this->transaction->user->name,
            'created_at' => $this->transaction->created_at->toIso8601String(),
        ];
    }
}
