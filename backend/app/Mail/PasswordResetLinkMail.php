<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cronos POS — Restauracion de Contraseña',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $name = e($this->user->name);
        $url = e($this->resetUrl);

        return <<<HTML
        <div style="font-family: 'Inter', Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px; background: #f8fafc;">
            <div style="background: white; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.08);">
                <div style="text-align: center; margin-bottom: 24px;">
                    <div style="display: inline-block; background: #4f46e5; border-radius: 8px; padding: 8px 12px;">
                        <span style="color: white; font-weight: bold; font-size: 16px;">CRONOS POS</span>
                    </div>
                </div>
                <h2 style="color: #1e293b; font-size: 18px; margin-bottom: 12px;">Hola, {$name}</h2>
                <p style="color: #64748b; font-size: 14px; line-height: 1.6;">
                    Un administrador ha solicitado la restauracion de tu contraseña. Utiliza el siguiente enlace para establecer una nueva contraseña:
                </p>
                <div style="text-align: center; margin: 24px 0;">
                    <a href="{$url}" style="display: inline-block; background: #4f46e5; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px;">
                        Restaurar Contraseña
                    </a>
                </div>
                <p style="color: #94a3b8; font-size: 12px; text-align: center;">
                    Este enlace expira en 4 horas. Si no solicitaste este cambio, ignora este correo.
                </p>
            </div>
        </div>
        HTML;
    }
}
