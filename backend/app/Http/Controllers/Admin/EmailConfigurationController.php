<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailConfiguration\StoreEmailConfigurationRequest;
use App\Http\Requests\EmailConfiguration\TestEmailConnectionRequest;
use App\Http\Requests\EmailConfiguration\UpdateEmailConfigurationRequest;
use App\Models\EmailConfiguration;
use App\Services\Mail\MailStrategyFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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

    /**
     * Catalogs consumed by the admin form (process types and providers).
     *
     * The provider list is intersected with the strategies actually registered
     * in config/mailing.php, so the dropdown can never offer a provider whose
     * class does not exist: an option the administrator can pick is an option
     * the system can send through.
     *
     * Each entry carries its outbound channel and port because that is the real
     * decision behind the selector — SMTP/2525 versus HTTPS/443 is a question
     * about the server's firewall, not about email features.
     */
    public function catalogs(MailStrategyFactory $strategies): JsonResponse
    {
        $supported = $strategies->supportedProviders();

        return response()->json([
            'status' => 'success',
            'data' => [
                'process_types' => collect(EmailConfiguration::PROCESS_TYPES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'providers' => collect(EmailConfiguration::PROVIDERS)
                    ->only($supported)
                    ->map(fn (string $label, string $value) => [
                        'value' => $value,
                        'label' => $label,
                        'channel' => config("mailing.providers.{$value}.driver") === 'http' ? 'https' : 'smtp',
                    ])
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
     * — not what the database holds — hands them to the strategy of the chosen
     * provider (SMTP, SendGrid or Resend) and pushes one real message through
     * it. A 200 means the route opened, the provider accepted the credential
     * and the sender identity passed; a 422 comes back with the provider's own
     * error text.
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
     * button asks ("do these credentials work?") requires the failure to travel
     * back inside the request.
     *
     * WHY IT CANNOT HANG FOR 60 SECONDS ANY MORE. The freeze was never a slow
     * provider: a VPS with a blocked submission port DROPS the packets, nothing
     * answers, and the socket waits for PHP's default 60-second timeout while
     * the browser spins and then gives up with no message at all. Each strategy
     * now bounds its own network time from config('mailing.timeouts') — an SMTP
     * transport timeout plus a scoped `default_socket_timeout`, a cURL
     * connect/read timeout for the HTTP one — so the worst case is a handful of
     * seconds ending in a readable error instead of a minute ending in silence.
     *
     * HOW the failure is surfaced. testConnection() never throws: it returns a
     * diagnostic array, and this method turns it into HTTP 422 carrying the
     * provider message verbatim, its exception class, its status code and a
     * summarized trace. Symfony's TransportException and Resend's JSON body
     * both put the diagnosis in their text — an "Operation timed out" against
     * port 587 means the host firewall, a 401 means a revoked key, a 403 means
     * an unverified sender domain — so paraphrasing them into a friendly
     * sentence would destroy the only information the administrator came for.
     */
    public function testConnection(TestEmailConnectionRequest $request, MailStrategyFactory $strategies): JsonResponse
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
         * An unsaved model is the payload's carrier: the strategies read a
         * plain array and never ask whether the row exists, so an in-memory
         * instance lets an administrator validate credentials BEFORE persisting
         * them, through the exact same code path production uses. Building a
         * second, test-only transport here would create a resolver able to
         * drift from the real one — precisely the bug this tool exists to
         * catch.
         */
        $configuration = new EmailConfiguration([
            'process_type' => $payload['process_type'],
            'provider' => $payload['provider'],
            'api_key' => $apiKey,
            'from_email' => $payload['from_email'],
            'from_name' => $payload['from_name'],
            'target_emails' => $this->normalizeEmails($payload['target_emails']),
        ]);

        try {
            $strategy = $strategies->for($configuration);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'error_code' => 'ERR_MAIL_STRATEGY_UNSUPPORTED',
            ], 400);
        }

        /*
         * The strategy owns the try/catch, so this call is total: it answers
         * with a result for every outcome, including the ones that used to
         * become an unhandled 500 (a DNS failure, a TLS handshake rejected
         * mid-negotiation). The outer catch below only covers a strategy that
         * breaks its own contract.
         */
        try {
            $result = $strategy->testConnection($configuration->toStrategyConfig());
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'provider' => $payload['provider'],
                'strategy' => class_basename($strategy),
                'transport' => [],
                'recipients' => $configuration->deliverableEmails(),
                'elapsed_ms' => 0,
                'error' => ['message' => $e->getMessage(), 'class' => class_basename($e), 'code' => $e->getCode()],
            ];
        }

        return $result['success'] === true
            ? $this->connectionSucceeded($payload, $result)
            : $this->connectionFailed($payload, $result);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $result
     */
    private function connectionSucceeded(array $payload, array $result): JsonResponse
    {
        Log::info('Prueba de conexion de correo exitosa.', [
            'process_type' => $payload['process_type'],
            'provider' => $payload['provider'],
            'strategy' => $result['strategy'] ?? null,
            'transport' => $result['transport'] ?? [],
            'recipients' => count($result['recipients'] ?? []),
            'elapsed_ms' => $result['elapsed_ms'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => Arr::except($result, ['success', 'error']),
            'metadata' => [
                'message' => 'Conexion exitosa. Se envio un correo de prueba a '
                    .count($result['recipients'] ?? []).' destinatario(s).',
            ],
        ]);
    }

    /**
     * Failure answer: HTTP 422 with the real technical error in the body.
     *
     * 422 and not 500 is deliberate. The request was well formed and the
     * application did exactly what it was asked; what failed is the
     * configuration being tested, which is a fact about the payload. It also
     * keeps the error out of the 5xx alerting of the platform: a wrong API key
     * typed by an administrator is not an application incident.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $result
     */
    private function connectionFailed(array $payload, array $result): JsonResponse
    {
        $error = $result['error'] ?? [];
        $message = $error['message'] ?? 'La prueba de conexion fallo sin un mensaje del proveedor.';

        /*
         * Logged without the credential: the payload is echoed back to the
         * browser and written to the log, and neither is a place for an API
         * key. The exception class is kept because it separates a transport
         * problem from an authentication one at a glance.
         */
        Log::warning('Prueba de conexion de correo fallida.', [
            'process_type' => $payload['process_type'],
            'provider' => $payload['provider'],
            'strategy' => $result['strategy'] ?? null,
            'transport' => $result['transport'] ?? [],
            'exception' => $error['class'] ?? null,
            'status_code' => $error['status_code'] ?? null,
            'elapsed_ms' => $result['elapsed_ms'] ?? null,
            'error' => $message,
        ]);

        return response()->json([
            'status' => 'error',
            // `message` is what the UI shows; `error` is the key the API
            // contract names for the raw provider text. Both hold the same
            // verbatim string on purpose — no paraphrase, no truncation.
            'message' => $message,
            'error' => $message,
            'error_code' => 'ERR_MAIL_TEST_FAILED',
            'error_class' => $error['class'] ?? null,
            'data' => Arr::except($result, ['success']),
        ], 422);
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
