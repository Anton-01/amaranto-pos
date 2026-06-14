<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_promotion', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('promotion_id');

            $table->primary(['product_id', 'promotion_id']);
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_promotion');
    }
};
