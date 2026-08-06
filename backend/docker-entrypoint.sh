#!/bin/sh
set -e

echo "=== Cronos POS Backend - Docker Entrypoint ==="

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
echo "→ Running migrations with seed..."
php artisan migrate:fresh --seed --force --drop-types

# 6. Clear and rebuild caches
echo "→ Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# 7. Start queue worker in background
echo "→ Starting queue worker..."
php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 &

# 8. Start Reverb WebSocket server in background
echo "→ Starting Laravel Reverb on port 8080..."
php artisan reverb:start --host=0.0.0.0 --port=8080 &

# 9. Start Laravel development server (foreground)
echo "→ Starting Laravel server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
