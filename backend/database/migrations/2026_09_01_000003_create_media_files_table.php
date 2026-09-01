<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog of the media library.
     *
     * The bytes live in Google Drive; this table is the index the POS reasons
     * about. It deliberately keeps a full local copy of the metadata (name,
     * size, mime, dimensions, checksum) so the library grid can be rendered,
     * filtered and audited without a single call to Google — the network trip
     * happens only when somebody actually opens or downloads a file.
     */
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Drive's own identifier. Nullable only for the brief window in
            // which a row is being created; a persisted row always has one.
            $table->string('drive_file_id', 120)->nullable()->index();
            $table->string('drive_folder_id', 120)->nullable();

            // Name shown in the library. Editable — it is the "title" of the
            // WordPress-style metadata modal.
            $table->string('name');

            // Name of the file as it arrived, kept verbatim for forensics.
            $table->string('original_name');

            // Sanitized name actually written to Drive.
            $table->string('storage_name');

            $table->string('extension', 20);
            $table->string('mime_type', 150);
            $table->string('category', 30)->default('document');
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Accessibility and SEO metadata of the WordPress-style modal.
            $table->string('alt_text')->nullable();
            $table->text('description')->nullable();

            // Intrinsic dimensions, resolved server-side for images only.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // SHA-256 of the uploaded bytes. Detects duplicates before the
            // upload and proves the stored object was not swapped afterwards.
            $table->string('checksum', 64)->nullable()->index();

            /*
             * Effective privacy of the object in Drive, as enforced by this
             * application after upload:
             *  - private:    only the service account can read it.
             *  - restricted: the service account plus the explicitly granted
             *                corporate accounts.
             * "public" is deliberately NOT a value: nothing in this module may
             * create an "anyone with the link" grant on Drive.
             */
            $table->string('visibility', 20)->default('private');

            // Kill switch of the library entry. An archived file keeps its
            // bytes and its history but disappears from pickers.
            $table->boolean('is_active')->default(true);

            $table->uuid('uploaded_by')->nullable();
            $table->uuid('updated_by')->nullable();

            // Soft delete + the forensic columns of the global trash module.
            $table->uuid('deleted_by')->nullable();
            $table->string('deletion_reason')->nullable();
            $table->softDeletesTz();

            $table->timestampsTz();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();

            // The library grid always filters by category and/or status and
            // orders by recency; this composite serves that query directly.
            $table->index(['category', 'is_active']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
