<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->decimal('amount', 12, 2);
            $table->text('description');
            $table->jsonb('immutable_snapshot');
            $table->timestampTz('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });

        DB::statement("ALTER TABLE petty_cash_transactions ADD COLUMN reason petty_cash_reason NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_transactions');
    }
};
