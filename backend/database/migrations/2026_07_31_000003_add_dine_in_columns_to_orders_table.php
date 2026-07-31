<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('table_id')->nullable();
            $table->string('table_name_at_sale', 60)->nullable();
            $table->uuid('waiter_id')->nullable();
            $table->string('waiter_name_at_sale', 150)->nullable();

            $table->foreign('table_id')->references('id')->on('tables')->onDelete('restrict');
            $table->foreign('waiter_id')->references('id')->on('users')->onDelete('set null');

            $table->index('table_id');
        });

        // Una cuenta abierta todavia no tiene forma de pago ni cajon asignado:
        // ambos se resuelven en el cobro (POST /tables/{id}/close).
        DB::statement('ALTER TABLE orders ALTER COLUMN payment_method_id DROP NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN cash_register_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Las ordenes sin cobrar no pueden sobrevivir a la reversion del esquema.
        DB::statement("DELETE FROM orders WHERE status = 'open'");
        DB::statement('ALTER TABLE orders ALTER COLUMN payment_method_id SET NOT NULL');
        DB::statement('ALTER TABLE orders ALTER COLUMN cash_register_id SET NOT NULL');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['table_id']);
            $table->dropForeign(['waiter_id']);
            $table->dropIndex(['table_id']);
            $table->dropColumn(['table_id', 'table_name_at_sale', 'waiter_id', 'waiter_name_at_sale']);
        });
    }
};
