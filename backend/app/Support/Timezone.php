<?php

namespace App\Support;

use DateTimeZone;
use RuntimeException;

/**
 * Fuente unica de verdad de la zona horaria del negocio.
 *
 * Toda la pila esta homologada a `America/Mexico_City` en tres capas (ver
 * CONTEXT.md seccion 53):
 *
 *   1. Docker  — `TZ` en los contenedores (reloj del SO) y `timezone` en el
 *                servidor PostgreSQL de desarrollo.
 *   2. Laravel — `config('app.timezone')`, que el framework aplica con
 *                `date_default_timezone_set()` en el arranque. Desde ahi, TODO
 *                `Carbon::now()` / `now()` / `Carbon::parse()` sin argumento de
 *                zona ya nace en hora local: por eso el codigo NO debe volver a
 *                escribir la cadena a mano.
 *   3. Postgres — `SET TIME ZONE` por sesion via `config/database.php`
 *                 (seccion 47), tanto en la base local como en la Managed.
 *
 * Esta clase existe para los dos casos en que `Carbon` por si solo no basta:
 * convertir un instante ya hidratado (`->timezone(...)` al serializar) y
 * agrupar por dia/hora local dentro de SQL crudo (`AT TIME ZONE ...`).
 */
final class Timezone
{
    /** Zona del negocio si `APP_TIMEZONE` no esta definida en el entorno. */
    public const DEFAULT = 'America/Mexico_City';

    /**
     * Zona horaria efectiva de la aplicacion.
     *
     * Se lee de `config('app.timezone')` para que cambiarla sea UNA edicion en
     * el `.env` y no una caceria de literales por todo el codigo.
     */
    public static function app(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : self::DEFAULT;
    }

    /**
     * La misma zona, lista para incrustarse en SQL crudo.
     *
     * `AT TIME ZONE` no admite un binding posicional en la posicion en que lo
     * usamos (va dentro de expresiones de `GROUP BY` que deben coincidir
     * caracter por caracter con las del `SELECT`), asi que el valor viaja
     * interpolado. Para que un `.env` mal escrito —o manipulado— no se
     * convierta en un vector de inyeccion, se valida contra el catalogo de
     * zonas de PHP ANTES de entrecomillarlo: si no es un identificador IANA
     * real, la consulta no llega a construirse.
     *
     * @return string El identificador entrecomillado, p. ej. `'America/Mexico_City'`
     */
    public static function sqlLiteral(): string
    {
        $timezone = self::app();

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException(
                "Zona horaria invalida en app.timezone: [{$timezone}]. "
                . 'Debe ser un identificador IANA (p. ej. America/Mexico_City).'
            );
        }

        return "'" . $timezone . "'";
    }
}
