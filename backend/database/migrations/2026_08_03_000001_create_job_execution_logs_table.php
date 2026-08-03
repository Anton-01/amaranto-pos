<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora forense de ejecucion de jobs en segundo plano (Fase 10).
 *
 * Una fila por INTENTO, no por job: si la cola reintenta un job tres veces,
 * quedan tres filas y el histórico conserva por que fallo cada una. La fila
 * nace en estado `pending` al encolar y va mutando (`running` -> `success` |
 * `failed`) conforme el worker la procesa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS job_execution_status');
        DB::statement("CREATE TYPE job_execution_status AS ENUM ('pending', 'running', 'success', 'failed')");

        Schema::create('job_execution_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // UUID que Laravel asigna al payload en la cola. Es la llave que
            // permite correlacionar los eventos JobQueued / JobProcessing /
            // JobProcessed / JobFailed, emitidos en procesos distintos.
            $table->uuid('job_uuid')->nullable();

            $table->string('job_name', 255);
            $table->string('display_name', 255)->nullable();
            $table->string('connection', 60)->nullable();
            $table->string('queue', 60)->nullable();

            // 0 = encolado, aun sin ejecutar. 1..n = numero de intento real.
            $table->unsignedSmallInteger('attempt')->default(0);

            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('exception_class', 255)->nullable();
            $table->text('exception_message')->nullable();
            $table->text('exception_trace')->nullable();

            // Contexto libre: tags del job, parametros no sensibles, etc.
            $table->jsonb('context')->nullable();

            // Quien disparo el job. Null = proceso automatico sin sesion.
            $table->uuid('triggered_by')->nullable();
            $table->string('trigger_source', 20)->default('system');

            $table->timestampsTz();

            $table->foreign('triggered_by')->references('id')->on('users')->onDelete('set null');

            // Correlacion evento -> fila: el worker busca por (uuid, intento).
            $table->unique(['job_uuid', 'attempt']);

            // Histórico paginado: siempre ordenado por fecha descendente.
            $table->index(['created_at']);
            $table->index(['job_name', 'created_at']);
            $table->index(['triggered_by']);
        });

        DB::statement("ALTER TABLE job_execution_logs ADD COLUMN status job_execution_status NOT NULL DEFAULT 'pending'");

        // El panel filtra por estatus y agrega el catalogo por nombre de job.
        DB::statement('CREATE INDEX job_execution_logs_status_created_at_index ON job_execution_logs (status, created_at DESC)');
        DB::statement('CREATE INDEX job_execution_logs_job_name_status_index ON job_execution_logs (job_name, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_execution_logs');
        DB::statement('DROP TYPE IF EXISTS job_execution_status');
    }
};
