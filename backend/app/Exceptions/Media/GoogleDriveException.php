<?php

namespace App\Exceptions\Media;

use RuntimeException;
use Throwable;

/**
 * A failure that came back from Google, carrying Google's own words.
 *
 * The provider's message is preserved verbatim on purpose. "Invalid grant"
 * against a service account means clock skew or a revoked key; a 403
 * "insufficientFilePermissions" means the root folder was never shared with
 * the account; a 404 on upload means the folder id is wrong. Paraphrasing any
 * of these into a friendly sentence destroys the only information the
 * administrator came for.
 */
class GoogleDriveException extends RuntimeException
{
    public const ERROR_CODE = 'ERR_MEDIA_DRIVE_FAILED';

    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
