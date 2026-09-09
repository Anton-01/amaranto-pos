<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One publication attempt against one network.
 *
 * This is the module's audit trail, and it is append-and-close: a row is
 * created in `pending` before the network is touched and is only ever moved
 * forward to `success` or `failed`. Nothing deletes it, not even the deletion
 * of the image it published — the foreign keys are `nullOnDelete` precisely so
 * the evidence outlives its subject.
 */
class SocialPost extends Model
{
    use HasUuids;

    /** Queued, not yet picked up by the worker. */
    public const STATUS_PENDING = 'pending';

    /** The worker is inside the provider's call right now. */
    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING => 'En cola',
        self::STATUS_PUBLISHING => 'Publicando',
        self::STATUS_SUCCESS => 'Publicado',
        self::STATUS_FAILED => 'Falló',
    ];

    protected $fillable = [
        'batch_id',
        'media_file_id',
        'social_account_id',
        'provider',
        'status',
        'caption',
        'image_url',
        'api_response_id',
        'metadata',
        'error_message',
        'error_code',
        'published_at',
        'created_by',
    ];

    protected $appends = ['status_label', 'provider_label'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Marks the row as being published right now.
     *
     * Separate from the success write so a worker that dies inside the HTTP
     * call leaves `publishing` behind instead of `pending`. The distinction is
     * operational: `pending` means "nothing was sent", `publishing` means "we
     * do not know whether the network received it", and only the second one
     * warrants checking the page before retrying.
     */
    public function markPublishing(string $imageUrl, ?SocialAccount $account = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_PUBLISHING,
            'image_url' => $imageUrl,
            'social_account_id' => $account?->id ?? $this->social_account_id,
        ])->save();
    }

    /** @param  array<string, mixed>  $context */
    public function markSuccess(?string $apiResponseId, array $context = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCESS,
            'api_response_id' => $apiResponseId,
            'metadata' => $context === [] ? null : $context,
            'error_code' => null,
            'published_at' => now(),
        ])->save();
    }

    public function markFailed(string $message, ?string $code = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            // Bounded: a Graph error can carry a full HTML body when a proxy
            // answers instead of Meta, and an unbounded text column is how a
            // log table quietly becomes the biggest one in the database.
            'error_message' => mb_substr($message, 0, 2000),
            'error_code' => $code,
        ])->save();
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getProviderLabelAttribute(): string
    {
        return SocialAccount::PROVIDERS[$this->provider] ?? $this->provider;
    }
}
