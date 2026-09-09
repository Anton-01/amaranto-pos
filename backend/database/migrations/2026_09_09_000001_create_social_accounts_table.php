<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credentials of the social networks the library can publish to.
     *
     * One row per provider connection: a Facebook Page, an Instagram Business
     * account, a WhatsApp sender. The design mirrors `drive_credentials` on
     * purpose — the identity against a third party lives encrypted in the
     * database and is rotated from the panel, never from a redeploy.
     *
     * WHY THE TOKEN IS A `text` AND NOT A `string`. The column holds the
     * Laravel `encrypted` cast output, not the raw token: a 200-character Meta
     * long-lived token becomes several hundred bytes of base64 once the
     * ciphertext, the IV and the MAC are serialized together. A `varchar(255)`
     * would truncate it and the failure would surface hours later as an
     * unexplainable "invalid signature".
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // facebook | instagram | whatsapp. Not an enum: adding a network
            // must not require an ALTER TYPE on a live database.
            $table->string('provider', 20);

            // Human label for the panel — "Página principal", "IG sucursal
            // centro". The provider ids below are unreadable by design.
            $table->string('label');

            // Encrypted at rest through the model's `encrypted` cast.
            $table->text('access_token')->nullable();

            // Facebook Page id. The Graph node every feed publication targets.
            $table->string('page_id', 64)->nullable();

            // Instagram Business user id (the IG-User node), which is NOT the
            // @handle and NOT the Facebook Page id, even though it is reached
            // through the Page. Confusing the three is the single most common
            // cause of a 100 "Unsupported get request" from Graph.
            $table->string('ig_user_id', 64)->nullable();

            // WhatsApp Cloud API sender. Reserved for the real integration;
            // the current publisher is a mock (see WhatsAppService).
            $table->string('phone_number_id', 64)->nullable();

            // Meta Business account that owns the assets above. Kept for
            // diagnostics: two tokens from different businesses look identical.
            $table->string('business_account_id', 64)->nullable();

            /*
             * When Meta says the token dies. Nullable because a Page token
             * derived from a long-lived user token has no expiry of its own,
             * and pretending it expires "in 60 days" would age it out of the
             * panel while it is still perfectly valid.
             */
            $table->timestampTz('token_expires_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();

            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // The publisher's only lookup: "the live connection for this
            // network". Ordered so the index serves it in a single hit.
            $table->index(['provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
