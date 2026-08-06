<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gestion idempotente de tipos ENUM nativos de PostgreSQL.
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * `migrate:fresh` borra TABLAS, no tipos. Los ENUM nativos sobreviven al
 * borrado y, en la siguiente corrida, el `CREATE TYPE` de la migracion choca
 * contra el superviviente:
 *
 *     SQLSTATE[42710]: Duplicate object: type "discount_type" already exists
 *
 * Laravel ofrece `migrate:fresh --drop-types` para esto, pero basta que
 * alguien —un entrypoint de Docker, un script de CI, un `php artisan
 * migrate:fresh` a mano— omita la bandera para que el arranque completo
 * reviente. Confiar en que nadie la olvide no es una garantia; que las
 * migraciones sean idempotentes, si.
 *
 * ESTRATEGIA DE `create()`
 *
 *   1. El tipo no existe            -> se crea.
 *   2. Existe y NADIE lo usa        -> se recrea. Garantiza que la definicion
 *                                      corresponde a la del archivo de
 *                                      migracion y no a un residuo antiguo con
 *                                      otros valores.
 *   3. Existe y hay columnas que
 *      dependen de el               -> no se toca. Recrearlo destruiria datos,
 *                                      y su presencia significa que la
 *                                      migracion ya corrio sobre esta base.
 *
 * Nunca se usa `DROP TYPE ... CASCADE`: eliminaria en silencio las columnas
 * que dependen del tipo. En una base de datos financiera eso es inaceptable.
 */
class PostgresEnum
{
    /**
     * Crea el tipo ENUM de forma segura ante corridas repetidas.
     *
     * @param  list<string>  $values
     */
    public static function create(string $name, array $values): void
    {
        self::assertValidIdentifier($name);

        if ($values === []) {
            throw new InvalidArgumentException("El ENUM '{$name}' necesita al menos un valor.");
        }

        if (self::exists($name)) {
            if (self::isInUse($name)) {
                return;
            }

            DB::statement('DROP TYPE '.self::quoteIdentifier($name));
        }

        DB::statement(sprintf(
            'CREATE TYPE %s AS ENUM (%s)',
            self::quoteIdentifier($name),
            implode(', ', array_map([self::class, 'quoteLiteral'], $values))
        ));
    }

    /**
     * Crea varios tipos de una pasada.
     *
     * @param  array<string, list<string>>  $definitions  nombre => valores
     */
    public static function createMany(array $definitions): void
    {
        foreach ($definitions as $name => $values) {
            self::create($name, $values);
        }
    }

    /**
     * Añade un valor a un ENUM existente.
     *
     * PostgreSQL prohibe `ALTER TYPE ... ADD VALUE` dentro de un bloque
     * transaccional: la migracion que lo invoque debe declarar
     * `public $withinTransaction = false`.
     */
    public static function addValue(string $name, string $value): void
    {
        self::assertValidIdentifier($name);

        DB::statement(sprintf(
            'ALTER TYPE %s ADD VALUE IF NOT EXISTS %s',
            self::quoteIdentifier($name),
            self::quoteLiteral($value)
        ));
    }

    /** Elimina el tipo si existe. Sin CASCADE, a proposito. */
    public static function drop(string ...$names): void
    {
        foreach ($names as $name) {
            self::assertValidIdentifier($name);
            DB::statement('DROP TYPE IF EXISTS '.self::quoteIdentifier($name));
        }
    }

    /** ¿El tipo existe en el esquema activo? */
    public static function exists(string $name): bool
    {
        self::assertValidIdentifier($name);

        return DB::selectOne(
            'SELECT 1
               FROM pg_type t
               JOIN pg_namespace n ON n.oid = t.typnamespace
              WHERE t.typname = ?
                AND n.nspname = current_schema()',
            [$name]
        ) !== null;
    }

    /**
     * ¿Alguna columna viva declara este tipo?
     *
     * Se excluyen las columnas ya eliminadas (`attisdropped`), que PostgreSQL
     * conserva internamente y darian un falso positivo.
     */
    public static function isInUse(string $name): bool
    {
        self::assertValidIdentifier($name);

        return DB::selectOne(
            'SELECT 1
               FROM pg_attribute a
               JOIN pg_type t ON t.oid = a.atttypid
               JOIN pg_class c ON c.oid = a.attrelid
              WHERE t.typname = ?
                AND a.attnum > 0
                AND NOT a.attisdropped
                AND c.relkind IN (\'r\', \'p\')
              LIMIT 1',
            [$name]
        ) !== null;
    }

    /**
     * Los nombres provienen de constantes en las migraciones, nunca de entrada
     * de usuario, pero un identificador no puede parametrizarse en SQL: se
     * valida en origen antes de interpolarlo.
     */
    private static function assertValidIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Nombre de tipo invalido: '{$name}'.");
        }
    }

    private static function quoteIdentifier(string $name): string
    {
        return '"'.$name.'"';
    }

    private static function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
