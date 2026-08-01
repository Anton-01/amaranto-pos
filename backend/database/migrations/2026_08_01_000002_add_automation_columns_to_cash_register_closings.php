<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_closings', function (Blueprint $table) {
            // Distingue el arqueo manual del cierre ejecutado por el scheduler.
            $table->boolean('is_automated')->default(false);
            // Contexto forense del cierre automatico (ej. "declarado no
            // verificado fisicamente"). Nunca editable: nace con el registro.
            $table->text('notes')->nullable();

            $table->index('is_automated');
        });
    }

    public function down(): void
    {
        Schema::table('cash_register_closings', function (Blueprint $table) {
            $table->dropIndex(['is_automated']);
            $table->dropColumn(['is_automated', 'notes']);
        });
    }
};
