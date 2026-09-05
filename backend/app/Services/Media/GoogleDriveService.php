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
 *
 * THE STORAGE MODEL IS A SHARED DRIVE, AND THAT IS NOT A PREFERENCE. A service
 * account is an identity with no storage quota of its own. Every object it
 * creates needs an owner who pays for the bytes, and in an ordinary My Drive
 * folder that owner is the service account itself — so Drive answers
 * `403 [storageQuotaExceeded]` on the first real upload, no matter how
 * correctly the folder was shared and no matter which request parameters are
 * sent. Inside a shared drive the drive owns its contents and the organization
 * pays for them, and the same upload succeeds. That is why this class checks
 * for a `driveId` on the root folder before declaring a connection healthy,
 * and why it translates that specific 403 into the one instruction that fixes
 * it instead of repeating Google's wording.
 */
class GoogleDriveService
{
    /**
     * Google's reason code for "the owner of these bytes has no room left".
     * For a service account that is not a transient condition to retry, it is
     * a permanent property of the identity — see translateQuotaFailure().
     */
    private const REASON_QUOTA_EXCEEDED = 'storageQuotaExceeded';

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
     * @return array{drive_file_id: string, drive_folder_id: string, shared_drive_id: string|null, visibility: string, permissions: array<int, array<string, mixed>>}
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

