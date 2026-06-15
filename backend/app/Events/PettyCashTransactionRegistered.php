<?php

namespace App\Events;

use App\Models\PettyCashTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PettyCashTransactionRegistered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public PettyCashTransaction $transaction)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('petty-cash-alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'transaction.registered';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'reason' => $this->transaction->reason,
            'description' => $this->transaction->description,
            'operator' => $this->transaction->user->name,
            'created_at' => $this->transaction->created_at->toIso8601String(),
        ];
    }
}
