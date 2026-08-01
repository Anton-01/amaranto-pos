<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS table_session_status');
        DB::statement("CREATE TYPE table_session_status AS ENUM ('open', 'closed', 'canceled')");

        Schema::create('table_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('table_id');
            $table->uuid('order_id');
            $table->uuid('user_id');
            $table->uuid('closed_by')->nullable();
            $table->unsignedSmallInteger('guests')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('table_id')->references('id')->on('tables')->onDelete('restrict');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['table_id', 'opened_at']);
        });

        DB::statement("ALTER TABLE table_sessions ADD COLUMN status table_session_status NOT NULL DEFAULT 'open'");

        // Garantia de concurrencia a nivel de motor: una mesa no puede tener dos
        // sesiones abiertas simultaneas aunque dos meseros pulsen "Abrir" a la vez.
        // El indice parcial deja pasar cuantas sesiones cerradas historicas haga falta.
        DB::statement('CREATE UNIQUE INDEX table_sessions_one_open_per_table ON table_sessions (table_id) WHERE status = \'open\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('table_sessions');
        DB::statement('DROP TYPE IF EXISTS table_session_status');
    }
};
