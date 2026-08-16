<?php

namespace App\Exceptions\Mail;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A mail provider refused the message, or the request never reached it.
 *
 * It exists so that an HTTP provider (Resend) fails with the same weight as an
 * SMTP one: Symfony throws TransportException when a relay rejects a message,
 * while a REST client happily returns a 401 response object that no exception
 * would ever be built from. Normalizing both into a thrown exception is what
 * lets the diagnostic layer treat every provider identically.
 *
 * The message is the provider's own text, never a paraphrase. "API key is
 * invalid" and "The from address is not verified" demand different fixes, and
 * a friendly "no se pudo enviar" erases the distinction the administrator
 * clicked the button to obtain.
 */
class MailTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?string $providerCode = null,
    ) {
        // The HTTP status doubles as the exception code so a var_dump of the
        // exception in a log still carries it.
        parent::__construct($message, $statusCode ?? 0);
    }

    /**
     * Builds the exception out of a failed HTTP API response.
     *
     * Providers describe the failure inside the JSON body under a handful of
     * competing key names, so every known shape is probed before falling back
     * to the raw body — an HTML error page from a proxy is still more useful
     * than an empty string.
     */
    public static function fromResponse(string $provider, Response $response): self
    {
        $body = $response->json();

        $detail = is_array($body)
            ? ($body['message']
                ?? $body['error']['message']
                ?? (is_string($body['error'] ?? null) ? $body['error'] : null)
                ?? null)
            : null;

        $detail ??= trim($response->body()) ?: 'sin cuerpo en la respuesta';

        $code = is_array($body) ? ($body['name'] ?? $body['type'] ?? null) : null;

        return new self(
            sprintf(
                '%s respondio HTTP %d: %s',
                $provider,
                $response->status(),
                is_string($detail) ? $detail : json_encode($detail)
            ),
            $response->status(),
            is_string($code) ? $code : null,
        );
    }

    /** HTTP status returned by the provider, when the failure had one. */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /** Machine-readable error name published by the provider, if any. */
    public function providerCode(): ?string
    {
        return $this->providerCode;
    }
}
