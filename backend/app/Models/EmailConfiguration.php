<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound email credentials and routing for a single business process.
 *
 * One row per `process_type`: it holds the provider API key, the sender
 * identity, the subject and the recipient list used whenever that process
 * needs to notify somebody. Nothing here is read at boot — the transport is
 * assembled on the fly by App\Services\Mail\DynamicMailerFactory right before
 * the message leaves, so a key rotated in the admin UI takes effect on the
 * next queued message without restarting PHP-FPM or the queue workers.
 */
class EmailConfiguration extends Model
{
    use HasUuids;

    /** Automated background processes (scheduler, jobs, cash auto-closing). */
    public const PROCESS_JOBS = 'jobs';

    /** User lifecycle mail (welcome, password reset, account changes). */
    public const PROCESS_USERS = 'users';

    /**
     * Catalog of addressable processes.
     *
     * Shared by the validation rules and by the admin UI dropdown so both ends
     * always offer the exact same set of keys.
     */
    public const PROCESS_TYPES = [
        self::PROCESS_JOBS => 'Procesos Automaticos (Jobs)',
        self::PROCESS_USERS => 'Usuarios y Accesos',
        'sales' => 'Ventas y Cierres',
        'inventory' => 'Inventario y Stock',
    ];

    /** Transport families understood by config/mailing.php. */
    public const PROVIDERS = [
        'sendgrid' => 'SendGrid',
        'smtp' => 'SMTP Generico',
    ];

    protected $fillable = [
        'process_type',
        'provider',
        'api_key',
        'from_email',
        'from_name',
        'target_emails',
        'subject',
        'is_active',
        'updated_by',
    ];

    /**
     * The raw credential never travels to the browser. The UI works with
     * `api_key_preview` / `has_api_key` instead, and submits a new key only
     * when the administrator actually wants to rotate it.
     */
    protected $hidden = ['api_key'];

    protected $appends = ['has_api_key', 'api_key_preview'];

    protected function casts(): array
    {
        return [
            // Laravel encrypts on write and decrypts on read using APP_KEY, so
            // a database dump never exposes a usable provider credential.
            'api_key' => 'encrypted',
            'target_emails' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Active configuration for a process, or null when the process has no
     * mailing configured. Callers treat null as "notifications disabled".
     */
    public static function activeFor(string $processType): ?self
    {
        return static::query()
            ->where('process_type', $processType)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Recipient list cleaned for delivery: trimmed, de-duplicated and stripped
     * of syntactically invalid addresses, so one malformed entry saved by hand
     * cannot make the whole send fail inside the transport.
     */
    public function deliverableEmails(): array
    {
        return collect($this->target_emails ?? [])
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /** True when a credential is stored, without revealing it. */
    public function getHasApiKeyAttribute(): bool
    {
        return filled($this->api_key);
    }

    /**
     * Last four characters of the credential, enough for an administrator to
     * recognize which key is loaded without being able to reuse it.
     */
    public function getApiKeyPreviewAttribute(): ?string
    {
        $key = $this->api_key;

        if (blank($key)) {
            return null;
        }

        return '****' . substr($key, -4);
    }
}
