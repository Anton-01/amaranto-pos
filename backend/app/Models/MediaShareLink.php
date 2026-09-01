<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A controlled share link issued by the POS.
 *
 * NOT a Google "anyone with the link" grant. The object in Drive stays
 * private; this row is a token the application validates before streaming the
 * bytes itself. That inversion is what buys expiration, download caps and
 * instant revocation — none of which Drive's public link can offer.
 *
 * The raw token exists exactly once, in the response that creates the link.
 * What is persisted is its SHA-256, so whoever reads this table cannot rebuild
 * a working URL.
 */
class MediaShareLink extends Model
{
    use HasUuids;

    /** Inline preview only; the endpoint answers with an inline disposition. */
    public const PERMISSION_VIEW = 'view';

    /** Download allowed; the endpoint answers with an attachment disposition. */
    public const PERMISSION_DOWNLOAD = 'download';

    public const PERMISSIONS = [
        self::PERMISSION_VIEW => 'Solo vista previa',
        self::PERMISSION_DOWNLOAD => 'Vista previa y descarga',
    ];

    protected $fillable = [
        'media_file_id',
        'token_hash',
        'token_preview',
        'permission',
        'expires_at',
        'max_downloads',
        'download_count',
        'revoked_at',
        'revoked_by',
        'last_accessed_at',
        'last_accessed_ip',
        'created_by',
    ];

    /**
     * The hash is not a secret in the cryptographic sense, but publishing it
     * invites offline work against a token whose format is known. It never
     * leaves the server.
     */
    protected $hidden = ['token_hash'];

    protected $appends = ['is_expired', 'is_revoked', 'is_exhausted', 'is_usable', 'status'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'max_downloads' => 'integer',
            'download_count' => 'integer',
        ];
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** SHA-256 of a raw token — the single place the digest is computed. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getIsRevokedAttribute(): bool
    {
        return $this->revoked_at !== null;
    }

    public function getIsExhaustedAttribute(): bool
    {
        return $this->max_downloads !== null && $this->download_count >= $this->max_downloads;
    }

    /** The single predicate the public endpoint checks before serving bytes. */
    public function getIsUsableAttribute(): bool
    {
        return ! $this->is_revoked && ! $this->is_expired && ! $this->is_exhausted;
    }

    /**
     * Reason the link is unusable, in the order that matters to an operator:
     * a revoked link is revoked even if it also expired, because revocation is
     * the deliberate act somebody performed.
     */
    public function getStatusAttribute(): string
    {
        return match (true) {
            $this->is_revoked => 'revoked',
            $this->is_expired => 'expired',
            $this->is_exhausted => 'exhausted',
            default => 'active',
        };
    }
}
