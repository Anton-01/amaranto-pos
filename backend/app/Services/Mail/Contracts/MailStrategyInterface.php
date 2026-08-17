<?php

namespace App\Services\Mail\Contracts;

use Illuminate\Mail\Mailable;

/**
 * Contract every outbound mail provider implements (Strategy pattern).
 *
 * WHY A STRATEGY AND NOT A SWITCH. Each provider fails in its own way and is
 * diagnosed with its own vocabulary: an SMTP relay answers with a socket that
 * never opens, an HTTP API answers with a 401 and a JSON body. Folding all of
 * them into one procedure produced a method whose branches only shared the word
 * "send", and adding Resend to it meant editing the code path that SendGrid
 * depends on. Here every provider owns one class, the classes never see each
 * other, and the caller — the queue job or the health check endpoint — talks to
 * this interface without knowing which one answered.
 *
 * THE $config ARRAY is the provider-agnostic payload, produced by
 * App\Models\EmailConfiguration::toStrategyConfig(): process_type, provider,
 * api_key, from_email, from_name, subject and the already-validated
 * target_emails. Passing an array rather than the model is deliberate: the
 * health check tests credentials that were never saved, so a strategy must
 * never assume a row exists.
 *
 * TIMEOUTS ARE PART OF THE CONTRACT. Every implementation is required to bound
 * the time it can spend inside the network, using config('mailing.timeouts'):
 * a strategy that can block for the PHP default of 60 seconds turns the
 * "Probar Conexion" button into a frozen browser tab, which is the failure this
 * layer was written to remove.
 */
interface MailStrategyInterface
{
    /** Provider key this strategy answers for ("smtp", "sendgrid", "resend"). */
    public function provider(): string;

    /**
     * Delivers a Mailable through this provider.
     *
     * Called from the queue worker, where blocking is acceptable and retries
     * exist: exceptions are allowed to bubble so the job can be retried with
     * backoff instead of silently swallowing a rejected message.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws \Throwable when the provider refuses the message.
     */
    public function send(Mailable $mail, array $config): bool;

    /**
     * Dials the provider now and reports what happened, without throwing.
     *
     * This is the diagnostic half of the contract, so failure is a normal
     * return value: the array always carries the resolved route, the elapsed
     * milliseconds and — when it failed — the provider's own error message,
     * class and summarized trace. Returning instead of throwing is what lets
     * the controller answer an HTTP 422 with the real cause in the body rather
     * than a generic 500.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> Shape produced by
     *                              App\Services\Mail\Support\MailDiagnostic.
     */
    public function testConnection(array $config): array;

    /**
     * Route this configuration would take, resolved without dialling anything.
     *
     * Read-only by contract: `php artisan app:debug-mail-config` prints it for
     * every stored row, so it must not mutate config('mail.mailers') nor open a
     * socket.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> channel, mailer, transport, host, port,
     *                              encryption and timeout.
     */
    public function describeTransport(array $config): array;
}
