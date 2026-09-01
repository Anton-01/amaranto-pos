<?php

namespace App\Models;

use App\Exceptions\Media\DriveCredentialsMissingException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Google Drive service account credentials, held encrypted in the database.
 *
 * The secret columns go through Laravel's `encrypted` cast, so a database dump
 * yields ciphertext, and they are listed in `$hidden` so no controller can
 * leak them by returning the model. The browser only ever sees the derived
 * `has_*` booleans and the client email — enough to recognize which account is
 * loaded, useless for impersonating it.
 */
class DriveCredential extends Model
{
    use HasUuids;

    /** Cache key of the OAuth access token derived from this row. */
    public const TOKEN_CACHE_PREFIX = 'media:drive:token:';

    protected $fillable = [
        'label',
        'service_account_json',
        'client_email',
        'project_id',
        'client_id',
        'client_secret',
        'private_key',
        'root_folder_id',
        'authorized_emails',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'updated_by',
    ];

    /**
     * The three columns that can impersonate the service account. None of them
     * has any business reaching the browser, not even truncated.
     */
    protected $hidden = [
        'service_account_json',
        'client_secret',
        'private_key',
    ];

    protected $appends = [
        'has_service_account',
        'has_private_key',
        'has_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'service_account_json' => 'encrypted',
            'client_secret' => 'encrypted',
            'private_key' => 'encrypted',
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
     * True when the row carries everything the JWT signer needs: an issuer
     * identity, a signing key and a destination folder. A row missing any of
     * the three would fail at the first API call, so it is rejected up front
     * with a message naming what is absent.
     */
    public function isUsable(): bool
    {
        return filled($this->client_email)
            && filled($this->private_key)
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
            'Correo de la Service Account' => $this->client_email,
            'Llave privada (JSON de la Service Account)' => $this->private_key,
            'ID de la carpeta raíz en Drive' => $this->root_folder_id,
        ])->filter(fn ($value) => blank($value))->keys()->all();
    }

    /**
     * Fills client_email, project_id and private_key out of a pasted service
     * account JSON.
     *
     * WHY DENORMALIZE. The signer needs the private key on every token refresh
     * and the UI needs the issuer on every page load. Decrypting and parsing
     * the whole JSON document for that would be work on a hot path, and would
     * put the full credential in memory far more often than necessary.
     *
     * Returns false when the payload is not a usable service account key, so
     * the caller can answer 422 instead of persisting something that will only
     * fail later against Google.
     */
    public function fillFromServiceAccountJson(string $json): bool
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        if (! is_array($decoded) || blank($decoded['client_email'] ?? null) || blank($decoded['private_key'] ?? null)) {
            return false;
        }

        $this->service_account_json = $json;
        $this->client_email = $decoded['client_email'];
        $this->private_key = $decoded['private_key'];
        $this->project_id = $decoded['project_id'] ?? null;

        // The JSON also carries the OAuth client id. Keeping it lets the panel
        // show the same identifier Google Cloud displays, without a second
        // field for the administrator to copy by hand.
        if (blank($this->client_id) && filled($decoded['client_id'] ?? null)) {
            $this->client_id = $decoded['client_id'];
        }

        return true;
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
     * Drops the cached OAuth token of this connection.
     *
     * Called on every credential write: after a key rotation the token minted
     * by the previous key is still inside its one-hour window and would keep
     * working, which would make a revocation look like it had not applied.
     */
    public function forgetAccessToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_PREFIX.$this->id);
    }

    public function getHasServiceAccountAttribute(): bool
    {
        return filled($this->service_account_json);
    }

    public function getHasPrivateKeyAttribute(): bool
    {
        return filled($this->private_key);
    }

    public function getHasClientSecretAttribute(): bool
    {
        return filled($this->client_secret);
    }
}
