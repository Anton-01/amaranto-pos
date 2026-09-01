<?php

namespace App\Services\Media;

use App\Models\MediaAuditLog;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The module's single writer of forensic evidence.
 *
 * Every controller and service that changes anything in the media module comes
 * through here, so an action can never be performed without leaving a trace by
 * simply forgetting a line of logging in one branch.
 *
 * TWO INVARIANTS SHAPE THIS CLASS:
 *
 * 1. The actor and the resource name are SNAPSHOTTED, not referenced. A file
 *    gets renamed and a user gets deleted; the record of what was downloaded
 *    and by whom must survive both. The foreign keys are kept as well, for
 *    joins while the rows still exist.
 *
 * 2. Auditing never breaks the operation. A logger that can abort a business
 *    action by failing turns a full disk into a total outage. A write that
 *    fails here is reported to the application log and the caller carries on.
 */
class MediaAuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Records one action against a library file.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?MediaFile $file = null,
        array $metadata = [],
        ?User $actor = null,
    ): ?MediaAuditLog {
        return $this->write([
            'media_file_id' => $file?->id,
            'resource_name' => $file?->name ?? ($metadata['resource_name'] ?? null),
            'drive_file_id' => $file?->drive_file_id,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
        ], $actor);
    }

    /**
     * Records an action on a resource that is not a library file — a file type
     * policy, the Drive credentials, a rejected upload that never became a row.
     *
     * The rejected upload is the reason this method exists at all: an attempt
     * to push a forbidden file is exactly the event a forensic trail must show,
     * and it is the one event with no `media_files` row to hang from.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordDetached(
        string $action,
        ?string $resourceName,
        array $metadata = [],
        ?User $actor = null,
    ): ?MediaAuditLog {
        return $this->write([
            'media_file_id' => null,
            'resource_name' => $resourceName,
            'drive_file_id' => null,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
        ], $actor);
    }

    /**
     * Records an action attributed to an anonymous visitor.
     *
     * Share link redemptions have no authenticated user by design — that is the
     * whole point of a share link — so the trail identifies them by address and
     * user agent, and names the link that authorized the access.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordAnonymous(string $action, ?MediaFile $file, array $metadata = []): ?MediaAuditLog
    {
        return $this->write([
            'media_file_id' => $file?->id,
            'resource_name' => $file?->name,
            'drive_file_id' => $file?->drive_file_id,
            'action' => $action,
            'metadata' => $metadata === [] ? null : $metadata,
        ], null, anonymous: true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes, ?User $actor, bool $anonymous = false): ?MediaAuditLog
    {
        $user = $anonymous ? null : ($actor ?? $this->request->user());

        try {
            return MediaAuditLog::create([
                ...$attributes,
                'user_id' => $user?->id,
                // Snapshot columns: what these say is what was true when the
                // action happened, whatever the users table says later.
                'user_name' => $user?->name ?? ($anonymous ? 'Visitante (enlace compartido)' : 'Sistema'),
                'user_email' => $user?->email,
                'ip_address' => $this->request->ip(),
                'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Never rethrown: see the class docblock. The evidence is lost for
            // this one action, the operation is not.
            Log::error('No se pudo escribir el registro de auditoría de medios.', [
                'action' => $attributes['action'] ?? null,
                'media_file_id' => $attributes['media_file_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
