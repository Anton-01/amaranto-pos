<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 50)->unique();
            $table->string('parent_sku', 50)->nullable()->index();
            $table->string('name', 150);
            $table->uuid('category_id');
            $table->decimal('cost_price', 12, 2);
            $table->decimal('sale_price', 12, 2);
            $table->integer('current_stock')->default(0);
            $table->integer('minimum_stock')->default(0);
            $table->integer('maximum_stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->string('deletion_reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('restrict');
            $table->foreign('deleted_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
