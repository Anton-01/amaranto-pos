<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forensic trail of the media module.
     *
     * Append-only by contract: the application inserts and reads, never
     * updates and never deletes. There is no `updated_at` on purpose — a row
     * that can be modified is not evidence.
     *
     * WHY IT DOES NOT REUSE `audit_logs`. The global table records domain
     * mutations keyed by a polymorphic owner. This one has to answer questions
     * that table cannot: which file, under which name AT THE TIME, by which
     * operator identity AT THE TIME, and from which address. The name and the
     * actor are snapshotted here precisely because the file may later be
     * renamed and the user may later be deleted; the evidence must survive
     * both.
     */
    public function up(): void
    {
        Schema::create('media_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable and nullOnDelete: purging a file must not erase the
            // record that it once existed and who removed it.
            $table->uuid('media_file_id')->nullable();

            // Snapshot of the resource identity at the moment of the action.
            $table->string('resource_name')->nullable();
            $table->string('drive_file_id', 120)->nullable();

            // upload, update_metadata, download, preview, share_link_created,
            // share_link_revoked, share_link_accessed, delete, restore,
            // status_change, permissions_updated, file_type_created,
            // file_type_updated, file_type_status_change, file_type_deleted,
            // credentials_updated, credentials_tested, upload_rejected.
            $table->string('action', 60);

            // Actor. The id can go null when the user row is removed, so the
            // name and email are snapshotted as text alongside it.
            $table->uuid('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();

            // Free-form context: old/new metadata, rejection reason, share
            // link id, expiration, byte size.
            $table->jsonb('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Written explicitly by the logger, not by Eloquent timestamps:
            // this table has no `updated_at` to pair with.
            $table->timestampTz('created_at')->nullable();

            $table->foreign('media_file_id')->references('id')->on('media_files')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // The viewer filters by file, by action and by actor, always
            // ordered by recency.
            $table->index(['media_file_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_audit_logs');
    }
};
