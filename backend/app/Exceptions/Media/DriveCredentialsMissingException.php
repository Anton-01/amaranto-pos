<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * Raised when the media module is asked to reach Drive before an administrator
 * has configured a usable service account.
 *
 * It is a distinct type (and not a generic GoogleDriveException) because the
 * remedy is different: nothing is wrong with Google, the POS simply has no
 * identity to present, and the answer must send the administrator to the
 * credentials panel rather than to a provider status page.
 */
class DriveCredentialsMissingException extends RuntimeException
{
    public const ERROR_CODE = 'ERR_MEDIA_DRIVE_NOT_CONFIGURED';

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Google Drive no está configurado. '
            .'Registra las credenciales de la Service Account en Configuración → Google Drive '
            .'antes de operar la biblioteca de medios.');
    }
}
