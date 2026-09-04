<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreDriveCredentialRequest;
use App\Http\Requests\Media\TestDriveConnectionRequest;
use App\Models\DriveCredential;
use App\Models\MediaAuditLog;
use App\Services\Media\GoogleDriveService;
use App\Services\Media\MediaAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Administration of the Google Drive connection.
 *
 * Every response passes through the model's $hidden rules, so the service
 * account JSON, the private key and the client secret never leave the server.
 * The panel works with the `has_*` booleans and the client email instead —
 * enough to recognize which account is loaded, useless for impersonating it.
 */
class DriveCredentialController extends Controller
{
    public function __construct(private readonly MediaAuditLogger $audit) {}

    /**
     * Current connection and its real condition.
     *
     * The payload states what is missing rather than answering a bare
     * "configured: true/false": a row with a key but no root folder is the most
     * common half-configured state, and it fails at the first upload with an
     * error that points nowhere near this screen.
     */
    public function show(): JsonResponse
    {
        $credential = DriveCredential::active();

        return response()->json([
            'status' => 'success',
            'data' => $credential?->load('updatedByUser:id,name'),
            'metadata' => [
                'is_configured' => (bool) $credential?->isUsable(),
                'missing' => $credential?->missingPieces() ?? [
                    'Correo de la Service Account',
                    'Llave privada (JSON de la Service Account)',
                    'ID de la carpeta raíz en Drive',
                ],
                // Echoed so the panel can tell the administrator exactly which
                // Drive permission the service account is asking Google for.
                // It is the setting that decides whether a root folder shared
                // from somebody else's Drive is visible at all, and a 404 on
                // the connection test is unreadable without knowing it.
                'scope' => config('media.drive.scope'),
                'supports_external_shared_folders' => config('media.drive.scope')
                    !== config('media.drive.narrow_scope'),
            ],
        ]);
    }

