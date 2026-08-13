#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# CONTAINER_ROLE decide QUE hace este contenedor al arrancar.
#
#   app    -> UNICO responsable del arranque compartido: composer install,
#             .env, migraciones, seeders. Sirve HTTP (8000) y Reverb (8080).
#   worker -> scheduler / queue worker. NO instala dependencias ni toca la
#             base de datos: solo ejecuta el `command:` de docker-compose.
#
# Por que existe esta division: los tres servicios (backend, scheduler,
# queue-worker) comparten la MISMA imagen, el MISMO bind-mount ./backend, el
# MISMO volumen de vendor y la MISMA base de datos. Cuando los tres corrian
# este script completo se pisaban entre si:
#
#   1. Tres `composer install` simultaneos regeneraban el autoload sobre el
#      mismo bootstrap/cache, y el que llegaba tarde moria con
#      "require(bootstrap/cache/packages.php): Failed to open stream".
#   2. Tres `migrate:fresh --seed` simultaneos sobre la misma base: uno
#      DROPEABA todas las tablas mientras otro iba a la mitad de sus
#      migraciones, y el segundo moria con
#      'relation "users" does not exist' al crear una llave foranea.
#   3. El `exec php artisan serve` final ignoraba el `command:` del compose,
#      asi que el scheduler nunca ejecuto `schedule:work`: los tres
#      contenedores levantaban un servidor HTTP identico.
#
# El orden de arranque lo garantiza el healthcheck de `backend` mas el
# `depends_on: condition: service_healthy` de los workers en docker-compose.yml.
# ---------------------------------------------------------------------------
ROLE="${CONTAINER_ROLE:-app}"

echo "=== Cronos POS Backend - Docker Entrypoint (role: ${ROLE}) ==="

if [ "$ROLE" != "app" ]; then
    if [ "$#" -eq 0 ]; then
        echo "✖ CONTAINER_ROLE=${ROLE} requiere un 'command:' en docker-compose.yml." >&2
        exit 1
    fi

    # Red de seguridad por si alguien arranca este servicio sin su dependencia:
    # el healthcheck de `backend` ya deberia haber dejado vendor/ y .env listos.
    WAITED=0
    while [ ! -f vendor/autoload.php ] || [ ! -f .env ]; do
        if [ "$WAITED" -eq 0 ]; then
            echo "→ Esperando a que el contenedor 'backend' publique vendor/ y .env..."
        fi
        if [ "$WAITED" -ge 300 ]; then
            echo "✖ Timeout (5 min): el contenedor 'backend' no completo su arranque." >&2
            exit 1
        fi
        sleep 2
        WAITED=$((WAITED + 2))
    done

    echo "→ Arranque compartido listo. Ejecutando: $*"
    exec "$@"
fi

# 1. Install/update Composer dependencies (always run to pick up new packages)
echo "→ Installing Composer dependencies..."
composer install --optimize-autoloader --no-interaction

# 2. Fix storage permissions for Laravel
echo "→ Setting storage permissions..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 3. Environment file setup (always recreate in Docker for consistency)
echo "→ Setting up .env..."
cp .env.example .env
if [ -z "$(grep '^APP_KEY=base64:' .env)" ]; then
    php artisan key:generate --force
fi

# 4. Install Reverb if not present (before migrations)
if ! composer show laravel/reverb > /dev/null 2>&1; then
    echo "→ Installing Laravel Reverb..."
    composer require laravel/reverb --with-all-dependencies
    php artisan vendor:publish --provider="Laravel\Reverb\ReverbServiceProvider" --force 2>/dev/null || true
fi

# 5. Create storage symlink for public file serving
if [ ! -L "public/storage" ]; then
    echo "→ Creating storage symlink..."
    php artisan storage:link
fi

# 6. Run migrations and seeders
#
# --drop-types es OBLIGATORIO en PostgreSQL: `migrate:fresh` borra tablas pero
# NO los tipos ENUM nativos. Sin la bandera, los tipos sobreviven al borrado y
# la segunda corrida del contenedor muere con:
#     SQLSTATE[42710]: type "discount_type" already exists
#
# Las migraciones ademas crean sus ENUM de forma idempotente
# (App\Support\Database\PostgresEnum), de modo que el arranque funciona aunque
# alguien invoque el comando sin esta bandera. Cinturon y tirantes: este error
# rompia el contenedor entero y no debe poder repetirse.
#
# Solo corre en el rol `app`: es la unica escritura destructiva del arranque y
# no admite dos ejecutores (ver el comentario de CONTAINER_ROLE arriba).
echo "→ Running migrations with seed..."
php artisan migrate:fresh --seed --force --drop-types

# 7. Clear and rebuild caches
echo "→ Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 8. Comando personalizado (si el compose define uno para este servicio)
if [ "$#" -gt 0 ]; then
    echo "→ Ejecutando comando personalizado: $*"
    exec "$@"
fi

# 9. Start Reverb WebSocket server in background
#
# La cola NO se levanta aqui: el servicio `queue-worker` de docker-compose.yml
# es su unico dueño. Dos workers sobre la misma cola duplicaban procesos y
# mezclaban los logs de los jobs.
echo "→ Starting Laravel Reverb on port 8080..."
php artisan reverb:start --host=0.0.0.0 --port=8080 &

# 10. Start Laravel development server (foreground)
echo "→ Starting Laravel server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
