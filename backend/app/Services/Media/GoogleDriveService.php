<?php

namespace App\Services\Media;

use App\Exceptions\Media\GoogleDriveException;
use App\Models\DriveCredential;
use App\Models\MediaFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Business-facing façade over the Drive REST client.
 *
 * The client below knows HTTP; this class knows the module's rules — where a
 * file must land, who may read it once it is there, and what "configured
 * correctly" means. Everything above it (controllers, the library service)
 * talks to this class and never builds a Drive request by hand.
 */
class GoogleDriveService
{
    public function __construct(private readonly GoogleDriveClient $client) {}

    /**
     * Pushes bytes to Drive and returns the stored object's metadata, already
     * hardened.
     *
     * The order is deliberate: upload first, then lock the permissions, then
     * report. Drive creates a file owned by the service account and not shared
     * with anyone, so the window between the two calls is not a public window —
     * the hardening step exists to remove any inherited grant and to add the
     * explicitly authorized corporate readers, not to close a hole.
     *
     * @return array{drive_file_id: string, drive_folder_id: string, visibility: string, permissions: array<int, array<string, mixed>>}
     *
     * @throws GoogleDriveException
     */
    public function upload(
        DriveCredential $credential,
        string $contents,
        string $storageName,
        string $mimeType,
        string $category,
    ): array {
        $folderId = $this->resolveTargetFolder($credential, $category);

        $uploaded = $this->client->uploadFile($credential, $contents, [
            'name' => $storageName,
            'parents' => [$folderId],
            // Drive's own description doubles as an origin marker: anybody
            // browsing the folder in the Drive UI can tell at a glance that
            // the object is owned by the POS and not by a person.
            'description' => 'Cronos POS — Biblioteca de Medios ('.$category.')',
        ], $mimeType);

        $driveFileId = $uploaded['id'] ?? null;

        if (! is_string($driveFileId) || $driveFileId === '') {
            throw new GoogleDriveException(
                'Google no devolvió el identificador del archivo subido.',
                null,
                ['stage' => 'upload'],
            );
        }

        $hardening = $this->hardenPermissions($credential, $driveFileId);

        return [
            'drive_file_id' => $driveFileId,
            'drive_folder_id' => $folderId,
            'visibility' => $hardening['visibility'],
            'permissions' => $hardening['permissions'],
        ];
    }

