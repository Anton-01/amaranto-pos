<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable line of the media module's forensic trail.
 *
 * Append-only by contract: the application inserts and reads. `$timestamps` is
 * off because the table has no `updated_at` — a row that can be modified is
 * not evidence, and the schema is what enforces that, not a convention.
 */
class MediaAuditLog extends Model
{
    use HasUuids;

    public const ACTION_UPLOAD = 'upload';

    public const ACTION_UPLOAD_REJECTED = 'upload_rejected';

    public const ACTION_UPDATE_METADATA = 'update_metadata';

    public const ACTION_DOWNLOAD = 'download';

    public const ACTION_PREVIEW = 'preview';

    public const ACTION_SHARE_LINK_CREATED = 'share_link_created';

    public const ACTION_SHARE_LINK_REVOKED = 'share_link_revoked';

    public const ACTION_SHARE_LINK_ACCESSED = 'share_link_accessed';

    public const ACTION_DELETE = 'delete';

    public const ACTION_RESTORE = 'restore';

    public const ACTION_STATUS_CHANGE = 'status_change';

    public const ACTION_PERMISSIONS_UPDATED = 'permissions_updated';

    public const ACTION_FILE_TYPE_CREATED = 'file_type_created';

    public const ACTION_FILE_TYPE_UPDATED = 'file_type_updated';

    public const ACTION_FILE_TYPE_STATUS_CHANGE = 'file_type_status_change';

    public const ACTION_FILE_TYPE_DELETED = 'file_type_deleted';

    public const ACTION_CREDENTIALS_UPDATED = 'credentials_updated';

    public const ACTION_CREDENTIALS_TESTED = 'credentials_tested';

    /**
     * Catalog of auditable actions.
     *
     * Shared by the viewer's filter dropdown and by the API validation, so the
     * administrator can never filter by an action the system does not emit.
     */
    public const ACTIONS = [
        self::ACTION_UPLOAD => 'Subida de archivo',
        self::ACTION_UPLOAD_REJECTED => 'Subida rechazada',
        self::ACTION_UPDATE_METADATA => 'Actualización de metadatos',
        self::ACTION_DOWNLOAD => 'Descarga',
        self::ACTION_PREVIEW => 'Vista previa',
        self::ACTION_SHARE_LINK_CREATED => 'Enlace de compartición generado',
        self::ACTION_SHARE_LINK_REVOKED => 'Enlace revocado',
        self::ACTION_SHARE_LINK_ACCESSED => 'Acceso por enlace compartido',
        self::ACTION_DELETE => 'Eliminación',
        self::ACTION_RESTORE => 'Restauración',
        self::ACTION_STATUS_CHANGE => 'Cambio de estatus',
        self::ACTION_PERMISSIONS_UPDATED => 'Permisos de Drive actualizados',
        self::ACTION_FILE_TYPE_CREATED => 'Tipo de archivo creado',
        self::ACTION_FILE_TYPE_UPDATED => 'Tipo de archivo actualizado',
        self::ACTION_FILE_TYPE_STATUS_CHANGE => 'Tipo de archivo activado/desactivado',
        self::ACTION_FILE_TYPE_DELETED => 'Tipo de archivo eliminado',
        self::ACTION_CREDENTIALS_UPDATED => 'Credenciales de Drive actualizadas',
        self::ACTION_CREDENTIALS_TESTED => 'Prueba de conexión con Drive',
    ];

    /**
     * Actions that represent a security-relevant event, highlighted by the
     * viewer. Grouped here and not in the frontend so the classification stays
     * a backend decision.
     */
    public const CRITICAL_ACTIONS = [
        self::ACTION_UPLOAD_REJECTED,
        self::ACTION_DELETE,
        self::ACTION_SHARE_LINK_CREATED,
        self::ACTION_SHARE_LINK_ACCESSED,
        self::ACTION_CREDENTIALS_UPDATED,
        self::ACTION_PERMISSIONS_UPDATED,
    ];

    public $timestamps = false;

    protected $fillable = [
        'media_file_id',
        'resource_name',
        'drive_file_id',
        'action',
        'user_id',
        'user_name',
        'user_email',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $appends = ['action_label', 'is_critical'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** Spanish label of the action, resolved server-side for the viewer. */
    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function getIsCriticalAttribute(): bool
    {
        return in_array($this->action, self::CRITICAL_ACTIONS, true);
    }
}
