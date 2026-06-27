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

class UserWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cronos POS - Bienvenido al equipo',
        );
    }

    public function content(): Content
    {
        $fiscal = GlobalSetting::where('key', 'fiscal_data')->first();

        return new Content(
            view: 'mail.user-welcome',
            with: [
                'userName' => $this->user->name,
                'userEmail' => $this->user->email,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => config('app.frontend_url', url('/login')),
                'footerFiscal' => $fiscal?->value,
            ],
        );
    }
}
