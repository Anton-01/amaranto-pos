<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Guarda para las pruebas que necesitan PostgreSQL de verdad.
 *
 * El esquema del POS usa ENUMs nativos, JSONB, `ilike` y agregados con
 * `FILTER (WHERE …)`: no hay forma de emularlo en sqlite. En lugar de fallar
 * con una pared de errores de conexion cuando el servicio no esta arriba, la
 * prueba se salta con una instruccion accionable.
 */
trait RequiresPostgres
{
    protected function skipUnlessPostgresAvailable(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Esta prueba requiere PostgreSQL. Levanta el servicio con `docker compose up -d postgres` '
                .'y crea la base de pruebas: CREATE DATABASE cronos_pos_test;'
            );
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped(
                'PostgreSQL no esta disponible en '
                .config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port')
                .' — '.$e->getMessage()
            );
        }
    }
}
