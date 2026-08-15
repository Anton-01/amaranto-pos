<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailConfiguration\StoreEmailConfigurationRequest;
use App\Http\Requests\EmailConfiguration\TestEmailConnectionRequest;
use App\Http\Requests\EmailConfiguration\UpdateEmailConfigurationRequest;
use App\Mail\TestConnectionMail;
use App\Models\EmailConfiguration;
use App\Services\Mail\DynamicMailerFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Administration of the per-process mailing credentials.
 *
 * Every response goes through the model's $hidden/$appends rules, so the raw
 * API key never leaves the server: the UI only ever sees whether a credential
 * exists and its last four characters.
 */
class EmailConfigurationController extends Controller
{
    public function index(): JsonResponse
    {
        $configurations = EmailConfiguration::with('updatedByUser:id,name')
            ->orderBy('process_type')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $configurations,
        ]);
    }

    /** Catalogs consumed by the admin form (process types and providers). */
    public function catalogs(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'process_types' => collect(EmailConfiguration::PROCESS_TYPES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'providers' => collect(EmailConfiguration::PROVIDERS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
            ],
        ]);
    }

    public function store(StoreEmailConfigurationRequest $request): JsonResponse
    {
        $configuration = EmailConfiguration::create([
            ...$request->validated(),
            'target_emails' => $this->normalizeEmails($request->input('target_emails', [])),
            'is_active' => $request->boolean('is_active', true),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $configuration->fresh('updatedByUser'),
            'metadata' => ['message' => 'Configuracion de correo creada correctamente.'],
        ], 201);
    }

    public function update(
        UpdateEmailConfigurationRequest $request,
        EmailConfiguration $emailConfiguration
    ): JsonResponse {
        $payload = $request->validated();

        // Blank credential field = "leave the stored key untouched". Rotating
        // requires actually typing a new key, which prevents an accidental save
        // from wiping working credentials.
        if (blank($payload['api_key'] ?? null)) {
            unset($payload['api_key']);
        }

        if (array_key_exists('target_emails', $payload)) {
            $payload['target_emails'] = $this->normalizeEmails($payload['target_emails']);
        }

        $payload['updated_by'] = $request->user()->id;

        $emailConfiguration->update($payload);

        return response()->json([
            'status' => 'success',
            'data' => $emailConfiguration->fresh('updatedByUser'),
            'metadata' => ['message' => 'Configuracion de correo actualizada correctamente.'],
        ]);
    }

    /** Inline kill switch used by the status toggle of the admin table. */
    public function toggleStatus(Request $request, EmailConfiguration $emailConfiguration): JsonResponse
    {
        $emailConfiguration->update([
            'is_active' => ! $emailConfiguration->is_active,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $emailConfiguration->fresh('updatedByUser'),
            'metadata' => [
                'message' => $emailConfiguration->is_active
                    ? 'Configuracion activada.'
                    : 'Configuracion desactivada.',
            ],
        ]);
    }

    public function destroy(EmailConfiguration $emailConfiguration): JsonResponse
    {
        $emailConfiguration->delete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Configuracion de correo eliminada.'],
        ]);
    }

    /**
     * Mailing health check: dials the provider now and reports what happened.
     *
     * WHAT it does. It takes the credentials currently typed in the admin form
     * — not what the database holds — assembles the very same transport the
     * queue would assemble, and pushes one message through it. A 200 means the
     * socket opened, the provider accepted the API key and the sender identity
     * passed; anything else comes back with the raw transport error.
     *
     * WHY it is synchronous, when production mail is not. Every business
     * Mailable implements ShouldQueue and leaves through
     * SendConfiguredProcessMail, because a cash closing must never be held
     * hostage by SMTP latency, and because retries with backoff absorb
     * transient provider failures. That is right for delivery and useless for
     * diagnosis: a queued test would return HTTP 200 the instant Redis accepted
     * the payload — reporting that the message was *enqueued*, never that it
     * was *delivered* — and the real failure would land minutes later in a
     * worker log the administrator cannot open. Answering the question the
     * button asks ("do these credentials work?") requires the exception to
     * travel back inside the request, so this is the one place in the system
     * that calls send() instead of queue().
     *
     * HOW the failure is surfaced. The whole attempt is wrapped in try/catch
     * over Throwable and the provider's own message is returned verbatim.
     * Symfony's TransportException carries the diagnosis in its text — an
     * "Operation timed out" against port 2525 means the host firewall, a 401
     * means a revoked key — so paraphrasing it into a friendly sentence would
     * destroy the only piece of information the administrator came for.
     */
    public function testConnection(TestEmailConnectionRequest $request, DynamicMailerFactory $factory): JsonResponse
    {
        $payload = $request->validated();

        $apiKey = filled($payload['api_key'] ?? null)
            ? $payload['api_key']
            : $this->storedApiKeyFor($payload['configuration_id'] ?? null);

        if (blank($apiKey)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Escribe la API Key del proveedor para poder probar la conexion.',
                'error_code' => 'ERR_MAIL_TEST_NO_CREDENTIAL',
            ], 400);
        }

        /*
         * An unsaved model is the payload's carrier. The factory already knows
         * how to merge a configuration over the provider skeleton — including
         * the anti-block port 2525 — and it never asks whether the row exists,
         * so an in-memory instance lets an administrator validate credentials
         * BEFORE persisting them, through the exact same code path production
         * uses. Reimplementing the Config::set() calls here would create a
         * second transport builder that could drift from the real one, which is
         * precisely the bug this tool is meant to catch.
         */
        $configuration = new EmailConfiguration([
            'process_type' => $payload['process_type'],
            'provider' => $payload['provider'],
            'api_key' => $apiKey,
            'from_email' => $payload['from_email'],
            'from_name' => $payload['from_name'],
            'target_emails' => $this->normalizeEmails($payload['target_emails']),
        ]);

        $recipients = $configuration->deliverableEmails();

        try {
            $mailerName = $factory->register($configuration);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'error_code' => 'ERR_MAIL_TRANSPORT_UNSUPPORTED',
            ], 400);
        }

        // Read back what was injected so the answer describes the route that
        // was actually dialled. The credential is dropped here and never
        // reaches the response.
        $transport = Arr::only(
            (array) config("mail.mailers.{$mailerName}"),
            ['transport', 'host', 'port', 'encryption']
        );

        $startedAt = microtime(true);

        try {
            Mail::mailer($mailerName)
                ->to($recipients)
                ->send(
                    (new TestConnectionMail($payload['process_type'], $transport))
                        ->from($payload['from_email'], $payload['from_name'])
                );
        } catch (Throwable $e) {
            /*
             * Logged without the credential: the payload is echoed back to the
             * browser and written to the log, and neither is a place for an API
             * key. The exception class is kept because it separates a transport
             * problem from an authentication one at a glance.
             */
            Log::warning('Prueba de conexion de correo fallida.', [
                'process_type' => $payload['process_type'],
                'provider' => $payload['provider'],
                'transport' => $transport,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'error_code' => 'ERR_MAIL_TEST_FAILED',
                'error_class' => class_basename($e),
                'data' => [
                    'transport' => $transport,
                    'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ],
            ], 400);
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('Prueba de conexion de correo exitosa.', [
            'process_type' => $payload['process_type'],
            'provider' => $payload['provider'],
            'transport' => $transport,
            'recipients' => count($recipients),
            'elapsed_ms' => $elapsedMs,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'transport' => $transport,
                'recipients' => $recipients,
                'elapsed_ms' => $elapsedMs,
            ],
            'metadata' => [
                'message' => 'Conexion exitosa. Se envio un correo de prueba a '
                    .count($recipients).' destinatario(s).',
            ],
        ]);
    }

    /**
     * Credential stored for a saved configuration, used when the form submits
     * an empty key field — which is its normal state while editing, since the
     * API never returns the key to the browser.
     */
    private function storedApiKeyFor(?string $configurationId): ?string
    {
        if (blank($configurationId)) {
            return null;
        }

        return EmailConfiguration::find($configurationId)?->api_key;
    }

    /**
     * Trims and de-duplicates the recipient list before it reaches the column,
     * so the stored JSON is already the list the dispatcher will iterate.
     *
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private function normalizeEmails(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
