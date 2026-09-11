<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Que se vendio durante el turno, congelado dentro del arqueo.
     *
     * POR QUE SE GUARDA Y NO SE RECALCULA. El desglose por metodo de pago ya
     * vive dentro del cierre porque un arqueo es la foto de un turno, no una
     * consulta que se vuelve a ejecutar. La lista de productos es exactamente
     * lo mismo: el nombre y el costo unitario del producto pueden cambiar
     * mañana —o el producto puede borrarse— y el cierre de hoy debe seguir
     * diciendo lo que dijo el dia que se firmo.
     *
     * Nullable: los cierres anteriores a esta columna nacieron sin lista y no
     * pueden completarse. El modelo es insert-only, asi que rellenarlos seria
     * reescribir un ledger. La pantalla distingue "sin productos" de "este
     * cierre es anterior al desglose" leyendo null contra [].
     */
    public function up(): void
    {
        Schema::table('cash_register_closings', function (Blueprint $table) {
            $table->jsonb('product_breakdown')->nullable()->after('payment_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_closings', function (Blueprint $table) {
            $table->dropColumn('product_breakdown');
        });
    }
};
