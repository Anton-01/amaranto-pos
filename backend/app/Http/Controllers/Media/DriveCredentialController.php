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
 * The connection is an OAuth 2.0 user-context grant: a client id, a client
 * secret and a refresh token issued by the owner of the Drive. Every response
 * passes through the model's $hidden rules, so the secret and the refresh token
 * never leave the server. The panel works with the `has_*` booleans, the client
 * id and the authorizing account's email instead — enough to recognize which
 * connection is loaded, useless for impersonating it.
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
                    'Client ID de OAuth',
                    'Client Secret de OAuth',
                    'Refresh Token',
                    'ID de la carpeta raíz en Drive',
                ],
                // Echoed so the panel can tell the administrator exactly which
                // Drive permission this application asks Google for. It is the
                // setting that decides whether a folder the owner created by
                // hand is visible at all, and a 404 on the connection test is
                // unreadable without knowing it.
                'scope' => config('media.drive.scope'),
                'supports_manual_folders' => config('media.drive.scope')
                    !== config('media.drive.narrow_scope'),
            ],
        ]);
    }

    /**
     * Creates or replaces the active connection.
     *
     * An empty `client_secret` or `refresh_token` means "keep the stored
     * value" — the form is normally submitted that way, because the API never
     * returns either of them. Rotating requires actually pasting a new value,
     * which prevents an accidental save from wiping a working connection while
     * editing the folder id or the reader list.
     *
     * Both are written through the model's `encrypted` cast, so what reaches
     * PostgreSQL is ciphertext under APP_KEY. The client id is stored in the
     * clear on purpose: it is a public identifier of the OAuth application,
     * shown back in the panel, and useless without the pair it belongs to.
     */
    public function store(StoreDriveCredentialRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $credential = DriveCredential::active() ?? new DriveCredential();

        $credential->fill([
            'label' => $payload['label'] ?? ($credential->label ?: 'Google Drive'),
            'client_id' => $payload['client_id'],
            'root_folder_id' => $payload['root_folder_id'],
            'authorized_emails' => $payload['authorized_emails'] ?? ($credential->authorized_emails ?? []),
            'is_active' => $request->boolean('is_active', true),
            'updated_by' => $request->user()->id,
        ]);

        if (filled($payload['client_secret'] ?? null)) {
            $credential->client_secret = $payload['client_secret'];
        }

        if (filled($payload['refresh_token'] ?? null)) {
            $credential->refresh_token = $payload['refresh_token'];
        }

        /*
         * A refresh token is bound to the OAuth client that obtained it, so
         * pointing an existing connection at a different client id leaves it
         * holding a grant no longer redeemable — Google answers `invalid_grant`
         * and the panel would blame the token. Forgetting the stored account
         * and the stored health check is what stops the UI from asserting an
         * identity that has not been proven against the new pair.
         */
        if ($credential->isDirty('client_id')) {
            $credential->account_email = null;
            $credential->last_tested_at = null;
            $credential->last_test_status = null;
            $credential->last_test_message = null;
        }

        $credential->save();

        /*
         * A rotated grant leaves the previous access token alive inside its
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
                // The identity is recorded, the secrets are not. This row is
                // read by auditors; it must not become a second copy of the
                // credential. Only whether each secret was replaced is stored,
                // which is what makes a rotation auditable without exposing it.
                'client_id' => $credential->client_id,
                'account_email' => $credential->account_email,
                'root_folder_id' => $credential->root_folder_id,
                'authorized_emails' => $credential->grantableEmails(),
                'client_secret_rotated' => filled($payload['client_secret'] ?? null),
                'refresh_token_rotated' => filled($payload['refresh_token'] ?? null),
                'is_active' => $credential->is_active,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $credential->fresh('updatedByUser'),
            'metadata' => [
                'message' => 'Credenciales de OAuth de Google Drive guardadas de forma cifrada.',
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
     * form cannot repopulate — the client secret and the refresh token — fall
     * back to what is stored.
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
            'client_id' => $payload['client_id'] ?? $stored?->client_id,
            'root_folder_id' => $payload['root_folder_id'] ?? $stored?->root_folder_id,
            'authorized_emails' => $payload['authorized_emails'] ?? ($stored?->authorized_emails ?? []),
        ]);

        // Assigned outside the fill() so a blank field falls back to the stored
        // secret instead of blanking it: the panel cannot repopulate either of
        // these, so "empty" always means "use what is saved".
        $candidate->client_secret = filled($payload['client_secret'] ?? null)
            ? $payload['client_secret']
            : $stored?->client_secret;

        $candidate->refresh_token = filled($payload['refresh_token'] ?? null)
            ? $payload['refresh_token']
            : $stored?->refresh_token;

        $candidate->account_email = $stored?->account_email;

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
                'client_id' => $candidate->client_id,
                'account_email' => $candidate->account_email,
                'root_folder_id' => $candidate->root_folder_id,
            ],
            $request->user(),
        );

        // The outcome is persisted on the stored row so the panel can state the
        // connection's condition on load, instead of implying everything is
        // fine until somebody presses the button again.
        if ($stored) {
            $stored->forceFill([
                // The check resolves the authorizing account from Drive itself.
                // Writing it back is what lets the panel name WHO the connection
                // speaks as — the one fact a refresh token does not reveal, and
                // the usual cause of a 404 on a folder id that is perfectly
                // valid for somebody else.
                'account_email' => $candidate->account_email ?? $stored->account_email,
                'last_tested_at' => now(),
                'last_test_status' => $result['success'] ? 'success' : 'failed',
                'last_test_message' => $result['success']
                    ? 'Conexión verificada correctamente.'
                    : ($result['error']['message'] ?? 'La prueba falló sin mensaje del proveedor.'),
            ])->save();
        }

        if (! $result['success']) {
            Log::warning('Prueba de conexión con Google Drive fallida.', [
                'client_id' => $candidate->client_id,
                'account_email' => $candidate->account_email,
                'root_folder_id' => $candidate->root_folder_id,
                'stage' => $result['error']['stage'] ?? null,
                'status_code' => $result['error']['status_code'] ?? null,
                'error' => $result['error']['message'] ?? null,
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_DRIVE_TEST_FAILED',
                // Google's own words, verbatim where they are useful: an
                // `invalid_grant` and a "File not found" call for different
                // fixes, and a friendly paraphrase would erase the distinction.
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
                // The authorizing account is named in the success message
                // because it is the part of a healthy setup an administrator
                // cannot see from the panel, and the part that silently breaks
                // if that person ever revokes the application's access.
                'message' => 'Conexión verificada: el Refresh Token autentica como '
                    .($result['checks']['account_email'] ?? 'la cuenta autorizada')
                    .', la carpeta raíz es alcanzable y se puede escribir en ella.',
            ],
        ]);
    }

    /**
     * Disables the connection without destroying it.
     *
     * The row survives so the audit trail keeps a resolvable reference to the
     * account that uploaded the existing library, and so re-enabling does not
     * require generating a new refresh token.
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