    /**
     * Enforces this module's privacy contract on one object.
     *
     * WHAT IT REMOVES. Every grant of type `anyone` (Drive's "anyone with the
     * link") and every grant of type `domain` (visible to a whole Workspace
     * domain). Those are the two shapes that make a file readable by somebody
     * who simply walks the folder structure — the exact leak this module was
     * asked to prevent.
     *
     * WHAT IT KEEPS. The service account's own ownership, and any explicit
     * per-user grant. Deleting an owner permission is refused by Drive anyway,
     * and deleting a corporate reader we ourselves granted would fight the
     * administrator's configuration.
     *
     * WHAT IT ADDS. A reader grant for each address in `authorized_emails`, so
     * the accounting team can open the file with their own Google identity
     * without the POS ever making it public.
     *
     * WHY IT DOES NOT THROW ON A FAILED REVOCATION. A single permission that
     * refuses to disappear must not abort an upload whose bytes are already in
     * Drive — that would leave an orphan object with no library row. The
     * failure is logged and reflected in the returned visibility, so the file
     * is reported for what it is instead of being silently trusted.
     *
     * @return array{visibility: string, permissions: array<int, array<string, mixed>>, removed: int, granted: int}
     *
     * @throws GoogleDriveException when the permission list itself is unreadable.
     */
    public function hardenPermissions(DriveCredential $credential, string $driveFileId): array
    {
        $permissions = $this->client->listPermissions($credential, $driveFileId);
        $removed = 0;
        $publicRemains = false;

        foreach ($permissions as $permission) {
            $type = $permission['type'] ?? null;

            if (! in_array($type, ['anyone', 'domain'], true)) {
                continue;
            }

            $permissionId = $permission['id'] ?? null;

            if (! is_string($permissionId) || $permissionId === '') {
                $publicRemains = true;

                continue;
            }

            try {
                $this->client->deletePermission($credential, $driveFileId, $permissionId);
                $removed++;
            } catch (Throwable $e) {
                $publicRemains = true;

                Log::warning('No se pudo revocar un permiso público en Google Drive.', [
                    'drive_file_id' => $driveFileId,
                    'permission_id' => $permissionId,
                    'permission_type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $granted = 0;

        foreach ($credential->grantableEmails() as $email) {
            try {
                $this->client->grantReader($credential, $driveFileId, $email);
                $granted++;
            } catch (Throwable $e) {
                // A single unreachable corporate account (a typo, a suspended
                // Workspace user) is a configuration problem, not a reason to
                // fail an upload that already succeeded.
                Log::warning('No se pudo otorgar lectura a una cuenta autorizada de Drive.', [
                    'drive_file_id' => $driveFileId,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            // A file whose public grant could not be removed is NOT reported as
            // private. Overstating the privacy of an object is worse than
            // admitting the hardening was partial.
            'visibility' => $publicRemains
                ? MediaFile::VISIBILITY_RESTRICTED
                : ($granted > 0 ? MediaFile::VISIBILITY_RESTRICTED : MediaFile::VISIBILITY_PRIVATE),
            'permissions' => $this->client->listPermissions($credential, $driveFileId),
            'removed' => $removed,
            'granted' => $granted,
        ];
    }

    /**
     * Folder a file of a given category belongs to.
     *
     * The library is laid out as `<root>/<Categoría>/<AAAA-MM>`: browsing the
     * raw Drive folder in a support call stays comprehensible, and no single
     * folder accumulates the tens of thousands of children that make Drive's
     * own listing crawl.
     *
     * @throws GoogleDriveException
     */
    private function resolveTargetFolder(DriveCredential $credential, string $category): string
    {
        $categoryFolder = $this->client->ensureFolder(
            $credential,
            ucfirst($category),
            (string) $credential->root_folder_id,
        );

        return $this->client->ensureFolder(
            $credential,
            Carbon::now()->format('Y-m'),
            $categoryFolder,
        );
    }

    /** @throws GoogleDriveException */
    public function rename(DriveCredential $credential, string $driveFileId, string $name): void
    {
        $this->client->updateFileMetadata($credential, $driveFileId, ['name' => $name]);
    }

    /** @throws GoogleDriveException */
    public function download(DriveCredential $credential, string $driveFileId): string
    {
        return $this->client->downloadFile($credential, $driveFileId);
    }

    /** @throws GoogleDriveException */
    public function trash(DriveCredential $credential, string $driveFileId): void
    {
        $this->client->trashFile($credential, $driveFileId);
    }

    /** @throws GoogleDriveException */
    public function untrash(DriveCredential $credential, string $driveFileId): void
    {
        $this->client->untrashFile($credential, $driveFileId);
    }

    /**
     * Synchronous health check of a connection, run against credentials that
     * may not be persisted yet.
     *
     * WHY IT VERIFIES THREE THINGS AND NOT ONE. Minting a token proves the key
     * signs and the account exists — and nothing more. The two failures that
     * actually break this module in production are a root folder id that does
     * not exist, and a root folder that exists but was never SHARED with the
     * service account; both mint a token happily and then fail on the first
     * upload, hours later, with a 404 or a 403 nobody connects back to the
     * setup screen. So the check also reads the folder and asserts
     * `canAddChildren`.
     *
     * The folder read is the step that most often fails with Google's least
     * helpful answer. Drive does not distinguish "this id does not exist" from
     * "this id is outside your token's reach": both are `404 File not found`.
     * That is why a 404 here is not repeated verbatim but expanded into the
     * checklist of causes — sharing grant, id shape, OAuth scope — together
     * with the folders the account can actually see.
     *
     * It never throws: it returns a diagnostic array for every outcome, so the
     * controller can answer 422 with the provider's own words instead of
     * turning a misconfiguration into a 500.
     *
     * @return array{success: bool, checks: array<string, mixed>, error: array<string, mixed>|null, elapsed_ms: int}
     */
    public function testConnection(DriveCredential $credential): array
    {
        $startedAt = microtime(true);
        $checks = [
            'credentials_complete' => false,
            'token_minted' => false,
            'root_folder_reachable' => false,
            'root_folder_writable' => false,
        ];

        $missing = $credential->missingPieces();

        if ($missing !== []) {
            return [
                'success' => false,
                'checks' => $checks,
                'error' => [
                    'message' => 'Faltan datos obligatorios: '.implode(', ', $missing).'.',
                    'stage' => 'validation',
                    'status_code' => null,
                ],
                'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ];
        }

        $checks['credentials_complete'] = true;

        try {
            // An unsaved model has no id to key the cache with, and a saved one
            // may be carrying a token minted by the key being replaced. Either
            // way the check must exercise the credential in front of it, so the
            // cached token is dropped first.
            $credential->forgetAccessToken();

            $this->client->accessToken($credential);
            $checks['token_minted'] = true;

            $folder = $this->client->getFile(
                $credential,
                (string) $credential->root_folder_id,
                'id,name,mimeType,trashed,capabilities(canAddChildren)',
            );

            $checks['root_folder_reachable'] = true;
            $checks['root_folder_name'] = $folder['name'] ?? null;

            if (($folder['mimeType'] ?? null) !== 'application/vnd.google-apps.folder') {
                return $this->testFailure(
                    $checks,
                    'El ID indicado no corresponde a una carpeta de Drive, sino a un archivo.',
                    'root_folder',
                    $startedAt,
                );
            }

            if (($folder['trashed'] ?? false) === true) {
                return $this->testFailure(
                    $checks,
                    'La carpeta raíz está en la papelera de Google Drive. Restáurala antes de operar la biblioteca.',
                    'root_folder',
                    $startedAt,
                );
            }

            if (($folder['capabilities']['canAddChildren'] ?? false) !== true) {
                return $this->testFailure(
                    $checks,
                    'La cuenta de servicio ('.$credential->client_email.') puede ver la carpeta pero no escribir en ella. '
                        .'Compártela con esa dirección con permiso de Editor desde Google Drive.',
                    'root_folder',
                    $startedAt,
                );
            }

            $checks['root_folder_writable'] = true;
        } catch (GoogleDriveException $e) {
            return $this->reportDriveFailure($credential, $checks, $e, $startedAt);
        } catch (Throwable $e) {
            Log::error('Fallo inesperado al probar la conexión con Google Drive.', [
                'client_email' => $credential->client_email,
                'root_folder_id' => $credential->root_folder_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return $this->testFailure($checks, $e->getMessage(), 'unexpected', $startedAt);
        }

        return [
            'success' => true,
            'checks' => $checks,
            'error' => null,
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * Turns a Drive failure raised during the health check into a diagnosis an
     * administrator can act on, and leaves a full record of it in the log.
     *
     * The two statuses worth translating are the ones that arrive with the
     * root folder configured and the token already minted, because Google's own
     * wording actively misleads there:
     *
     * - 404 "File not found" does NOT mean the id does not exist. Drive reports
     *   every object outside the token's reach as absent, so a folder shared
     *   with the service account an hour ago still reads as 404 when the OAuth
     *   scope cannot see externally shared objects, or when the grant landed on
     *   a different address than the one signing these requests.
     * - 403 means the folder IS visible but this account may not write into it.
     *
     * @param  array<string, mixed>  $checks
     * @return array{success: bool, checks: array<string, mixed>, error: array<string, mixed>, elapsed_ms: int}
     */
    private function reportDriveFailure(
        DriveCredential $credential,
        array $checks,
        GoogleDriveException $e,
        float $startedAt,
    ): array {
        // The client stamps a stage only on the token and signing paths; a
        // failure that got past a minted token is by construction the folder
        // read, which is the whole rest of this check.
        $stage = $e->context['stage'] ?? ($checks['token_minted'] === true ? 'root_folder' : 'drive');

        $message = $e->getMessage();
        $visibleFolders = [];

        if ($stage === 'root_folder' && $e->statusCode === 404) {
            $visibleFolders = $this->client->listAccessibleFolders($credential);
            $message = $this->explainUnreachableRootFolder($credential, $e, $visibleFolders);
        }

        if ($stage === 'root_folder' && $e->statusCode === 403) {
            $message = 'Google Drive negó el acceso (403) a la carpeta raíz "'.$credential->root_folder_id.'". '
                .'La cuenta de servicio ('.$credential->client_email.') alcanza la carpeta pero no tiene el '
                .'permiso que la operación necesita. Compártela con esa dirección con rol de Editor. '
                .'Respuesta de Google: '.$e->getMessage();
        }

        Log::error('Prueba de conexión con Google Drive fallida.', [
            'stage' => $stage,
            'status_code' => $e->statusCode,
            // Google's reason code separates a genuinely wrong id from an
            // unreachable one; both surface as HTTP 404.
            'google_reason' => $e->context['reason'] ?? null,
            'google_message' => $e->getMessage(),
            'client_email' => $credential->client_email,
            'root_folder_id' => $credential->root_folder_id,
            // Recorded on every failure because it is the single setting that
            // decides whether an externally shared folder is visible at all,
            // and it is invisible from the panel.
            'oauth_scope' => config('media.drive.scope'),
            'shared_drive_params' => 'supportsAllDrives=true, includeItemsFromAllDrives=true, corpora=allDrives',
            'folders_visible_to_service_account' => $visibleFolders,
            'drive_path' => $e->context['path'] ?? null,
        ]);

        return $this->testFailure($checks, $message, $stage, $startedAt, $e->statusCode);
    }

    /**
     * The 404 message, written as the checklist the administrator has to walk.
     *
     * Ordered by how often each cause is the real one, and it names the account
     * and the scope in force because neither is visible from the settings
     * panel — an operator cannot check a sharing grant against an address they
     * have not been told.
     *
     * @param  array<int, array{id: string, name: string}>  $visibleFolders
     */
    private function explainUnreachableRootFolder(
        DriveCredential $credential,
        GoogleDriveException $e,
        array $visibleFolders,
    ): string {
        $scope = (string) config('media.drive.scope');
        $narrowScope = (string) config('media.drive.narrow_scope');

        $message = 'Google Drive respondió 404 (File not found) al leer la carpeta raíz "'
            .$credential->root_folder_id.'". Drive reporta como inexistente cualquier objeto que el token '
            .'no alcanza, así que un 404 aquí casi nunca significa que el ID esté mal escrito. Revisa, en '
            .'este orden: '
            .'1) que la carpeta esté compartida con '.$credential->client_email.' con permiso de Editor '
            .'(compartir la carpeta padre no alcanza si la carpeta se movió después de compartirse); '
            .'2) que el ID sea el tramo que sigue a /folders/ en la URL de la carpeta, sin parámetros ni '
            .'"?usp=sharing"; '
            .'3) que la carpeta no haya sido eliminada o movida a otra unidad por su propietario.';

        if ($scope === $narrowScope) {
            $message .= ' AVISO: el alcance OAuth en uso es '.$narrowScope.', que solo alcanza archivos '
                .'creados por esta aplicación y NUNCA verá una carpeta compartida desde fuera, por más '
                .'permisos de Editor que tenga la cuenta. Para una carpeta compartida externamente el '
                .'alcance debe ser https://www.googleapis.com/auth/drive (variable MEDIA_DRIVE_SCOPE).';
        }

        if ($visibleFolders === []) {
            $message .= ' La cuenta de servicio no ve NINGUNA carpeta en Drive en este momento, lo que '
                .'apunta a que el paso de compartir nunca llegó a aplicarse sobre esta cuenta.';
        } else {
            $names = collect($visibleFolders)
                ->map(fn (array $folder) => $folder['name'].' ('.$folder['id'].')')
                ->implode(', ');

            $message .= ' Carpetas que esta cuenta SÍ alcanza: '.$names.'.';
        }

        return $message.' Respuesta literal de Google: '.$e->getMessage();
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array{success: bool, checks: array<string, mixed>, error: array<string, mixed>, elapsed_ms: int}
     */
    private function testFailure(
        array $checks,
        string $message,
        string $stage,
        float $startedAt,
        ?int $statusCode = null,
    ): array {
        return [
            'success' => false,
            'checks' => $checks,
            'error' => [
                'message' => $message,
                'stage' => $stage,
                'status_code' => $statusCode,
            ],
            'elapsed_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ];
    }
}
