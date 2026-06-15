<?php

namespace App\Listeners;

use App\Events\PettyCashTransactionRegistered;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\PettyCashWithdrawalNotification;

class NotifyPettyCashWithdrawal
{
    public function handle(PettyCashTransactionRegistered $event): void
    {
        $notificationType = NotificationType::where('slug', 'petty_cash_withdrawal')->first();

        if (! $notificationType) {
            return;
        }

        $allowedRoles = $notificationType->allowed_roles ?? ['admin'];

        $users = User::whereHas('roles', function ($q) use ($allowedRoles) {
            $q->whereIn('name', $allowedRoles);
        })
        ->whereHas('notificationPreferences', function ($q) use ($notificationType) {
            $q->where('notification_type_id', $notificationType->id)
              ->where('is_enabled', true);
        })
        ->get();

        $event->transaction->load('user');

        foreach ($users as $user) {
            $user->notify(new PettyCashWithdrawalNotification($event->transaction));
        }
    }
}
