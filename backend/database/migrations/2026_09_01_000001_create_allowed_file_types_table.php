<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic whitelist of uploadable file types.
     *
     * This table IS the upload policy. The API does not carry a hard-coded
     * list of extensions anywhere: every upload resolves the row matching the
     * submitted extension, and an absent or inactive row rejects the file
     * before a single byte reaches Google Drive.
     *
     * Keeping the policy in a table (instead of config/media.php) is what lets
     * an administrator open ".xlsx" for the accounting team, or shut ".svg"
     * during an incident, without a deploy.
     */
    public function up(): void
    {
        Schema::create('allowed_file_types', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Lowercase, no dot. Unique because the lookup at upload time is
            // "one extension -> one policy"; two competing rows would make the
            // effective limit depend on row order.
            $table->string('extension', 20)->unique();

            // Canonical MIME the browser is expected to send. Validation
            // checks BOTH this and the extension: a .png renamed to .pdf
            // fails on the MIME, and a real PDF renamed to .png fails on the
            // extension.
            $table->string('mime_type', 150);

            // Human label shown in the admin table and in error messages.
            $table->string('label', 100);

            // Per-type ceiling in kilobytes. Always clamped at read time by
            // config('media.max_upload_kb'), which no UI can raise.
            $table->unsignedInteger('max_size_kb')->default(2048);

            // Drives the preview engine and the library filters: document,
            // image, spreadsheet, presentation, archive, other.
            $table->string('category', 30)->default('document');

            // Kill switch. An inactive row keeps its history and its audit
            // trail but stops accepting new uploads immediately.
            $table->boolean('is_active')->default(true);

            // Rows seeded by the system are protected from deletion so the
            // library can never end up unable to accept an image.
            $table->boolean('is_system')->default(false);

            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Serves the hot path: "is this extension currently accepted?".
            $table->index(['extension', 'is_active']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_file_types');
    }
};
