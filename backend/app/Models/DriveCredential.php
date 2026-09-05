<?php

namespace App\Models;

use App\Exceptions\Media\DriveCredentialsMissingException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Google Drive OAuth 2.0 grant, held encrypted in the database.
 *
 * IDENTITY MODEL. The connection is a USER-CONTEXT grant — a client id, a
 * client secret and a refresh token issued by the owner of the Drive — and no
 * longer a service account key. A service account owns no storage quota, so
 * every byte it wrote was billed to an account with zero bytes and Drive
 * answered `403 [storageQuotaExceeded]`; the documented workaround, a Shared
 * Drive, does not exist on a personal Google One account. Acting AS the owner
 * makes the uploads belong to them and consume the plan they already pay for.
 *
 * The two secret columns go through Laravel's `encrypted` cast, so a database
 * dump yields ciphertext, and they are listed in `$hidden` so no controller can
 * leak them by returning the model. The browser only ever sees the derived
 * `has_*` booleans, the client id and the authorizing account's email — enough
 * to recognize which connection is loaded, useless for impersonating it.
 */
class DriveCredential extends Model
{
    use HasUuids;

    /** Cache key of the OAuth access token derived from this row. */
    public const TOKEN_CACHE_PREFIX = 'media:drive:token:';

    protected $fillable = [
        'label',
        'account_email',
        'client_id',
        'client_secret',
        'refresh_token',
        'root_folder_id',
        'authorized_emails',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'updated_by',
    ];

    /**
     * The two columns that can act on the owner's Drive. Neither has any
     * business reaching the browser, not even truncated.
     */
    protected $hidden = [
        'client_secret',
        'refresh_token',
    ];

    protected $appends = [
        'has_client_secret',
        'has_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'refresh_token' => 'encrypted',
            'authorized_emails' => 'array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active connection, or null when Drive has never been configured. */
    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->latest('updated_at')->first();
    }

    /**
     * Same lookup as active(), for the callers that cannot proceed without a
     * connection — every upload, download and share resolution.
     *
     * @throws DriveCredentialsMissingException when Drive is not configured.
     */
    public static function requireActive(): self
    {
        $credential = static::active();

        if (! $credential || ! $credential->isUsable()) {
            throw new DriveCredentialsMissingException();
        }

        return $credential;
    }

    /**
     * True when the row carries everything a token exchange needs: the OAuth
     * client pair, the grant issued against it, and a destination folder. A row
     * missing any of the four would fail at the first API call, so it is
     * rejected up front with a message naming what is absent.
     */
    public function isUsable(): bool
    {
        return filled($this->client_id)
            && filled($this->client_secret)
            && filled($this->refresh_token)
            && filled($this->root_folder_id);
    }

    /**
     * Names of the missing pieces, in Spanish, for the panel's status strip.
     *
     * @return array<int, string>
     */
    public function missingPieces(): array
    {
        return collect([
            'Client ID de OAuth' => $this->client_id,
            'Client Secret de OAuth' => $this->client_secret,
            'Refresh Token' => $this->refresh_token,
            'ID de la carpeta raíz en Drive' => $this->root_folder_id,
        ])->filter(fn ($value) => blank($value))->keys()->all();
    }

    /**
     * Corporate accounts that receive an explicit reader grant on every upload,
     * cleaned of blanks and malformed addresses so one typo cannot make an
     * otherwise valid upload fail at the permissions step.
     *
     * @return array<int, string>
     */
    public function grantableEmails(): array
    {
        return collect($this->authorized_emails ?? [])
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Label for this connection in logs and diagnostics.
     *
     * The authorizing account is resolved from Drive itself on every connection
     * test, so it is normally present; before the first test there is only the
     * client id, which still identifies WHICH OAuth application is speaking.
     */
    public function identityLabel(): string
    {
        return filled($this->account_email)
            ? (string) $this->account_email
            : 'la cuenta autorizada del Client ID '.($this->client_id ?: 'sin configurar');
    }

    /**
     * Drops the cached OAuth token of this connection.
     *
     * Called on every credential write: after a rotation the token minted by
     * the previous refresh token is still inside its one-hour window and would
     * keep working, which would make a revocation look like it had not applied.
     */
    public function forgetAccessToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_PREFIX.$this->id);
    }

    public function getHasClientSecretAttribute(): bool
    {
        return filled($this->client_secret);
    }

    public function getHasRefreshTokenAttribute(): bool
    {
        return filled($this->refresh_token);
    }
}
