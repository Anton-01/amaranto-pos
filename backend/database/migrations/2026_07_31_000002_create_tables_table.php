<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS table_status');
        DB::statement("CREATE TYPE table_status AS ENUM ('available', 'occupied', 'reserved')");

        Schema::create('tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60);
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('zone', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique('name');
            $table->index('zone');
        });

        DB::statement("ALTER TABLE tables ADD COLUMN status table_status NOT NULL DEFAULT 'available'");
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
        DB::statement('DROP TYPE IF EXISTS table_status');
    }
};