    /**
     * Creates or replaces the active connection.
     *
     * An empty `service_account_json` means "keep the stored credential" — the
     * form is normally submitted that way, because the API never returns the
     * document. Rotating requires actually pasting a new one, which prevents an
     * accidental save from wiping a working connection while editing the reader
     * list.
     */
    public function store(StoreDriveCredentialRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $credential = DriveCredential::active() ?? new DriveCredential();

        if (filled($payload['service_account_json'] ?? null)) {
            if (! $credential->fillFromServiceAccountJson($payload['service_account_json'])) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'ERR_MEDIA_DRIVE_INVALID_JSON',
                    'message' => 'El JSON no corresponde a una Service Account de Google: '
                        .'debe contener al menos "client_email" y "private_key". '
                        .'Pega el archivo tal como lo descargaste de Google Cloud.',
                ], 422);
            }
        }

        $credential->fill([
            'label' => $payload['label'] ?? ($credential->label ?: 'Google Drive'),
            'root_folder_id' => $payload['root_folder_id'],
            'authorized_emails' => $payload['authorized_emails'] ?? ($credential->authorized_emails ?? []),
            'is_active' => $request->boolean('is_active', true),
            'updated_by' => $request->user()->id,
        ]);

        if (filled($payload['client_id'] ?? null)) {
            $credential->client_id = $payload['client_id'];
        }

        if (filled($payload['client_secret'] ?? null)) {
            $credential->client_secret = $payload['client_secret'];
        }

        $credential->save();

        /*
         * A rotated key leaves the previous access token alive inside its
         * one-hour window. Dropping it here is what makes a revocation take
         * effect at once instead of "sometime within the hour" — the exact
         * class of bug that makes a security control untrustworthy.
         */
        $credential->forgetAccessToken();

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_CREDENTIALS_UPDATED,
            $credential->label,
            [
                'drive_credential_id' => $credential->id,
                // The identity is recorded, the secret is not. This row is
                // read by auditors; it must not become a second copy of the
                // credential.
                'client_email' => $credential->client_email,
                'project_id' => $credential->project_id,
                'root_folder_id' => $credential->root_folder_id,
                'authorized_emails' => $credential->grantableEmails(),
                'service_account_rotated' => filled($payload['service_account_json'] ?? null),
                'is_active' => $credential->is_active,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $credential->fresh('updatedByUser'),
            'metadata' => [
                'message' => 'Credenciales de Google Drive guardadas de forma cifrada.',
                'is_configured' => $credential->isUsable(),
                'missing' => $credential->missingPieces(),
            ],
        ]);
    }

    /**
     * Synchronous health check of the connection.
     *
     * WHY SYNCHRONOUS. The question the button asks is "do these credentials
     * work?", and only a failure that travels back inside the request can
     * answer it. A queued check would return 200 the instant Redis accepted the
     * job and hide the real failure in a worker log the administrator cannot
     * open.
     *
     * WHY IT ACCEPTS AN UNSAVED PAYLOAD. It builds an in-memory model from what
     * is typed in the form, so a credential can be validated BEFORE being
     * persisted, through the exact code path production uses. The fields the
     * form cannot repopulate — the service account JSON — fall back to what is
     * stored.
     *
     * WHY 422 AND NOT 500 ON FAILURE. The request was well formed and the
     * application did exactly what it was asked; what failed is the
     * configuration under test. It also keeps a mistyped folder id out of the
     * platform's 5xx alerting.
     */
    public function testConnection(TestDriveConnectionRequest $request, GoogleDriveService $drive): JsonResponse
    {
        $payload = $request->validated();
        $stored = filled($payload['credential_id'] ?? null)
            ? DriveCredential::find($payload['credential_id'])
            : DriveCredential::active();

        $candidate = new DriveCredential([
            'label' => $stored?->label ?? 'Google Drive',
            'root_folder_id' => $payload['root_folder_id'] ?? $stored?->root_folder_id,
            'authorized_emails' => $payload['authorized_emails'] ?? ($stored?->authorized_emails ?? []),
        ]);

        if (filled($payload['service_account_json'] ?? null)) {
            if (! $candidate->fillFromServiceAccountJson($payload['service_account_json'])) {
                return response()->json([
                    'status' => 'error',
                    'code' => 'ERR_MEDIA_DRIVE_INVALID_JSON',
                    'message' => 'El JSON no corresponde a una Service Account de Google '
                        .'(faltan "client_email" o "private_key").',
                ], 422);
            }
        } elseif ($stored) {
            $candidate->client_email = $stored->client_email;
            $candidate->private_key = $stored->private_key;
            $candidate->project_id = $stored->project_id;
        }

        // Only a persisted row owns a cache entry; giving the candidate the
        // stored id makes the check invalidate and exercise the real one.
        $candidate->id = $stored?->id ?? 'candidate';

        $result = $drive->testConnection($candidate);

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_CREDENTIALS_TESTED,
            $candidate->label,
            [
                'success' => $result['success'],
                'checks' => $result['checks'],
                'error' => $result['error']['message'] ?? null,
                'elapsed_ms' => $result['elapsed_ms'],
                'client_email' => $candidate->client_email,
                'root_folder_id' => $candidate->root_folder_id,
            ],
            $request->user(),
        );

        // The outcome is persisted on the stored row so the panel can state the
        // connection's condition on load, instead of implying everything is
        // fine until somebody presses the button again.
        if ($stored) {
            $stored->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => $result['success'] ? 'success' : 'failed',
                'last_test_message' => $result['success']
                    ? 'Conexión verificada correctamente.'
                    : ($result['error']['message'] ?? 'La prueba falló sin mensaje del proveedor.'),
            ])->save();
        }

        if (! $result['success']) {
            Log::warning('Prueba de conexión con Google Drive fallida.', [
                'client_email' => $candidate->client_email,
                'root_folder_id' => $candidate->root_folder_id,
                'stage' => $result['error']['stage'] ?? null,
                'status_code' => $result['error']['status_code'] ?? null,
                'error' => $result['error']['message'] ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_DRIVE_TEST_FAILED',
                // Google's own words, verbatim: an "insufficientFilePermissions"
                // and a "File not found" call for different fixes, and a
                // friendly paraphrase would erase the distinction.
                'message' => $result['error']['message'] ?? 'La prueba de conexión falló.',
                'data' => [
                    'checks' => $result['checks'],
                    'stage' => $result['error']['stage'] ?? null,
                    'elapsed_ms' => $result['elapsed_ms'],
                ],
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'checks' => $result['checks'],
                'elapsed_ms' => $result['elapsed_ms'],
            ],
            'metadata' => [
                'message' => 'Conexión verificada: la cuenta de servicio autentica y puede escribir en la carpeta raíz.',
            ],
        ]);
    }

    /**
     * Disables the connection without destroying it.
     *
     * The row survives so the audit trail keeps a resolvable reference to the
     * account that uploaded the existing library, and so re-enabling does not
     * require pasting the JSON again.
     */
    public function destroy(Request $request): JsonResponse
    {
        $credential = DriveCredential::active();

        if (! $credential) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_DRIVE_NOT_CONFIGURED',
                'message' => 'No hay una conexión activa con Google Drive.',
            ], 404);
        }

        $credential->update(['is_active' => false, 'updated_by' => $request->user()->id]);
        $credential->forgetAccessToken();

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_CREDENTIALS_UPDATED,
            $credential->label,
            ['drive_credential_id' => $credential->id, 'is_active' => false, 'deactivated' => true],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Conexión con Google Drive desactivada. '
                .'La biblioteca dejará de aceptar subidas hasta reactivarla.'],
        ]);
    }
}
