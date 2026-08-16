<?php

namespace App\Exceptions\Mail;

use App\Models\EmailConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a business process asks to send mail and no active configuration
 * backs it.
 *
 * WHY IT IS A 422 AND NOT A 500. The request was well formed and the
 * application did exactly what it was asked; what is missing is a row an
 * administrator has to create in Ajustes → Notificaciones / Emails. That is a
 * fact about the state of the payload's process, not an application incident,
 * and keeping it out of the 5xx band means a tenant that never configured
 * mailing does not page anybody. It matches the contract already used by the
 * connection health check (section 59.5).
 *
 * WHY THE MANUAL PATH RAISES WHILE THE QUEUE JOB STAYS SILENT.
 * SendConfiguredProcessMail returns quietly when the row is missing, because
 * nobody is watching an automated closing and "notifications are off" is a
 * valid state there. A human who just clicked "Enviar por Correo" is watching,
 * so the same condition has to travel back inside the request instead of dying
 * in a worker log the operator cannot open.
 */
class MailConfigurationMissingException extends RuntimeException
{
    /** Machine-readable contract the frontend switches on to show its warning. */
    public const ERROR_CODE = 'ERR_MAIL_CONFIG_MISSING';

    public const DEFAULT_MESSAGE = 'No existe una configuración de correo activa para este proceso. '
        .'Por favor, da de alta la configuración en el panel de Ajustes.';

    public function __construct(public readonly string $processType, ?string $message = null)
    {
        parent::__construct($message ?? self::DEFAULT_MESSAGE);
    }

    /** Human label of the process, so the warning can name what is unconfigured. */
    public function processLabel(): string
    {
        return EmailConfiguration::PROCESS_TYPES[$this->processType] ?? $this->processType;
    }

    /** Rendered by Laravel automatically; the API answers with the corporate error catalog. */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => self::ERROR_CODE,
            'message' => $this->getMessage(),
            'errors' => [],
            'metadata' => [
                'process_type' => $this->processType,
                'process_label' => $this->processLabel(),
            ],
        ], 422);
    }
}
