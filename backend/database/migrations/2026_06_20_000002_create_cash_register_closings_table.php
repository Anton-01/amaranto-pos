<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_closings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_register_id')->unique();
            $table->uuid('closed_by');
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('declared_amount', 12, 2);
            $table->decimal('difference_amount', 12, 2);
            $table->jsonb('payment_breakdown');
            $table->timestampTz('created_at');

            $table->foreign('cash_register_id')
                ->references('id')->on('cash_registers')->onDelete('restrict');
            $table->foreign('closed_by')
                ->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_closings');
    }
};
