<?php

namespace App\Exceptions\Social;

use RuntimeException;
use Throwable;

/**
 * A failure that came back from the Meta Graph API, carrying Meta's own words.
 *
 * The provider's message and numeric code are preserved verbatim on purpose,
 * exactly as `GoogleDriveException` does for Drive. Each one is a different
 * repair: 190 is an expired or revoked token, 200 is a missing
 * `pages_manage_posts` permission, 100 with subcode 2207003 means Meta could
 * not fetch the image URL, 4 and 32 are rate limits that resolve on their own.
 * Paraphrasing any of them into a friendly sentence destroys the only
 * information the administrator came for.
 */
class MetaGraphException extends RuntimeException
{
    public const ERROR_CODE = 'ERR_SOCIAL_META_GRAPH_FAILED';

    /** @param  array<string, mixed>  $context */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $graphCode = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
