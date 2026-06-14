<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->decimal('value', 12, 2);
            $table->timestamp('start_date');
            $table->timestamp('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->string('deletion_reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('deleted_by')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE promotions ADD COLUMN type promotion_type NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
