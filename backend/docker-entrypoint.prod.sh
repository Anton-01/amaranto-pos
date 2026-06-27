#!/bin/sh
set -e

echo "=== Cronos POS Backend — Production Entrypoint ==="

# Storage symlink
if [ ! -L "public/storage" ]; then
    echo "→ Creating storage symlink..."
    php artisan storage:link
fi

# Production cache optimization
echo "→ Building production caches..."
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

# Queue worker (background)
echo "→ Starting queue worker..."
php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --quiet &

# Reverb WebSocket server (background)
echo "→ Starting Laravel Reverb on port 8080..."
php artisan reverb:start --host=0.0.0.0 --port=8080 &

# PHP-FPM or artisan serve (foreground)
echo "→ Starting Laravel server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
