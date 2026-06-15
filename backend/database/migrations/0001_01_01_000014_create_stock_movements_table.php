<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('user_id');
            $table->integer('quantity');
            $table->decimal('cost_price_at_movement', 12, 2);
            $table->timestamp('created_at')->nullable();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
        });

        DB::statement("ALTER TABLE stock_movements ADD COLUMN type stock_movement_type NOT NULL");
        DB::statement("ALTER TABLE stock_movements ADD COLUMN reason stock_movement_reason");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
