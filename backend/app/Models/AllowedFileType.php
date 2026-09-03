<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the dynamic upload whitelist.
 *
 * The upload pipeline never carries its own list of extensions: it asks this
 * model whether the submitted file has an ACTIVE policy, and refuses the file
 * when the answer is no. Enabling ".xlsx" for the accounting team, or shutting
 * ".svg" during an incident, is therefore a row edit — not a deploy.
 */
class AllowedFileType extends Model
{
    use HasUuids;

    /** Raster and vector artwork: rendered inline by the preview engine. */
    public const CATEGORY_IMAGE = 'image';

    /** Text documents (pdf, docx, txt): icon preview plus inline PDF frame. */
    public const CATEGORY_DOCUMENT = 'document';

    /** Tabular files (xlsx, xls, csv). */
    public const CATEGORY_SPREADSHEET = 'spreadsheet';

    /** Slide decks (pptx). */
    public const CATEGORY_PRESENTATION = 'presentation';

    /** Compressed bundles (zip). Never expanded server-side. */
    public const CATEGORY_ARCHIVE = 'archive';

    /** Anything that has a policy but no dedicated preview. */
    public const CATEGORY_OTHER = 'other';

    /**
     * Catalog of categories.
     *
     * Shared by the validation rules, the admin dropdown and the frontend
     * preview engine, so the three of them can never disagree about which
     * buckets exist.
     */
    public const CATEGORIES = [
        self::CATEGORY_IMAGE => 'Imagen',
        self::CATEGORY_DOCUMENT => 'Documento',
        self::CATEGORY_SPREADSHEET => 'Hoja de Cálculo',
        self::CATEGORY_PRESENTATION => 'Presentación',
        self::CATEGORY_ARCHIVE => 'Archivo Comprimido',
        self::CATEGORY_OTHER => 'Otro',
    ];

    protected $fillable = [
        'extension',
        'mime_type',
        'label',
        'max_size_kb',
        'category',
        'is_active',
        'is_system',
        'updated_by',
    ];

    protected $appends = ['effective_max_size_kb'];

    protected function casts(): array
    {
        return [
            'max_size_kb' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Active policy for an extension, or null when the type is unknown or
     * disabled. Callers treat null as "reject the upload".
     *
     * The extension is normalized here and nowhere else, so a ".PDF" typed by
     * an administrator and a "pdf" sent by the browser resolve to the same row.
     */
    public static function activeFor(string $extension): ?self
    {
        return static::query()
            ->where('extension', static::normalizeExtension($extension))
            ->where('is_active', true)
            ->first();
    }

    /** Lowercase, dot-stripped, whitespace-free form used by the unique key. */
    public static function normalizeExtension(string $extension): string
    {
        return strtolower(trim($extension, " \t\n\r\0\x0B."));
    }

    /**
     * Size ceiling actually enforced, in kilobytes.
     *
     * The per-type limit can only be STRICTER than the platform ceiling. A row
     * saying 500 MB does not grant 500 MB: PHP's memory and the request
     * timeout are properties of the server, and no browser-editable row is
     * allowed to raise them.
     */
    public function getEffectiveMaxSizeKbAttribute(): int
    {
        return min($this->max_size_kb, (int) config('media.max_upload_kb', 25600));
    }

    /** Human size used by the admin table and by rejection messages. */
    public function humanMaxSize(): string
    {
        $kb = $this->effective_max_size_kb;

        return $kb >= 1024
            ? round($kb / 1024, 1).' MB'
            : $kb.' KB';
    }
}
