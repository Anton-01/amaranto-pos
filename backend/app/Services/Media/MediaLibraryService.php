<?php

namespace App\Services\Media;

use App\Exceptions\Media\DisallowedFileTypeException;
use App\Exceptions\Media\GoogleDriveException;
use App\Models\AllowedFileType;
use App\Models\DriveCredential;
use App\Models\MediaAuditLog;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the life of a library file: validate, store, harden, index,
 * audit.
 *
 * The controllers hold no business rules — they translate HTTP into a call
 * here and a result back into JSON. Keeping the sequence in one class is what
 * guarantees that no future endpoint can upload a file while skipping the
 * whitelist, the permission hardening, or the audit entry.
 */
class MediaLibraryService
{
    public function __construct(
        private readonly FileTypeValidator $validator,
        private readonly GoogleDriveService $drive,
        private readonly MediaAuditLogger $audit,
        private readonly ShareLinkService $shareLinks,
    ) {}

    /**
     * Full upload pipeline.
     *
     * ORDER IS THE SECURITY PROPERTY. The whitelist runs first, against the
     * temporary file PHP already has on disk, so a forbidden type costs one
     * database read and never touches the network. Only then are the Drive
     * credentials resolved and the bytes pushed. An installation with Drive
     * misconfigured still rejects a .exe correctly.
     *
     * A REJECTION IS AUDITED. `upload_rejected` is written before the exception
     * leaves this method: an attempt to push a forbidden file is precisely the
     * event a forensic trail must show, and it is the one event that never
     * produces a `media_files` row to hang from.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws DisallowedFileTypeException|GoogleDriveException
     */
    public function upload(UploadedFile $file, User $actor, array $metadata = []): MediaFile
    {
        try {
            $policy = $this->validator->validate($file);
        } catch (DisallowedFileTypeException $e) {
            $this->audit->recordDetached(
                MediaAuditLog::ACTION_UPLOAD_REJECTED,
                $file->getClientOriginalName(),
                [
                    'reason' => $e->reason,
                    'message' => $e->getMessage(),
                    'size_bytes' => $file->getSize(),
                    'declared_mime' => $file->getClientMimeType(),
                    'detected_mime' => $file->getMimeType(),
                    ...$e->context,
                ],
                $actor,
            );

            throw $e;
        }

        $credential = DriveCredential::requireActive();

        $contents = file_get_contents($file->getRealPath());
        $checksum = hash('sha256', $contents);
        $storageName = $this->buildStorageName($file, $policy);

        try {
            $stored = $this->drive->upload(
                $credential,
                $contents,
                $storageName,
                $policy->mime_type,
                $policy->category,
            );
        } catch (GoogleDriveException $e) {
            /*
             * The upload is where a half-configured connection finally bites,
             * often hours after the setup screen was last opened. Google's own
             * text alone ("File not found") is not enough to act on, because a
             * root folder that is unreachable — not shared with this address,
             * or outside the OAuth scope's reach — is reported exactly like a
             * folder id that was never valid. The identity, the folder and the
             * scope in force are recorded together so the log line answers the
             * question instead of starting the investigation.
             */
            Log::error('Google Drive rechazó una subida de la biblioteca de medios.', [
                'status_code' => $e->statusCode,
                'google_reason' => $e->context['reason'] ?? null,
                'google_message' => $e->getMessage(),
                'client_email' => $credential->client_email,
                'root_folder_id' => $credential->root_folder_id,
                'oauth_scope' => config('media.drive.scope'),
                'category' => $policy->category,
                'storage_name' => $storageName,
                'hint' => $e->statusCode === 404
                    ? 'Un 404 con la carpeta raíz configurada suele significar que la carpeta no está '
                        .'compartida con la cuenta de servicio, o que el alcance OAuth no alcanza carpetas '
                        .'compartidas desde fuera. Usa Configuración → Google Drive → Probar conexión.'
                    : null,
            ]);

            throw $e;
        }

        $dimensions = $this->resolveDimensions($file, $policy);

        try {
            $media = DB::transaction(fn () => MediaFile::create([
                'drive_file_id' => $stored['drive_file_id'],
                'drive_folder_id' => $stored['drive_folder_id'],
                'name' => $metadata['name'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'original_name' => $file->getClientOriginalName(),
                'storage_name' => $storageName,
                'extension' => $policy->extension,
                'mime_type' => $policy->mime_type,
                'category' => $policy->category,
                'size_bytes' => $file->getSize(),
                'alt_text' => $metadata['alt_text'] ?? null,
                'description' => $metadata['description'] ?? null,
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'checksum' => $checksum,
                'visibility' => $stored['visibility'],
                'is_active' => true,
                'uploaded_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));
        } catch (Throwable $e) {
            /*
             * The bytes are already in Drive and the index write failed, so the
             * object would become an orphan nobody can see or delete from the
             * POS. Trashing it here is a best-effort compensation: if that also
             * fails there is nothing left to do but leave a loud trace with the
             * Drive id, which is what a human needs to clean up by hand.
             */
            $this->rollbackOrphan($credential, $stored['drive_file_id'], $e);

            throw $e;
        }

        $this->audit->record(MediaAuditLog::ACTION_UPLOAD, $media, [
            'original_name' => $media->original_name,
            'storage_name' => $storageName,
            'extension' => $media->extension,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'checksum' => $checksum,
            'drive_folder_id' => $stored['drive_folder_id'],
            // Null means the object landed in a My Drive folder, which is the
            // storage model that eventually fails with a quota 403. Recording
            // it makes the audit trail able to answer "when did this start"
            // instead of only "it is failing now".
            'shared_drive_id' => $stored['shared_drive_id'] ?? null,
            'visibility' => $media->visibility,
            'permissions_after_upload' => count($stored['permissions']),
        ], $actor);

        return $media;
    }

    /**
     * Updates the editable metadata of a library entry.
     *
     * The audit entry carries a BEFORE/AFTER diff of only the fields that
     * actually changed. A trail that records "metadata updated" without saying
     * what moved answers none of the questions it will be asked.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateMetadata(MediaFile $media, User $actor, array $payload): MediaFile
    {
        $tracked = ['name', 'alt_text', 'description', 'is_active', 'category'];
        $before = $media->only($tracked);

        $media->fill([...$payload, 'updated_by' => $actor->id]);

        $changes = collect($media->getDirty())
            ->except('updated_by')
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => [
                'from' => $before[$key] ?? null,
                'to' => $media->{$key},
            ]])
            ->all();

        $media->save();

        /*
         * The name in Drive follows the name in the library. Without this, the
         * object browsed directly in Drive keeps the name it was uploaded with
         * forever, and an incident response comparing both sides finds two
         * different truths. A Drive failure here does not fail the request:
         * the source of truth is the local row, and the rename is cosmetic.
         */
        if (array_key_exists('name', $changes) && filled($media->drive_file_id)) {
            $this->syncDriveName($media);
        }

        if ($changes !== []) {
            $this->audit->record(MediaAuditLog::ACTION_UPDATE_METADATA, $media, [
                'changes' => $changes,
            ], $actor);
        }

        return $media->fresh(['uploadedByUser:id,name', 'updatedByUser:id,name']);
    }

    /**
     * Flips the library entry's kill switch.
     *
     * Archiving revokes every open share link of the file. Without that, an
     * operator who pulled a document out of circulation would leave every URL
     * they had already handed out still serving it — the archive would be
     * theatre.
     */
    public function toggleStatus(MediaFile $media, User $actor): MediaFile
    {
        $media->update([
            'is_active' => ! $media->is_active,
            'updated_by' => $actor->id,
        ]);

        $revoked = $media->is_active ? 0 : $this->shareLinks->revokeAllFor($media, $actor);

        $this->audit->record(MediaAuditLog::ACTION_STATUS_CHANGE, $media, [
            'is_active' => $media->is_active,
            'revoked_share_links' => $revoked,
        ], $actor);

        return $media;
    }

    /**
     * Removes a file from the library.
     *
     * The local row is soft-deleted (so the global trash module can restore it)
     * and the Drive object is TRASHED, not destroyed. A hard delete in Drive
     * would make the restore button a lie the moment somebody pressed it.
     * Every open share link dies with the file, immediately.
     */
    public function delete(MediaFile $media, User $actor, ?string $reason = null): void
    {
        $revoked = $this->shareLinks->revokeAllFor($media, $actor);

        $driveTrashed = false;

        if (filled($media->drive_file_id)) {
            try {
                $this->drive->trash(DriveCredential::requireActive(), $media->drive_file_id);
                $driveTrashed = true;
            } catch (Throwable $e) {
                // The local row still goes away: an operator asked for the file
                // to disappear from the POS and Drive being unreachable is not
                // a reason to keep serving it. The divergence is logged and
                // audited so it can be reconciled.
                Log::warning('No se pudo enviar el archivo a la papelera de Google Drive.', [
                    'media_file_id' => $media->id,
                    'drive_file_id' => $media->drive_file_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->audit->record(MediaAuditLog::ACTION_DELETE, $media, [
            'reason' => $reason,
            'revoked_share_links' => $revoked,
            'drive_trashed' => $driveTrashed,
            'size_bytes' => $media->size_bytes,
        ], $actor);

        $media->advancedDelete($actor->id, $reason);
    }

    /**
     * Fetches a file's bytes from Drive and audits the access.
     *
     * `preview` and `download` are recorded as different actions on purpose:
     * opening a thumbnail and pulling a copy of a payroll spreadsheet are not
     * the same event, and a trail that conflates them cannot answer who took
     * data out of the system.
     *
     * @throws GoogleDriveException
     */
    public function fetchContents(MediaFile $media, ?User $actor, bool $isDownload): string
    {
        $contents = $this->drive->download(DriveCredential::requireActive(), (string) $media->drive_file_id);

        $this->audit->record(
            $isDownload ? MediaAuditLog::ACTION_DOWNLOAD : MediaAuditLog::ACTION_PREVIEW,
            $media,
            ['size_bytes' => strlen($contents)],
            $actor,
        );

        return $contents;
    }

    /**
     * Re-applies the privacy contract to a file already in Drive.
     *
     * Exposed as an explicit action because Drive permissions can be changed
     * from outside the POS — somebody with folder access clicks "share" in the
     * Drive UI and the object silently becomes public. This is the button that
     * takes it back, and it reports how many grants it had to remove.
     *
     * @return array{visibility: string, removed: int, granted: int}
     *
     * @throws GoogleDriveException
     */
    public function reapplyPermissions(MediaFile $media, User $actor): array
    {
        $result = $this->drive->hardenPermissions(
            DriveCredential::requireActive(),
            (string) $media->drive_file_id,
        );

        $media->update([
            'visibility' => $result['visibility'],
            'updated_by' => $actor->id,
        ]);

        $this->audit->record(MediaAuditLog::ACTION_PERMISSIONS_UPDATED, $media, [
            'removed_public_grants' => $result['removed'],
            'granted_readers' => $result['granted'],
            'visibility' => $result['visibility'],
        ], $actor);

        return [
            'visibility' => $result['visibility'],
            'removed' => $result['removed'],
            'granted' => $result['granted'],
        ];
    }

    /**
     * Name the object carries inside Drive.
     *
     * Slugged and suffixed with a short random token, for three reasons: the
     * folder stays browsable by a human, two uploads of "escaneo.pdf" on the
     * same day do not collide, and the original name — which came from a user
     * and may contain anything — never reaches Drive's API verbatim.
     */
    private function buildStorageName(UploadedFile $file, AllowedFileType $policy): string
    {
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'archivo';

        return Str::limit($base, 80, '').'-'.Str::lower(Str::random(8)).'.'.$policy->extension;
    }

    /**
     * Intrinsic dimensions of an image, read from the temporary file.
     *
     * Only attempted for the image category, and failure is silent: a corrupt
     * or exotic image whose header getimagesize() cannot parse is still a valid
     * upload, it simply has no dimensions to display.
     *
     * @return array{width: int|null, height: int|null}
     */
    private function resolveDimensions(UploadedFile $file, AllowedFileType $policy): array
    {
        if ($policy->category !== AllowedFileType::CATEGORY_IMAGE) {
            return ['width' => null, 'height' => null];
        }

        try {
            $size = @getimagesize($file->getRealPath());
        } catch (Throwable) {
            $size = false;
        }

        return is_array($size)
            ? ['width' => (int) $size[0], 'height' => (int) $size[1]]
            : ['width' => null, 'height' => null];
    }

    private function syncDriveName(MediaFile $media): void
    {
        try {
            $this->drive->rename(
                DriveCredential::requireActive(),
                (string) $media->drive_file_id,
                $media->name.'.'.$media->extension,
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo renombrar el archivo en Google Drive.', [
                'media_file_id' => $media->id,
                'drive_file_id' => $media->drive_file_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function rollbackOrphan(DriveCredential $credential, string $driveFileId, Throwable $cause): void
    {
        try {
            $this->drive->trash($credential, $driveFileId);
        } catch (Throwable $e) {
            Log::error('Archivo huérfano en Google Drive: se subió pero no pudo indexarse ni eliminarse.', [
                'drive_file_id' => $driveFileId,
                'index_error' => $cause->getMessage(),
                'cleanup_error' => $e->getMessage(),
            ]);
        }
    }
}
