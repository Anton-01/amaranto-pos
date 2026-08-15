#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# Cronos POS — Entrypoint de PRODUCCION (agnostico al rol del contenedor)
#
# Este script NO decide que proceso corre el contenedor. Solo deja el
# framework listo (enlace de storage + caches compiladas) y cede el control
# con `exec "$@"` al `command:` declarado en docker-compose.prod.yml.
#
#   backend       -> php artisan serve --host=0.0.0.0 --port=8000
#   reverb        -> php artisan reverb:start --host=0.0.0.0 --port=8080
#   scheduler     -> php artisan schedule:work
#   queue-worker  -> php artisan queue:work --tries=3 --timeout=90
#
# POR QUE: la version anterior terminaba en
#     php artisan queue:work ... &
#     php artisan reverb:start ... &
#     exec php artisan serve ...
# es decir, ejecucion RIGIDA sin `"$@"`. Como los cuatro servicios comparten
# la MISMA imagen y el ENTRYPOINT siempre gana sobre el `command:`, cada
# contenedor levantaba la pila completa y el `command:` del compose se
# descartaba en silencio. En produccion eso significaba:
#
#   1. Tres servidores HTTP identicos en :8000 (dos de ellos inalcanzables,
#      pero consumiendo memoria y una conexion a PostgreSQL cada uno).
#   2. Tres servidores Reverb compitiendo por el puerto 8080; solo el del
#      contenedor con el puerto publicado recibia trafico y los otros dos
#      reintentaban el bind en bucle.
#   3. Tres consumidores de la misma cola de Redis: un job podia tocarle a
#      cualquiera de ellos, con los logs repartidos entre tres contenedores.
#   4. `schedule:work` NUNCA se ejecuto. El cierre automatico de caja de las
#      21:00 y la poda de telemetria dependian por completo del cron del host.
#
# Regla de oro que impone este archivo: UN proceso de primer plano por
# contenedor, elegido desde el compose, no desde la imagen.
# ---------------------------------------------------------------------------

if [ "$#" -eq 0 ]; then
    echo "✖ Entrypoint sin comando: define 'command:' en docker-compose.prod.yml" >&2
    echo "  (o deja que el CMD por defecto de backend/Dockerfile.prod lo provea)." >&2
    exit 1
fi

echo "=== Cronos POS Backend — Production Entrypoint ==="
echo "→ Comando destino: $*"

# --- Preparacion compartida ------------------------------------------------
# Estas tareas corren en TODOS los roles a proposito. En produccion no hay
# bind-mount: cada contenedor tiene su propia capa de escritura, asi que no
# se pisan entre si y ninguno depende de que otro haya compilado antes.
# Ademas cada rol las necesita de verdad:
#   config:cache -> los cuatro leen configuracion en cada arranque
#   route:cache  -> backend sirve rutas; los workers las resuelven al generar
#                   URLs firmadas en correos y notificaciones
#   view:cache   -> el queue worker renderiza Blade al enviar los Mailables
#   event:cache  -> el mapa de listeners alimenta la telemetria de jobs
# Ninguna toca la base de datos: las migraciones son responsabilidad
# exclusiva de deploy.sh, que las corre una sola vez contra `backend`.

echo "→ Ensuring storage symlink..."
rm -rf public/storage
php artisan storage:link --force || true

echo "→ Building production caches..."
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

# --- Cesion del control ----------------------------------------------------
# OBLIGATORIO: `exec` reemplaza el proceso del shell por el comando destino,
# de modo que este quede como PID 1 y reciba directamente SIGTERM. Sin `exec`,
# el shell se quedaria como PID 1 y `docker compose stop` mataria el script
# sin dar apagado ordenado al worker (jobs a medias) ni a Reverb (sockets
# cortados en seco), forzando el SIGKILL de los 10 s.
echo "→ Cediendo el control a: $*"
exec "$@"
