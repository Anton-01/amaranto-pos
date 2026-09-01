<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Google Drive service account credentials, held encrypted in the database.
     *
     * WHY NOT A FILE. Shipping a service-account JSON inside the image bakes a
     * live credential into every build artifact and every registry layer;
     * mounting it as a volume makes credential rotation an ops ticket and a
     * container restart. Here the secret lives in one encrypted column, is
     * rotated from the admin panel, and takes effect on the next upload.
     *
     * WHAT IS ENCRYPTED. `service_account_json`, `client_secret` and
     * `private_key` go through Laravel's `encrypted` cast (AES-256 with
     * APP_KEY), so a database dump — the realistic leak — yields ciphertext.
     * The identifiers that are not secrets (client id, client email, root
     * folder id) stay in the clear: they are shown in the UI and searched on.
     */
    public function up(): void
    {
        Schema::create('drive_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Human name of the connection ("Drive Corporativo"). One row is
            // active at a time; the rest are kept as rotation history.
            $table->string('label', 120)->default('Google Drive');

            // Full service account JSON as downloaded from Google Cloud.
            // Encrypted; never returned to the browser.
            $table->text('service_account_json')->nullable();

            // Denormalized from the JSON on save so the UI, the audit trail
            // and the token signer can read them without decrypting the whole
            // document on every request.
            $table->string('client_email')->nullable();
            $table->string('project_id', 120)->nullable();

            // OAuth client pair, for installations that provision the account
            // through a client id/secret instead of a JSON key file.
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();

            // Private key extracted from the JSON, encrypted separately: this
            // is the value that actually signs the JWT assertion.
            $table->text('private_key')->nullable();

            // Drive folder that roots the whole library. Every upload lands
            // inside it or inside a subfolder of it, which bounds the blast
            // radius of the `drive.file` scope to one visible location.
            $table->string('root_folder_id', 120)->nullable();

            // Optional list of Google accounts that receive an explicit reader
            // grant on every uploaded file. Empty means "service account only".
            $table->jsonb('authorized_emails')->default('[]');

            $table->boolean('is_active')->default(true);

            // Outcome of the last synchronous health check. Stored so the
            // panel can state the connection's real condition on load instead
            // of claiming everything is fine until somebody presses Probar.
            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();

            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_credentials');
    }
};
