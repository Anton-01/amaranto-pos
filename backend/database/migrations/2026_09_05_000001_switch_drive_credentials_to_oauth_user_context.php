<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves the Drive connection from a service account key to an OAuth 2.0
     * user-context grant (client id + client secret + refresh token).
     *
     * WHY THE STRATEGY CHANGED. A Google service account is an identity with no
     * storage quota of its own, so every object it creates inside an ordinary
     * "My Drive" folder is billed to an account that owns zero bytes and Drive
     * answers `403 [storageQuotaExceeded]` on the first real upload. The
     * documented escape is to put the library root inside a Shared Drive, where
     * the drive owns its contents — but Shared Drives are a Google Workspace
     * feature and this deployment's Drive is a personal Google One account,
     * which has no such thing. There was no configuration of the service
     * account that could succeed.
     *
     * A refresh token issued by the account's own owner removes the problem at
     * the root: the API calls are made AS that person, so uploaded files belong
     * to them and consume the Google One plan they already pay for. No shared
     * drive, no quota-less identity, no `supportsAllDrives`.
     *
     * WHAT IS DROPPED AND WHY IT IS DROPPED RATHER THAN LEFT NULLABLE. The
     * service account columns are dead weight the moment the signer is gone,
     * and one of them is an RSA private key: keeping an unused credential
     * encrypted in a production table is a liability with no upside, since
     * nothing can read it back into a working connection anymore.
     *
     * WHAT IS ENCRYPTED. `client_secret` and the new `refresh_token` go through
     * Laravel's `encrypted` cast (AES-256 with APP_KEY), so a database dump —
     * the realistic leak — yields ciphertext. `client_id`, `account_email` and
     * `root_folder_id` stay in the clear: they are identifiers shown in the UI,
     * not secrets, and a client id is useless without the pair it belongs to.
     *
     * EXISTING ROWS SURVIVE BUT STOP BEING USABLE, deliberately. There is no
     * way to derive a refresh token from a service account key, so the active
     * connection reports its missing pieces and the library refuses uploads
     * until an administrator pastes the new triplet — which is the honest
     * outcome, and far better than a row that looks configured and fails at
     * Google.
     */
    public function up(): void
    {
        Schema::table('drive_credentials', function (Blueprint $table) {
            // Google account that authorized the grant. Resolved from Drive's
            // `about` endpoint on every connection test rather than typed by
            // hand, so it always names the identity the token actually carries
            // instead of the one somebody believed they had authorized.
            $table->string('account_email')->nullable();

            // The long-lived half of the OAuth grant. It is exchanged for a
            // short-lived access token before each burst of calls and is the
            // only stored value that can act on the user's Drive.
            $table->text('refresh_token')->nullable();
        });

        Schema::table('drive_credentials', function (Blueprint $table) {
            $table->dropColumn(['service_account_json', 'private_key', 'client_email', 'project_id']);
        });

        /*
         * The stored health check describes a connection that no longer exists.
         * Clearing it stops the panel from showing a green "last test: success"
         * over credentials that were just invalidated.
         */
        DB::table('drive_credentials')->update([
            'last_tested_at' => null,
            'last_test_status' => null,
            'last_test_message' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('drive_credentials', function (Blueprint $table) {
            $table->text('service_account_json')->nullable();
            $table->string('client_email')->nullable();
            $table->string('project_id', 120)->nullable();
            $table->text('private_key')->nullable();
        });

        Schema::table('drive_credentials', function (Blueprint $table) {
            $table->dropColumn(['account_email', 'refresh_token']);
        });
    }
};
