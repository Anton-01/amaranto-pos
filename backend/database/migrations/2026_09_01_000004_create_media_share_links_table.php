<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Controlled share links issued by the POS.
     *
     * These are NOT Google "anyone with the link" grants. The file in Drive
     * stays private; a share link is a token this application validates before
     * streaming the bytes itself. That inversion is what makes expiration,
     * download caps and instant revocation possible — none of which Drive's
     * public link can offer.
     *
     * The token is stored HASHED (SHA-256), exactly like a password reset
     * token: whoever reads this table cannot rebuild a working URL.
     */
    public function up(): void
    {
        Schema::create('media_share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_file_id');

            // SHA-256 of the raw token. Unique so a lookup is a single index
            // hit and a collision is impossible by construction.
            $table->string('token_hash', 64)->unique();

            // First characters of the raw token, for the admin table to
            // identify a link without being able to reconstruct it.
            $table->string('token_preview', 16);

            // view = inline preview only; download = attachment allowed.
            $table->string('permission', 20)->default('view');

            // Null is not allowed: every link expires. "Forever" is exactly
            // the property that turns a share into a leak.
            $table->timestampTz('expires_at');

            // Optional usage cap. Null means "unlimited within the window".
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('download_count')->default(0);

            // Manual revocation. A revoked link stays in the table forever as
            // evidence; it is never deleted.
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();

            $table->timestampTz('last_accessed_at')->nullable();
            $table->string('last_accessed_ip', 45)->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('media_file_id')->references('id')->on('media_files')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['media_file_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_share_links');
    }
};
