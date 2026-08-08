<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailConfiguration\StoreEmailConfigurationRequest;
use App\Http\Requests\EmailConfiguration\UpdateEmailConfigurationRequest;
use App\Models\EmailConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
