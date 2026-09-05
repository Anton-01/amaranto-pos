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
 * THE STORAGE MODEL IS THE OWNER'S OWN DRIVE. The module authenticates with an
 * OAuth 2.0 refresh token issued by the person who owns the Drive, so every
 * file it creates belongs to that person and consumes the Google One plan they
 * already pay for. That is a deliberate replacement for the service account
 * this module started with: a service account owns no storage quota at all, so
 * Drive answered `403 [storageQuotaExceeded]` on the first real upload no
 * matter how the folder was shared, and the documented remedy — a Shared Drive
 * that owns its own contents — is a Google Workspace feature that a personal
 * account does not have.
 *
 * The consequence for this class is that a quota 403 is no longer a structural
 * impossibility to be explained away; it now means what it says, that the
 * owner's Drive is genuinely full, and translateQuotaFailure() says so with the
 * numbers to prove it.
 */
class GoogleDriveService
{
    /**
     * Google's reason code for "the owner of these bytes has no room left".
     * Under user-context OAuth this is a real, fixable storage condition —
     * see translateQuotaFailure().
     */
    private const REASON_QUOTA_EXCEEDED = 'storageQuotaExceeded';

    public function __construct(private readonly GoogleDriveClient $client) {}

    /**
     * Pushes bytes to Drive and returns the stored object's metadata, already
     * hardened.
     *
     * The order is deliberate: upload first, then lock the permissions, then
     * report. Drive creates the file owned by the authorizing account and shared
     * with nobody, so the window between the two calls is not a public window —
     * the hardening step exists to strip any grant the parent folder passed down
     * and to add the explicitly authorized corporate readers, not to close a
     * hole.
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
            'visibility' => $hardening['visibility'],
            'permissions' => $hardening['permissions'],
        ];
    }

    /**
     * Rewrites the quota 403 into the instruction that actually resolves it.
     *
     * THIS FAILURE CHANGED MEANING when the module moved from a service account
     * to a user-context OAuth grant, and the message had to change with it.
     * Under the service account it was structural and unfixable — that identity
     * owns no storage at all, so the 403 arrived on a Drive with terabytes free
     * and the only remedy was a Shared Drive, which a personal Google One
     * account does not have. Under an owner's own refresh token the same code
     * means the plain thing it says: that account is out of room.
     *
     * So the translation stops explaining an impossibility and states the
     * numbers instead. `about` is consulted for the real usage and limit
     * because "your Drive is full" is not actionable without them — the owner
     * needs to know whether they are 40 GB over or 200 MB over before choosing
     * between deleting files and buying storage. It degrades to the message
     * without figures if that probe fails, since a diagnostic must never
     * replace the failure it is describing.
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

        $quota = $this->readQuota($credential);

        Log::error('Subida rechazada por cuota: la cuenta de Google que autorizó la conexión está llena.', [
            'account_email' => $credential->account_email,
            'root_folder_id' => $credential->root_folder_id,
            'storage_usage' => $quota['usage'],
            'storage_limit' => $quota['limit'],
            'google_message' => $e->getMessage(),
        ]);

        return new GoogleDriveException(
            'Google Drive rechazó la subida por cuota de almacenamiento (403 storageQuotaExceeded). '
                .'La cuenta que autorizó la conexión ('.$credential->identityLabel().') ya no tiene espacio '
                .'disponible'.$this->describeQuota($quota).'. '
                .'Los archivos de la biblioteca pertenecen a esa cuenta y consumen su plan, así que la '
                .'solución es liberar espacio en ella (incluida la papelera de Drive y los adjuntos de '
                .'Gmail) o ampliar el plan de Google One. Cambiar los permisos de la carpeta raíz no '
                .'modifica quién paga los bytes.',
            $e->statusCode,
            $e->context + ['stage' => 'upload', 'remedy' => 'free_storage'],
            $e,
        );
    }

    /**
     * Storage usage of the authorizing account, or nulls when it cannot be read.
     *
     * Never throws: every caller uses this to enrich a message, and a probe that
     * can blow up would replace the real diagnosis with its own.
     *
     * @return array{limit: int|null, usage: int|null}
     */
    private function readQuota(DriveCredential $credential): array
    {
        try {
            $about = $this->client->about($credential);
        } catch (Throwable) {
            return ['limit' => null, 'usage' => null];
        }

        return ['limit' => $about['limit'], 'usage' => $about['usage']];
    }

