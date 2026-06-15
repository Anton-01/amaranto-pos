<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('promotion_id')->nullable();
            $table->integer('quantity');
            $table->decimal('base_price_at_sale', 12, 2);
            $table->decimal('discount_amount_at_sale', 12, 2)->default(0);
            $table->decimal('final_price_at_sale', 12, 2);
            $table->decimal('tax_amount_at_sale', 12, 2)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
