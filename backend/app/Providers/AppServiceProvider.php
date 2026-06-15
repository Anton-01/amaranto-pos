<?php

namespace App\Providers;

use App\Events\PettyCashTransactionRegistered;
use App\Listeners\NotifyPettyCashWithdrawal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Event::listen(
            PettyCashTransactionRegistered::class,
            NotifyPettyCashWithdrawal::class
        );
    }
}
