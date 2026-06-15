#!/bin/sh
set -e

echo "=== Cronos POS Backend - Docker Entrypoint ==="

# 1. Install dependencies if vendor is missing or incomplete
if [ ! -f "vendor/autoload.php" ]; then
    echo "→ Installing Composer dependencies..."
    composer install --optimize-autoloader
fi

# 2. Fix storage permissions for Laravel
echo "→ Setting storage permissions..."
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 3. Environment file setup
if [ ! -f ".env" ]; then
    echo "→ Creating .env from .env.example..."
    cp .env.example .env
    php artisan key:generate --force
fi

# 4. Install Reverb if not present (before migrations)
if ! composer show laravel/reverb > /dev/null 2>&1; then
    echo "→ Installing Laravel Reverb..."
    composer require laravel/reverb --with-all-dependencies
    php artisan vendor:publish --provider="Laravel\Reverb\ReverbServiceProvider" --force 2>/dev/null || true
fi

# 5. Run migrations and seeders
echo "→ Running migrations with seed..."
php artisan migrate:fresh --seed --force

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
