<?php

namespace App\Models;

use App\Traits\AdvancedSoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One entry of the centralized media library.
 *
 * The bytes live in Google Drive; this row is the index the POS reasons about.
 * It keeps a full local copy of the metadata on purpose — the library grid,
 * its filters and the audit viewer all render without a single call to Google.
 * A network trip to Drive happens only when somebody actually opens, downloads
 * or shares a file.
 */
class MediaFile extends Model
{
    use AdvancedSoftDeletes, HasUuids;

    /** Readable only by the service account that owns the file. */
    public const VISIBILITY_PRIVATE = 'private';

    /** Service account plus the explicitly granted corporate accounts. */
    public const VISIBILITY_RESTRICTED = 'restricted';

    /**
     * Catalog of privacy states.
     *
     * There is no "public" member, and that is the module's central security
     * decision: nothing here may ever create an "anyone with the link" grant on
     * Drive. Sharing is served by media_share_links, which this application
     * validates and can revoke.
     */
    public const VISIBILITIES = [
        self::VISIBILITY_PRIVATE => 'Privado (solo la cuenta de servicio)',
        self::VISIBILITY_RESTRICTED => 'Restringido (cuentas autorizadas)',
    ];

    protected $fillable = [
        'drive_file_id',
        'drive_folder_id',
        'name',
        'original_name',
        'storage_name',
        'extension',
        'mime_type',
        'category',
        'size_bytes',
        'alt_text',
        'description',
        'width',
        'height',
        'checksum',
        'visibility',
        'is_active',
        'uploaded_by',
        'updated_by',
    ];

    protected $appends = ['human_size', 'preview_kind', 'dimensions'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(MediaShareLink::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(MediaAuditLog::class);
    }

    /** Share links that can still be redeemed right now. */
    public function activeShareLinks(): HasMany
    {
        return $this->shareLinks()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Free-text search over the fields an operator actually remembers: the
     * library name, the original file name and the alt text.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', trim($term)).'%';

        return $query->where(function (Builder $inner) use ($like) {
            $inner->where('name', 'ilike', $like)
                ->orWhere('original_name', 'ilike', $like)
                ->orWhere('alt_text', 'ilike', $like);
        });
    }

    /**
     * Columns the library grid needs, and nothing else.
     *
     * The system's payload standard forbids an implicit SELECT *: the
     * description can hold a paragraph per row, and shipping it for a
     * hundred-thumbnail grid nobody is reading is pure weight on the wire.
     *
     * @return array<int, string>
     */
    public static function gridColumns(): array
    {
        return [
            'id', 'drive_file_id', 'name', 'original_name', 'extension', 'mime_type',
            'category', 'size_bytes', 'alt_text', 'width', 'height', 'visibility',
            'is_active', 'uploaded_by', 'created_at', 'updated_at',
        ];
    }

    /**
     * How the frontend should render this file.
     *
     * Resolved from the MIME type server-side so the preview engine, the audit
     * viewer and any future consumer agree without each reimplementing the
     * mapping. `image` renders the bytes; `pdf` renders an embedded frame;
     * everything else falls back to a typed icon.
     */
    public function getPreviewKindAttribute(): string
    {
        return match (true) {
            str_starts_with((string) $this->mime_type, 'image/') => 'image',
            $this->mime_type === 'application/pdf' => 'pdf',
            $this->category === AllowedFileType::CATEGORY_SPREADSHEET => 'spreadsheet',
            $this->category === AllowedFileType::CATEGORY_PRESENTATION => 'presentation',
            $this->category === AllowedFileType::CATEGORY_ARCHIVE => 'archive',
            default => 'document',
        };
    }

    /** "1.4 MB" — formatted once here instead of in every consumer. */
    public function getHumanSizeAttribute(): string
    {
        $bytes = (int) $this->size_bytes;

        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 2).' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 2).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }

    /** "1920 × 1080" for images, null for everything else. */
    public function getDimensionsAttribute(): ?string
    {
        return $this->width && $this->height
            ? $this->width.' × '.$this->height
            : null;
    }
}