    /**
     * Human phrasing of a storage reading, or an empty string when Drive did
     * not answer.
     *
     * A missing `limit` is not a gap in the data: Google omits it on unlimited
     * plans, and saying so is more useful than printing nothing.
     *
     * @param  array{limit: int|null, usage: int|null}  $quota
     */
    private function describeQuota(array $quota): string
    {
        if ($quota['usage'] === null) {
            return '';
        }

        if ($quota['limit'] === null) {
            return ' (uso actual: '.$this->humanBytes($quota['usage']).', plan sin límite declarado)';
        }

        return ' ('.$this->humanBytes($quota['usage']).' de '.$this->humanBytes($quota['limit']).' usados)';
    }

    /** Byte count as the units a person reads on Google's own storage page. */
    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return round($value, $index > 1 ? 2 : 0).' '.$units[$index];
    }

    /**
     * Enforces this module's privacy contract on one object.
     *
     * WHAT IT REMOVES. Every grant of type `anyone` (Drive's "anyone with the
     * link") and every grant of type `domain` (visible to a whole domain).
     * Those are the two shapes that make a file readable by somebody who simply
     * walks the folder structure — the exact leak this module was asked to
     * prevent. Under user-context OAuth they are a live risk rather than a
     * theoretical one: the library now lives inside a real person's Drive,
     * where a parent folder may well have been shared with a link years ago,
     * and a new child inherits that reach.
     *
     * WHAT IT KEEPS. The owner's own ownership, and any explicit per-user
     * grant. Deleting an owner permission is refused by Drive anyway, and
     * deleting a corporate reader we ourselves granted would fight the
     * administrator's configuration.
     *
     * WHY IT DOES NOT THROW ON A FAILED REVOCATION. A single permission that
     * refuses to disappear must not abort an upload whose bytes are already in
     * Drive — that would leave an orphan object with no library row. The
     * failure is logged and reflected in the returned visibility, so the file
     * is reported for what it is instead of being silently trusted.
     *
     * WHAT IT ADDS. A reader grant for each address in `authorized_emails`, so
     * the accounting team can open the file with their own Google identity
     * without the POS ever making it public.
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
                    'remedy' => 'Revisa el acceso general de la carpeta raíz en Drive: mientras la carpeta '
                        .'padre siga compartida por enlace, cada archivo nuevo nacerá alcanzable.',
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
                // account) is a configuration problem, not a reason to fail an
                // upload that already succeeded.
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
     * WHY IT VERIFIES FIVE THINGS AND NOT ONE. Redeeming the refresh token
     * proves the OAuth triplet is intact — and nothing more. The failures that
     * actually break this module in production are a root folder id that does
     * not exist, a folder that exists but belongs to a DIFFERENT Google account
     * than the one that authorized the grant, and an account whose storage is
     * already full; all three redeem a token happily and then fail on the first
     * upload, hours later, with a 404 or a 403 nobody connects back to the
     * setup screen.
     *
     * THE IDENTITY CHECK IS THE ONE THAT CANNOT BE INFERRED LATER. A refresh
     * token carries no readable identity, so nothing on this screen reveals
     * that the token was minted while signed into a personal Gmail account
     * while the folder id was copied from a work account. Asking Drive `about`
     * turns that invisible mismatch into a named address the administrator can
     * compare, and returns the storage figures in the same call — which is the
     * number that matters now that the bytes are billed to a real person with a
     * finite plan.
     *
     * The folder read is the step that most often fails with Google's least
     * helpful answer. Drive does not distinguish "this id does not exist" from
     * "this id is outside your token's reach": both are `404 File not found`.
     * That is why a 404 here is not repeated verbatim but expanded into the
     * checklist of causes — wrong Google account, id shape, OAuth scope —
     * together with the folders the grant can actually see.
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
            'account_identified' => false,
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
            // may be carrying a token minted by the refresh token being
            // replaced. Either way the check must exercise the credential in
            // front of it, so the cached token is dropped first.
            $credential->forgetAccessToken();

            $this->client->accessToken($credential);
            $checks['token_minted'] = true;

            $about = $this->client->about($credential);

            $checks['account_identified'] = filled($about['email']);
            $checks['account_email'] = $about['email'];
            $checks['storage_usage'] = $about['usage'];
            $checks['storage_limit'] = $about['limit'];
            $checks['storage_available'] = $about['limit'] === null || $about['usage'] === null
                ? null
                : max(0, $about['limit'] - $about['usage']);

            // Recorded on the model so the panel names the account on load and
            // every later log line can say WHO the connection speaks as. The
            // candidate may be unsaved; the controller decides what to persist.
            $credential->account_email = $about['email'] ?? $credential->account_email;

            if ($about['limit'] !== null && $about['usage'] !== null && $about['usage'] >= $about['limit']) {
                return $this->testFailure(
                    $checks,
                    'La cuenta '.$credential->identityLabel().' autentica correctamente, pero su '
                        .'almacenamiento está agotado'
                        .$this->describeQuota(['limit' => $about['limit'], 'usage' => $about['usage']]).'. '
                        .'Los archivos de la biblioteca se guardan a nombre de esa cuenta, así que toda '
                        .'subida sería rechazada con 403 storageQuotaExceeded. Libera espacio (incluida la '
                        .'papelera de Drive y los adjuntos de Gmail) o amplía el plan de Google One.',
                    'storage_quota',
                    $startedAt,
                );
            }

            $folder = $this->client->getFile(
                $credential,
                (string) $credential->root_folder_id,
                'id,name,mimeType,trashed,ownedByMe,capabilities(canAddChildren)',
            );

            $checks['root_folder_reachable'] = true;
            $checks['root_folder_name'] = $folder['name'] ?? null;
            $checks['root_folder_owned'] = (bool) ($folder['ownedByMe'] ?? false);

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
                    'La cuenta '.$credential->identityLabel().' puede ver la carpeta pero no escribir en ella. '
                        .'Si la carpeta pertenece a otra persona, pídele permiso de Editor; lo más simple es '
                        .'crear la carpeta raíz dentro del Drive de la cuenta que autorizó esta conexión.',
                    'root_folder',
                    $startedAt,
                );
            }

            $checks['root_folder_writable'] = true;
        } catch (GoogleDriveException $e) {
            return $this->reportDriveFailure($credential, $checks, $e, $startedAt);
        } catch (Throwable $e) {
            Log::error('Fallo inesperado al probar la conexión con Google Drive.', [
                'account_email' => $credential->account_email,
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
     * The statuses worth translating are the ones Google words misleadingly:
     *
     * - A token-stage failure is the OAuth triplet, never the folder. The
     *   client already expands `invalid_grant` and `invalid_client` into their
     *   real causes, so that text is passed through as it stands.
     * - 404 "File not found" does NOT mean the id does not exist. Drive reports
     *   every object outside the token's reach as absent, so a folder sitting in
     *   the administrator's browser still reads as 404 when the refresh token
     *   was issued by a different Google account, or when the OAuth scope cannot
     *   see objects this application did not create.
     * - 403 means the folder IS visible but this account may not write into it —
     *   or, with a `storageQuotaExceeded` reason, that the account is full.
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
        // The client stamps a stage only on the token path, so the rest is
        // inferred from the call that failed. The distinction matters: a 404
        // raised by the identity probe must NOT be dressed up as the root
        // folder checklist, which would send the administrator to inspect a
        // folder that was never read.
        $stage = $e->context['stage'] ?? match (true) {
            ($e->context['path'] ?? null) === '/about' => 'account',
            $checks['token_minted'] === true => 'root_folder',
            default => 'drive',
        };

        $message = $e->getMessage();
        $visibleFolders = [];

        if ($stage === 'root_folder' && $e->statusCode === 404) {
            $visibleFolders = $this->client->listAccessibleFolders($credential);
            $message = $this->explainUnreachableRootFolder($credential, $e, $visibleFolders);
        }

        if ($stage === 'root_folder' && $e->statusCode === 403) {
            // The two 403s arrive at the same place and need opposite fixes:
            // one is about who may touch the folder, the other about who has
            // room for the bytes. Google's HTTP status does not separate them;
            // its reason code does.
            $message = ($e->context['reason'] ?? null) === self::REASON_QUOTA_EXCEEDED
                ? 'La cuenta '.$credential->identityLabel().' no tiene espacio disponible en Drive'
                    .$this->describeQuota($this->readQuota($credential)).'. Libera espacio o amplía el '
                    .'plan de Google One. Respuesta de Google: '.$e->getMessage()
                : 'Google Drive negó el acceso (403) a la carpeta raíz "'.$credential->root_folder_id.'". '
                    .'La cuenta '.$credential->identityLabel().' alcanza la carpeta pero no tiene el permiso '
                    .'que la operación necesita. Pide permiso de Editor a su propietario, o usa una carpeta '
                    .'creada por la propia cuenta autorizada. Respuesta de Google: '.$e->getMessage();
        }

        Log::error('Prueba de conexión con Google Drive fallida.', [
            'stage' => $stage,
            'status_code' => $e->statusCode,
            // Google's reason code separates a genuinely wrong id from an
            // unreachable one; both surface as HTTP 404. At the token stage it
            // separates a revoked grant (`invalid_grant`) from a wrong OAuth
            // application (`invalid_client`).
            'google_reason' => $e->context['reason'] ?? null,
            'google_message' => $e->getMessage(),
            'client_id' => $credential->client_id,
            'account_email' => $credential->account_email,
            'root_folder_id' => $credential->root_folder_id,
            // Recorded on every failure because it is the single setting that
            // decides whether a folder created by hand is visible at all, and
            // it is invisible from the panel.
            'oauth_scope' => config('media.drive.scope'),
            'folders_visible_to_account' => $visibleFolders,
            'drive_path' => $e->context['path'] ?? null,
        ]);

        return $this->testFailure($checks, $message, $stage, $startedAt, $e->statusCode);
    }

    /**
     * The 404 message, written as the checklist the administrator has to walk.
     *
     * Ordered by how often each cause is the real one. Under user-context OAuth
     * the first cause is new and is now the most common of all: the refresh
     * token was generated while signed into one Google account and the folder
     * id was copied from another. Nothing on the settings screen reveals that
     * on its own, which is why the authorizing address is named here.
     *
     * @param  array<int, array{id: string, name: string, owned: bool}>  $visibleFolders
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
            .'1) que el Refresh Token se haya generado con la MISMA cuenta de Google dueña de la carpeta '
            .'(esta conexión autentica como '.$credential->identityLabel().'); '
            .'2) que el ID sea el tramo que sigue a /folders/ en la URL de la carpeta, sin parámetros ni '
            .'"?usp=sharing"; '
            .'3) que la carpeta no haya sido eliminada ni movida por su propietario.';

        if ($scope === $narrowScope) {
            $message .= ' AVISO: el alcance OAuth en uso es '.$narrowScope.', que solo alcanza archivos '
                .'creados por esta aplicación y NUNCA verá una carpeta que la persona creó a mano en su '
                .'Drive. Para ese caso el alcance debe ser https://www.googleapis.com/auth/drive '
                .'(variable MEDIA_DRIVE_SCOPE).';
        }

        if ($visibleFolders === []) {
            $message .= ' La cuenta autorizada no ve NINGUNA carpeta en Drive en este momento, lo que '
                .'apunta a que el token pertenece a una cuenta distinta de la que se pretendía autorizar.';
        } else {
            // Ownership is part of the list because a folder somebody else
            // shared is not an answer to this problem: its children are billed
            // to that other person, so pointing the module at one would trade
            // the 404 for a quota failure that is not even ours to fix.
            $names = collect($visibleFolders)
                ->map(fn (array $folder) => $folder['name'].' ('.$folder['id'].')'
                    .(($folder['owned'] ?? false) ? ' [propia]' : ' [compartida por terceros]'))
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
