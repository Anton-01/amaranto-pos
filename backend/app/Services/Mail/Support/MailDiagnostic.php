<?php

namespace App\Services\Mail\Support;

use App\Exceptions\Mail\MailTransportException;
use Throwable;

/**
 * Result of a connection test, in the one shape every strategy answers with.
 *
 * The endpoint that consumes it has a single job — hand the administrator the
 * real reason the mail did not leave — so the failure branch carries more
 * information than the success one: the provider's verbatim message, the
 * exception class, the status code and a summarized stack trace. Without those
 * four, "no se pudo conectar" is indistinguishable from a blocked port, an
 * expired key and an unverified sender address.
 *
 * WHAT NEVER TRAVELS. The API key is not part of this object and the trace is
 * built from file, line, class and method only: PHP's raw trace embeds call
 * arguments, and those arguments are the credential. The summary is capped at
 * a handful of frames because the answer is read inside a toast, not a
 * debugger.
 */
final class MailDiagnostic
{
    /** Frames kept from the exception trace: enough to locate, short enough to read. */
    private const TRACE_FRAMES = 6;

    /**
     * @param  array<string, mixed>  $transport
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $hints
     */
    private function __construct(
        private readonly bool $success,
        private readonly string $provider,
        private readonly string $strategy,
        private readonly array $transport,
        private readonly array $recipients,
        private readonly int $elapsedMs,
        private readonly array $hints = [],
        private readonly ?Throwable $exception = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $transport
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $hints
     */
    public static function success(
        string $provider,
        string $strategy,
        array $transport,
        array $recipients,
        float $startedAt,
        array $hints = [],
    ): self {
        return new self(
            true,
            $provider,
            $strategy,
            $transport,
            $recipients,
            self::elapsedSince($startedAt),
            $hints,
        );
    }

    /**
     * @param  array<string, mixed>  $transport
     * @param  array<int, string>  $recipients
     * @param  array<int, string>  $hints
     */
    public static function failure(
        string $provider,
        string $strategy,
        array $transport,
        array $recipients,
        float $startedAt,
        Throwable $exception,
        array $hints = [],
    ): self {
        return new self(
            false,
            $provider,
            $strategy,
            $transport,
            $recipients,
            self::elapsedSince($startedAt),
            $hints,
            $exception,
        );
    }

    public function succeeded(): bool
    {
        return $this->success;
    }

    /** Provider message, verbatim, or null when nothing failed. */
    public function errorMessage(): ?string
    {
        return $this->exception?->getMessage();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'success' => $this->success,
            'provider' => $this->provider,
            'strategy' => $this->strategy,
            'transport' => $this->transport,
            'recipients' => $this->recipients,
            'elapsed_ms' => $this->elapsedMs,
            'hints' => $this->hints,
        ];

        if ($this->exception !== null) {
            $payload['error'] = $this->errorPayload($this->exception);
        }

        return $payload;
    }

    /**
     * Everything a human needs to act on the failure, and nothing a machine
     * could use to impersonate the account.
     *
     * @return array<string, mixed>
     */
    private function errorPayload(Throwable $e): array
    {
        $payload = [
            'message' => $e->getMessage(),
            'class' => class_basename($e),
            'code' => $e->getCode(),
            'trace' => $this->summarizeTrace($e),
        ];

        if ($e instanceof MailTransportException) {
            $payload['status_code'] = $e->statusCode();
            $payload['provider_code'] = $e->providerCode();
        }

        /*
         * Transport failures are usually wrapped: Symfony reports "Connection
         * could not be established" and keeps the operating system's own
         * "Connection timed out" underneath. The inner message is the one that
         * separates a firewall from a wrong host, so it is kept.
         */
        if (($previous = $e->getPrevious()) !== null) {
            $payload['previous'] = [
                'message' => $previous->getMessage(),
                'class' => class_basename($previous),
            ];
        }

        return $payload;
    }

    /**
     * Trace reduced to "file:line Class::method" lines.
     *
     * @return array<int, string>
     */
    private function summarizeTrace(Throwable $e): array
    {
        $frames = [sprintf('%s:%d (origen)', basename($e->getFile()), $e->getLine())];

        foreach (array_slice($e->getTrace(), 0, self::TRACE_FRAMES) as $frame) {
            $frames[] = sprintf(
                '%s:%s %s%s%s()',
                isset($frame['file']) ? basename($frame['file']) : '[interno]',
                $frame['line'] ?? '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '?',
            );
        }

        return $frames;
    }

    private static function elapsedSince(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
