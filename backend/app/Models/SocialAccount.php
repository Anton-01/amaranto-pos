<?php

namespace App\Models;

use App\Exceptions\Social\SocialCredentialsMissingException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credentials of one social network connection, held encrypted.
 *
 * SAME DISCIPLINE AS `DriveCredential`, AND FOR THE SAME REASON. The access
 * token goes through Laravel's `encrypted` cast, so a database dump yields
 * ciphertext, and it is listed in `$hidden` so no controller can leak it by
 * returning the model. The browser only ever sees `has_access_token` plus the
 * public ids — enough to recognize which connection is loaded, useless for
 * posting as the business.
 *
 * WHAT A LEAKED META TOKEN COSTS. A Page access token publishes, deletes and
 * reads the inbox of the page; an Instagram one publishes to the account. There
 * is no read-only tier being stored here, which is exactly why the column never
 * reaches an API response, not even truncated.
 */
class SocialAccount extends Model
{
    use HasUuids;

    public const PROVIDER_FACEBOOK = 'facebook';

    public const PROVIDER_INSTAGRAM = 'instagram';

    public const PROVIDER_WHATSAPP = 'whatsapp';

    /** The catalog the composer renders and the request validates against. */
    public const PROVIDERS = [
        self::PROVIDER_FACEBOOK => 'Facebook',
        self::PROVIDER_INSTAGRAM => 'Instagram',
        self::PROVIDER_WHATSAPP => 'WhatsApp',
    ];

    protected $fillable = [
        'provider',
        'label',
        'access_token',
        'page_id',
        'ig_user_id',
        'phone_number_id',
        'business_account_id',
        'token_expires_at',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'updated_by',
    ];

    /** The one column that can publish as the business. */
    protected $hidden = ['access_token'];

    protected $appends = ['has_access_token', 'provider_label', 'is_usable'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /** The live connection for a network, or null when it was never set up. */
    public static function activeFor(string $provider): ?self
    {
        return static::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();
    }

    /**
     * Same lookup, for the callers that cannot proceed without a connection.
     *
     * @throws SocialCredentialsMissingException when the network is not configured.
     */
    public static function requireActiveFor(string $provider): self
    {
        $account = static::activeFor($provider);

        if (! $account || ! $account->isUsable()) {
            throw new SocialCredentialsMissingException($provider, $account?->missingPieces() ?? []);
        }

        return $account;
    }

    /**
     * True when the row carries everything its provider's publish call needs.
     *
     * The requirements differ per network, and checking them here — rather than
     * inside the publisher — means a half-configured connection is rejected
     * with the name of what is missing instead of with Meta's opaque
     * "Unsupported post request".
     */
    public function isUsable(): bool
    {
        if (blank($this->access_token)) {
            return false;
        }

        return match ($this->provider) {
            self::PROVIDER_FACEBOOK => filled($this->page_id),
            self::PROVIDER_INSTAGRAM => filled($this->ig_user_id),
            // The mock publisher needs no sender yet; the column is reserved
            // for the Cloud API integration that replaces it.
            self::PROVIDER_WHATSAPP => true,
            default => false,
        };
    }

    /**
     * Names of the missing pieces, in Spanish, for the panel's status strip.
     *
     * @return array<int, string>
     */
    public function missingPieces(): array
    {
        $required = match ($this->provider) {
            self::PROVIDER_FACEBOOK => [
                'Access Token de la página' => $this->access_token,
                'ID de la página de Facebook' => $this->page_id,
            ],
            self::PROVIDER_INSTAGRAM => [
                'Access Token de la cuenta' => $this->access_token,
                'ID de la cuenta de Instagram Business' => $this->ig_user_id,
            ],
            default => ['Access Token' => $this->access_token],
        };

        return collect($required)->filter(fn ($value) => blank($value))->keys()->all();
    }

    /**
     * True when Meta's own expiry has already passed.
     *
     * Reported instead of silently publishing: a token that died yesterday
     * fails with a 190 the operator has no way to interpret, whereas a panel
     * that says "expiró el 3 de septiembre" points straight at the renewal.
     */
    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /** The Graph node every publication of this connection targets. */
    public function graphNodeId(): ?string
    {
        return match ($this->provider) {
            self::PROVIDER_FACEBOOK => $this->page_id,
            self::PROVIDER_INSTAGRAM => $this->ig_user_id,
            self::PROVIDER_WHATSAPP => $this->phone_number_id,
            default => null,
        };
    }

    public function getHasAccessTokenAttribute(): bool
    {
        return filled($this->access_token);
    }

    public function getProviderLabelAttribute(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    public function getIsUsableAttribute(): bool
    {
        return $this->isUsable();
    }
}
