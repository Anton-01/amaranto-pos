<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('cash_register_id');
            $table->uuid('ticket_config_id');
            $table->uuid('payment_method_id');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('iva_total', 12, 2);
            $table->decimal('total', 12, 2);
            $table->text('custom_legend')->nullable();
            $table->uuid('canceled_by')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('cash_register_id')->references('id')->on('cash_registers')->onDelete('restrict');
            $table->foreign('ticket_config_id')->references('id')->on('ticket_configs')->onDelete('restrict');
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('restrict');
            $table->foreign('canceled_by')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE orders ADD COLUMN status order_status NOT NULL DEFAULT 'completed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
