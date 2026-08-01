<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            // Etiqueta de renderizado: el frontend selecciona la plantilla
            // visual de la modal de detalles a partir de este tag.
            $table->string('type', 60);
            // Payload inmutable: snapshot completo del evento al momento de
            // generarse. Nunca se reconstruye desde las tablas vivas.
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('type');
            $table->index(['user_id', 'created_at']);
        });

        // El badge de no-leidas es la consulta mas frecuente del modulo: un
        // indice parcial la sirve sin recorrer el historico leido.
        DB::statement('CREATE INDEX system_notifications_unread ON system_notifications (user_id) WHERE read_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notifications');
    }
};
