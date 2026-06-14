<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->decimal('opening_balance', 12, 2);
            $table->decimal('expected_closing_balance', 12, 2)->default(0);
            $table->decimal('actual_closing_balance', 12, 2)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
