<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-process outbound email credentials.
     *
     * The application no longer depends on a single MAIL_* block in .env: each
     * kind of outbound traffic ("jobs", "users", ...) owns one row here with
     * its own provider credentials, sender identity, subject and recipient
     * list. At send time the transport is rebuilt from the matching row, so
     * rotating a SendGrid key or redirecting a report is a database write, not
     * a redeploy.
     */
    public function up(): void
    {
        Schema::create('email_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Business process that owns this configuration. Unique so that a
            // lookup by process can never return two competing transports.
            $table->string('process_type', 60)->unique();

            // Transport family resolved against config/mailing.php providers.
            $table->string('provider', 40)->default('sendgrid');

            // Stored through the "encrypted" cast: the ciphertext is longer
            // than the raw key, hence text instead of a bounded varchar.
            $table->text('api_key')->nullable();

            $table->string('from_email');
            $table->string('from_name');

            // Recipient list as a JSON array — a process usually notifies a
            // group (accounting + management), not a single mailbox.
            $table->jsonb('target_emails')->default('[]');

            $table->string('subject');

            // Kill switch: an inactive row is ignored by the dispatcher and the
            // process silently skips the notification instead of failing.
            $table->boolean('is_active')->default(true);

            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Serves the only hot-path query: the active row for a process.
            $table->index(['process_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_configurations');
    }
};
