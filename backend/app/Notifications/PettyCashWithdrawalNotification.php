<?php

namespace App\Notifications;

use App\Mail\PettyCashWithdrawalMail;
use App\Models\PettyCashTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

    public function toMail(object $notifiable): PettyCashWithdrawalMail
    {
        return (new PettyCashWithdrawalMail($this->transaction))->to($notifiable->email);
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
