<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repairs a `drive_credentials` table that is missing part of the OAuth
     * 2.0 triplet, which PostgreSQL reports as `SQLSTATE[42703]` the moment an
     * administrator presses Guardar on the Drive panel.
     *
     * WHY THIS EXISTS WHEN TWO EARLIER MIGRATIONS ALREADY DECLARE THE COLUMNS.
     * `client_id` and `client_secret` were created with the table, and
     * `account_email` and `refresh_token` were added by the switch to the
     * user-context grant. A database whose migration history is only partially
     * applied — the switch interrupted between its two Schema::table() calls,
     * or a schema restored from a dump older than either — ends up with a model
     * that writes four columns and a table that has fewer. The write then fails
     * with `column "refresh_token" of relation "drive_credentials" does not
     * exist`, which is a schema drift, not a bug in the controller.
     *
     * WHY EVERY COLUMN IS GUARDED BY hasColumn(). This migration has to be safe
     * on the database that is already correct — the common case — and on every
     * partially-migrated variant in between. Adding a column that exists aborts
     * the whole migration in PostgreSQL, so each one is checked individually
     * rather than assuming the four are missing or present as a block.
     *
     * COLUMN TYPES MIRROR THE MODEL'S CASTS. `client_secret` and
     * `refresh_token` carry Laravel's `encrypted` cast, so what is stored is a
     * base64 AES-256 envelope several times longer than the plaintext: both are
     * `text`, which is unbounded in PostgreSQL and cannot truncate a token.
     * `client_id` and `account_email` are stored in the clear — they are public
     * identifiers shown back in the panel, not secrets — and fit a `string`.
     */
    public function up(): void
    {
        Schema::table('drive_credentials', function (Blueprint $table) {
            if (! Schema::hasColumn('drive_credentials', 'client_id')) {
                // Public identifier of the OAuth application. Not a secret: it
                // is useless without the client secret it belongs to.
                $table->string('client_id')->nullable();
            }

            if (! Schema::hasColumn('drive_credentials', 'client_secret')) {
                // Encrypted at rest through the model's `encrypted` cast.
                $table->text('client_secret')->nullable();
            }

            if (! Schema::hasColumn('drive_credentials', 'refresh_token')) {
                // The long-lived half of the grant, exchanged for a short-lived
                // access token before each burst of Drive calls. Encrypted.
                $table->text('refresh_token')->nullable();
            }

            if (! Schema::hasColumn('drive_credentials', 'account_email')) {
                // Google account the refresh token authenticates as, resolved
                // from Drive's `about` endpoint on every connection test.
                $table->string('account_email')->nullable();
            }
        });
    }

    /**
     * Deliberately a no-op.
     *
     * The four columns are owned by the two migrations that declare them, and
     * this one only fills whichever gaps it finds. It cannot know which of them
     * it actually created, so dropping any of them on rollback would delete a
     * live credential column that a correctly migrated database created
     * earlier — and there is no way to recover a refresh token once it is gone.
     */
    public function down(): void
    {
        // Intentionally empty; see the note above.
    }
};
