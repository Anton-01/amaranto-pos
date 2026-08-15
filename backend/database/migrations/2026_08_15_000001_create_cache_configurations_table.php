<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-module cache policy.
     *
     * The heavy read paths (dashboard KPIs, hourly trend, top products,
     * monthly analytics, POS catalog) no longer carry a TTL hardcoded in PHP:
     * each one owns a row here holding how many minutes its payload may be
     * served from Redis and whether caching is enabled at all. Widening the
     * refresh window of the dashboard therefore becomes a database write from
     * the admin UI, not a redeploy.
     *
     * A missing row means "no policy configured" and the module falls back to
     * querying PostgreSQL directly, so the system degrades to its previous
     * behaviour instead of failing.
     */
    public function up(): void
    {
        Schema::create('cache_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Logical module that owns the policy. Unique so a lookup can
            // never resolve to two competing durations for the same payload.
            $table->string('module_name', 60)->unique();

            // Refresh window in minutes. Stored as an integer (not a duration
            // string) so it can be handed straight to Cache::remember().
            $table->integer('duration_minutes')->default(15);

            // Kill switch: an inactive policy makes the module bypass Redis
            // and read live from PostgreSQL, which is the escape hatch when a
            // stale payload is worse than the extra query cost.
            $table->boolean('is_active')->default(true);

            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Serves the only hot-path query: the active policy for a module.
            $table->index(['module_name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_configurations');
    }
};
