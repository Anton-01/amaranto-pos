<?php

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * Raised when an upload is refused by the dynamic whitelist.
 *
 * It carries a machine-readable reason alongside the sentence shown to the
 * operator, because the three refusal causes need different reactions: an
 * unknown extension is a request for the administrator, a disabled one is a
 * deliberate policy in force, and an oversized file is the user's to fix.
 */
class DisallowedFileTypeException extends RuntimeException
{
    /** The extension has no row at all in `allowed_file_types`. */
    public const REASON_UNKNOWN = 'ERR_MEDIA_TYPE_NOT_REGISTERED';

    /** A row exists but its kill switch is off. */
    public const REASON_INACTIVE = 'ERR_MEDIA_TYPE_DISABLED';

    /** The file exceeds the effective ceiling of its type. */
    public const REASON_TOO_LARGE = 'ERR_MEDIA_FILE_TOO_LARGE';

    /** Extension and MIME type disagree — a renamed file. */
    public const REASON_MIME_MISMATCH = 'ERR_MEDIA_MIME_MISMATCH';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function unknown(string $extension): self
    {
        return new self(
            self::REASON_UNKNOWN,
            "El tipo de archivo \".{$extension}\" no está registrado en el catálogo de tipos permitidos. "
                .'Solicita a un administrador que lo dé de alta en Configuración → Tipos de Archivo.',
            ['extension' => $extension],
        );
    }

    public static function inactive(string $extension): self
    {
        return new self(
            self::REASON_INACTIVE,
            "El tipo de archivo \".{$extension}\" está desactivado por política del sistema. "
                .'Un administrador debe reactivarlo antes de poder subir archivos de este tipo.',
            ['extension' => $extension],
        );
    }

    public static function tooLarge(string $extension, int $sizeKb, int $limitKb): self
    {
        return new self(
            self::REASON_TOO_LARGE,
            "El archivo pesa {$sizeKb} KB y el límite configurado para \".{$extension}\" es de {$limitKb} KB.",
            ['extension' => $extension, 'size_kb' => $sizeKb, 'limit_kb' => $limitKb],
        );
    }

    public static function mimeMismatch(string $extension, string $expected, string $received): self
    {
        return new self(
            self::REASON_MIME_MISMATCH,
            "El contenido del archivo no corresponde a la extensión \".{$extension}\". "
                ."Se esperaba \"{$expected}\" y el archivo declara \"{$received}\".",
            ['extension' => $extension, 'expected_mime' => $expected, 'received_mime' => $received],
        );
    }
}
