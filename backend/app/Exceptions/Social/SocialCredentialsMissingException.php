<?php

namespace App\Exceptions\Social;

use App\Models\SocialAccount;
use RuntimeException;

/**
 * The requested network has no usable connection stored.
 *
 * Thrown BEFORE any HTTP call, and it names what is absent. Letting a
 * half-configured connection reach Graph would trade this sentence for Meta's
 * "Unsupported post request", which tells an administrator nothing about the
 * empty column that caused it.
 */
class SocialCredentialsMissingException extends RuntimeException
{
    public const ERROR_CODE = 'ERR_SOCIAL_CREDENTIALS_MISSING';

    /** @param  array<int, string>  $missing */
    public function __construct(
        public readonly string $provider,
        public readonly array $missing = [],
    ) {
        $label = SocialAccount::PROVIDERS[$provider] ?? $provider;

        $message = $missing === []
            ? "No hay una conexión activa de {$label}. Configúrala antes de publicar."
            : "La conexión de {$label} está incompleta. Falta: ".implode(', ', $missing).'.';

        parent::__construct($message);
    }
}
