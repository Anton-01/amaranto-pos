<?php

namespace App\Mail;

use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserPasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cronos POS - Restauracion de Contrasena',
        );
    }

    public function content(): Content
    {
        $fiscal = GlobalSetting::where('key', 'fiscal_data')->first();

        return new Content(
            view: 'mail.password-reset',
            with: [
                'userName' => $this->user->name,
                'resetUrl' => $this->resetUrl,
                'footerFiscal' => $fiscal?->value,
            ],
        );
    }
}
