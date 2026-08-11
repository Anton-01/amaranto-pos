<?php

namespace App\Services;

use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Authorization gate for operations that void money already registered.
 *
 * The secret lives in `global_settings` under a single key and is stored as a
 * bcrypt hash, never in clear text. It is deliberately NOT any user's login
 * password: a supervisor can hand it to a shift lead to authorize a
 * cancellation without ever surrendering their own account credentials, and
 * rotating it does not force anybody to change how they sign in.
 *
 * Administrators bypass the prompt entirely — their role already carries the
 * authority the password exists to delegate.
 */
class CancellationAuthorizationService
{
    /** Key holding the hashed secret inside global_settings. */
    public const SETTING_KEY = 'cancellation_authorization';

    /** Role whose members cancel without typing the password. */
    public const PRIVILEGED_ROLE = 'admin';

    /** Recorded in the audit trail as the authority behind a cancellation. */
    public const AUTHORIZED_BY_ROLE = 'admin_role';

    public const AUTHORIZED_BY_PASSWORD = 'authorization_password';

    public function isConfigured(): bool
    {
        return filled($this->storedHash());
    }

    /** Timestamp of the last rotation, for the admin panel to display. */
    public function configuredAt(): ?string
    {
        $value = GlobalSetting::where('key', self::SETTING_KEY)->value('value');

        return is_array($value) ? ($value['updated_at'] ?? null) : null;
    }

    /** Replaces the secret. Only the hash is ever persisted. */
    public function store(string $plainPassword, ?string $updatedBy = null): void
    {
        GlobalSetting::updateOrCreate(
            ['key' => self::SETTING_KEY],
            [
                'value' => [
                    'hash' => Hash::make($plainPassword),
                    'updated_at' => now()->toIso8601String(),
                ],
                'updated_by' => $updatedBy,
            ]
        );
    }

    /**
     * Resolves who authorizes a cancellation.
     *
     * Returns the label stored in the audit trail, or null when the request
     * cannot be authorized. The password is compared with Hash::check, which
     * is constant-time and works against the stored bcrypt digest.
     */
    public function authorize(User $user, ?string $plainPassword): ?string
    {
        if ($user->hasRole(self::PRIVILEGED_ROLE)) {
            return self::AUTHORIZED_BY_ROLE;
        }

        $hash = $this->storedHash();

        if (blank($hash) || blank($plainPassword)) {
            return null;
        }

        return Hash::check($plainPassword, $hash) ? self::AUTHORIZED_BY_PASSWORD : null;
    }

    private function storedHash(): ?string
    {
        $value = GlobalSetting::where('key', self::SETTING_KEY)->value('value');

        return is_array($value) ? ($value['hash'] ?? null) : null;
    }
}