        try {
            $uploaded = $this->client->uploadFile($credential, $contents, [
                'name' => $storageName,
                'parents' => [$folderId],
                // Drive's own description doubles as an origin marker: anybody
                // browsing the folder in the Drive UI can tell at a glance that
                // the object is owned by the POS and not by a person.
                'description' => 'Cronos POS — Biblioteca de Medios ('.$category.')',
            ], $mimeType);
        } catch (GoogleDriveException $e) {
            throw $this->translateQuotaFailure($credential, $e);
        }

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
            // Present only for objects inside a shared drive. Recorded on the
            // way out so a support call can confirm the storage model from the
            // upload's own receipt rather than from a re-read.
            'shared_drive_id' => is_string($uploaded['driveId'] ?? null) ? $uploaded['driveId'] : null,
            'visibility' => $hardening['visibility'],
            'permissions' => $hardening['permissions'],
        ];
    }

    /**
     * Rewrites the quota 403 into the instruction that actually resolves it.
     *
     * Google's own words here are `The user's Drive storage quota has been
     * exceeded`, which sends an administrator to check a Drive that is 2% full
     * and to widen sharing grants that were never the problem. The real
     * statement is narrower and unintuitive: the service account has NO quota,
     * has never had any, and cannot be given any — the bytes have to belong to
     * a shared drive instead.
     *
     * Any other Drive failure is passed through untouched: paraphrasing a
     * `notFound` or a permissions error into a storage explanation would be
     * exactly the kind of misdirection this method exists to remove.
     */
    private function translateQuotaFailure(DriveCredential $credential, GoogleDriveException $e): GoogleDriveException
    {
        if (($e->context['reason'] ?? null) !== self::REASON_QUOTA_EXCEEDED) {
            return $e;
        }

        Log::error('Subida rechazada por cuota: la carpeta destino no pertenece a una unidad compartida.', [
            'client_email' => $credential->client_email,
            'root_folder_id' => $credential->root_folder_id,
            'root_shared_drive_id' => $this->client->driveIdOf($credential, (string) $credential->root_folder_id),
            'google_message' => $e->getMessage(),
        ]);

        return new GoogleDriveException(
            'Google Drive rechazó la subida por cuota de almacenamiento (403 storageQuotaExceeded). '
                .'La cuenta de servicio ('.$credential->client_email.') no posee almacenamiento propio, así que '
                .'cualquier archivo que cree dentro de una carpeta de "Mi unidad" queda a nombre de una cuenta con '
                .'cero bytes disponibles — compartir la carpeta con permiso de Editor no lo cambia. '
                .'Solución: crea una Unidad compartida en Google Drive, agrega a '.$credential->client_email
                .' como Administrador de contenido o Colaborador, mueve la carpeta raíz de la biblioteca dentro de '
                .'esa unidad y actualiza el ID de la carpeta raíz. Dentro de una unidad compartida los archivos '
                .'pertenecen a la unidad y consumen la cuota de la organización, no la de la cuenta de servicio.',
            $e->statusCode,
            $e->context + ['stage' => 'upload', 'remedy' => 'shared_drive'],
            $e,
        );
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
     * WHAT IT CANNOT REMOVE, AND SAYS SO. A grant INHERITED from the shared
     * drive belongs to the drive, not to the file: Drive refuses to delete it
     * from a child and the fix is a change to the drive's own sharing settings,
     * which is outside this application's authority. Attempting the deletion
     * anyway would spend a request to earn a 403 and would report the same
     * outcome less clearly, so an inherited public grant is skipped, logged
     * with what has to change and where, and counted against the file's
     * reported visibility.
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

            if (($permission['permissionDetails'][0]['inherited'] ?? false) === true) {
                $publicRemains = true;

                Log::warning(
                    'Permiso público heredado de la Unidad compartida: no se puede revocar desde el archivo.',
                    [
                        'drive_file_id' => $driveFileId,
                        'permission_id' => $permissionId,
                        'permission_type' => $type,
                        'remedy' => 'Cambia el acceso general de la Unidad compartida en Google Drive: '
                            .'mientras la unidad completa sea pública, todo archivo nuevo nacerá público.',
                    ],
                );

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
     * WHY IT VERIFIES FOUR THINGS AND NOT ONE. Minting a token proves the key
     * signs and the account exists — and nothing more. The three failures that
     * actually break this module in production are a root folder id that does
     * not exist, a root folder that exists but was never SHARED with the
     * service account, and a root folder that is shared correctly but sits in
     * somebody's My Drive; all three mint a token happily and then fail on the
     * first upload, hours later, with a 404 or a 403 nobody connects back to
     * the setup screen. So the check also reads the folder, asserts
     * `canAddChildren`, and asserts that the folder belongs to a shared drive.
     *
     * THE SHARED DRIVE ASSERTION IS THE ONE THAT CANNOT BE INFERRED LATER. A My
     * Drive folder shared with the service account passes every other check in
     * this method — it is reachable, it is writable, subfolders can even be
     * created inside it, because a folder costs zero bytes. It fails only when
     * the first real file is uploaded, with `403 [storageQuotaExceeded]`,
     * because the service account owns no quota and there is no request
     * parameter that changes who owns a new object. Reading `driveId` here is
     * what moves that failure from the operator's first upload to the setup
     * screen where it can be fixed.
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
            'root_folder_in_shared_drive' => false,
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
                'id,name,mimeType,trashed,driveId,capabilities(canAddChildren)',
            );

            $checks['root_folder_reachable'] = true;
            $checks['root_folder_name'] = $folder['name'] ?? null;
            $checks['shared_drive_id'] = is_string($folder['driveId'] ?? null) ? $folder['driveId'] : null;

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

            if (blank($checks['shared_drive_id'])) {
                $checks['root_folder_in_shared_drive'] = false;

                // Strict by default, because in every deployment shape this
                // module supports the next upload of real bytes WILL fail. The
                // escape hatch exists only for an installation that has proven
                // otherwise — a service account with delegated impersonation,
                // say — and must not be flipped to make a red panel go green.
                if ((bool) config('media.drive.require_shared_drive', true)) {
                    return $this->testFailure(
                        $checks,
                        $this->explainMyDriveRootFolder($credential),
                        'shared_drive',
                        $startedAt,
                    );
                }
            } else {
                $checks['root_folder_in_shared_drive'] = true;
            }
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
            // The two 403s arrive at the same place and need opposite fixes:
            // one is about who may touch the folder, the other about who pays
            // for the bytes. Google's HTTP status does not separate them; its
            // reason code does.
            $message = ($e->context['reason'] ?? null) === self::REASON_QUOTA_EXCEEDED
                ? $this->explainMyDriveRootFolder($credential).' Respuesta de Google: '.$e->getMessage()
                : 'Google Drive negó el acceso (403) a la carpeta raíz "'.$credential->root_folder_id.'". '
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
            // Null here means the root folder is NOT in a shared drive, which
            // is the quota failure waiting to happen; it is recorded on every
            // failure because it reframes several of them.
            'root_shared_drive_id' => $checks['shared_drive_id'] ?? null,
            'folders_visible_to_service_account' => $visibleFolders,
            'drive_path' => $e->context['path'] ?? null,
        ]);

        return $this->testFailure($checks, $message, $stage, $startedAt, $e->statusCode);
    }

    /**
     * The message for a root folder that is configured, shared, and still
     * unusable, because it lives in a My Drive instead of a shared drive.
     *
     * It is written as a procedure rather than as a diagnosis on purpose. The
     * finding itself ("the service account has no storage quota") is true but
     * unactionable to somebody looking at a Drive with 2 TB free, and the
     * instinctive next moves — widening the sharing grant, upgrading the
     * Workspace plan, emptying the trash — all fail. The only fix is to change
     * WHERE the folder lives, so the message spells out those four steps.
     */
    private function explainMyDriveRootFolder(DriveCredential $credential): string
    {
        $visibleFolders = $this->client->listAccessibleFolders($credential);

        $sharedDriveFolders = collect($visibleFolders)
            ->filter(fn (array $folder) => ($folder['shared_drive'] ?? false) === true)
            ->map(fn (array $folder) => $folder['name'].' ('.$folder['id'].')')
            ->implode(', ');

        // The sentence deliberately does not claim the folder is writable. This
        // message is also used for a quota 403 raised before that was
        // established, and a diagnosis that asserts something it did not verify
        // is how an administrator stops trusting the whole panel.
        $message = 'La carpeta raíz "'.$credential->root_folder_id.'" NO pertenece a una Unidad compartida: '
            .'está en la "Mi unidad" de una persona. '
            .'Una cuenta de servicio no tiene almacenamiento propio, así que todo archivo que cree '
            .'ahí queda a nombre de una cuenta con cero bytes y Google responde '
            .'403 [storageQuotaExceeded] en la primera subida real — sin importar los permisos de la '
            .'carpeta. Para corregirlo: '
            .'1) crea una Unidad compartida en Google Drive; '
            .'2) agrega a '.$credential->client_email.' como Administrador de contenido (o Colaborador); '
            .'3) mueve o vuelve a crear la carpeta de la biblioteca DENTRO de esa unidad; '
            .'4) actualiza aquí el ID de la carpeta raíz y vuelve a probar la conexión.';

        if ($sharedDriveFolders !== '') {
            $message .= ' Carpetas en Unidades compartidas que esta cuenta ya alcanza: '.$sharedDriveFolders.'.';
        }

        return $message;
    }

    /**
     * The 404 message, written as the checklist the administrator has to walk.
     *
     * Ordered by how often each cause is the real one, and it names the account
     * and the scope in force because neither is visible from the settings
     * panel — an operator cannot check a sharing grant against an address they
     * have not been told.
     *
     * @param  array<int, array{id: string, name: string, shared_drive: bool}>  $visibleFolders
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
            // The shared-drive marker is part of the list because a reachable
            // folder in a My Drive is not an answer to this problem: pointing
            // the module at one would trade the 404 for a quota 403.
            $names = collect($visibleFolders)
                ->map(fn (array $folder) => $folder['name'].' ('.$folder['id'].')'
                    .(($folder['shared_drive'] ?? false) ? ' [Unidad compartida]' : ' [Mi unidad]'))
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
