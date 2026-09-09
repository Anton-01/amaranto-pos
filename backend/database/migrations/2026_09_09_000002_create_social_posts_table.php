<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log and forensic trail of every publication attempt.
     *
     * ONE ROW PER CHANNEL, NOT PER SUBMISSION. An operator who ticks Facebook,
     * Instagram and WhatsApp produces three rows sharing a `batch_id`. That
     * shape is what lets Instagram fail while Facebook succeeds without either
     * outcome being lost or overwritten — a single row per submission would
     * have to pick one status for three different results.
     *
     * The rows are written BEFORE the network is touched, in `pending`, so a
     * worker killed mid-publication leaves evidence that the attempt existed.
     * A table that only records successes cannot answer the one question an
     * operator actually asks: "why did nothing appear on Instagram?".
     */
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * Groups the rows produced by one click on "Publicar Ahora".
             * Plain uuid and not a foreign key: there is no batches table and
             * inventing one would add a row that says nothing the three
             * children do not already say.
             */
            $table->uuid('batch_id')->index();

            // The library image being published. Nullable so the log survives
            // the deletion of the file — evidence outlives its subject.
            $table->uuid('media_file_id')->nullable();

            // The connection used. Also nullable: a rotated-away credential
            // must not erase the history of what it published.
            $table->uuid('social_account_id')->nullable();

            $table->string('provider', 20);

            // pending | publishing | success | failed. See SocialPost.
            $table->string('status', 20)->default('pending');

            $table->text('caption')->nullable();

            /*
             * The public URL handed to the provider. Recorded verbatim because
             * it is the single most useful datum when Meta answers "unable to
             * fetch": it can be pasted into a browser to see whether the link
             * had already expired when their crawler arrived.
             */
            $table->text('image_url')->nullable();

            // Id of the object created at the provider — the Facebook post id,
            // the Instagram media id. The proof the publication exists.
            $table->string('api_response_id', 120)->nullable();

            // Provider error text, kept verbatim. Paraphrasing Meta's message
            // destroys the only information the administrator came for.
            $table->text('error_message')->nullable();

            // Meta's numeric `error.code`, addressable for triage: 190 is an
            // expired token, 4/17/32 are rate limits, 100 is a bad parameter.
            $table->string('error_code', 40)->nullable();

            /*
             * Provider context of a SUCCESSFUL publication — the Instagram
             * creation id, the WhatsApp `simulated` flag. Deliberately separate
             * from `error_message`: a column named for failures that also
             * carries success detail is how a log becomes unreadable.
             */
            $table->jsonb('metadata')->nullable();

            $table->timestampTz('published_at')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('media_file_id')->references('id')->on('media_files')->nullOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // The log viewer's default ordering, and the per-file history the
            // composer shows when it opens.
            $table->index(['media_file_id', 'created_at']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
