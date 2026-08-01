<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ALTER TYPE ... ADD VALUE no puede ejecutarse dentro de un bloque
     * transaccional en PostgreSQL, por lo que esta migracion se ejecuta suelta.
     */
    public $withinTransaction = false;

    /**
     * Incorpora el estado 'open' al ciclo de vida de la orden.
     *
     * Una orden 'open' es la cuenta viva de una mesa: existe, acumula items y
     * descuenta inventario, pero todavia no es una venta. Todas las
     * agregaciones financieras del sistema (Dashboard, Analytics, Daily
     * Summary, Cierres de Caja) ya filtran explicitamente por
     * status = 'completed', de modo que las cuentas abiertas quedan fuera de
     * los reportes sin necesidad de tocarlas.
     */
    public function up(): void
    {
        DB::statement("ALTER TYPE order_status ADD VALUE IF NOT EXISTS 'open'");
    }

    public function down(): void
    {
        // PostgreSQL no permite eliminar valores de un ENUM: se recrea el tipo.
        DB::statement("UPDATE orders SET status = 'canceled' WHERE status = 'open'");
        DB::statement('ALTER TABLE orders ALTER COLUMN status DROP DEFAULT');
        DB::statement('ALTER TYPE order_status RENAME TO order_status_old');
        DB::statement("CREATE TYPE order_status AS ENUM ('completed', 'canceled')");
        DB::statement('ALTER TABLE orders ALTER COLUMN status TYPE order_status USING status::text::order_status');
        DB::statement("ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'completed'");
        DB::statement('DROP TYPE order_status_old');
    }
};
