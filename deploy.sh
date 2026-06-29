#!/bin/bash
# ==============================================================
# Cronos POS — Script de Despliegue de Produccion
# Ejecutar en el Droplet: bash deploy.sh
# ==============================================================

set -euo pipefail

export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

APP_DIR="/opt/cronos-pos"
COMPOSE_FILE="docker-compose.prod.yml"
LOG_FILE="/var/log/cronos-deploy.log"

log() {
    local current_time=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[${current_time}] $1" | tee -a "$LOG_FILE"
}

log "=========================================="
log "Iniciando despliegue de Cronos POS"
log "=========================================="

cd "$APP_DIR"

# 1. Pull de los ultimos cambios
log "→ Paso 1/6: Descargando cambios de main..."
git pull origin main --ff-only

# 2. Compilar y levantar contenedores
log "→ Paso 2/6: Compilando imagenes y levantando servicios..."
docker compose -f "$COMPOSE_FILE" up --build -d

# 3. Esperar a que el backend este saludable
log "→ Paso 3/6: Esperando que el backend inicie..."
RETRIES=0
MAX_RETRIES=30
until docker compose -f "$COMPOSE_FILE" exec backend php artisan about > /dev/null 2>&1; do
    RETRIES=$((RETRIES + 1))
    if [ "$RETRIES" -ge "$MAX_RETRIES" ]; then
        log "ERROR: El backend no respondio despues de ${MAX_RETRIES} intentos"
        exit 1
    fi
    sleep 2
done
log "   Backend operativo."

# 4. Ejecutar migraciones de produccion
log "→ Paso 4/6: Ejecutando migraciones..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan migrate --force

# 5. Optimizar caches de produccion
log "→ Paso 5/6: Optimizando caches..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan config:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan route:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan event:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan view:cache

# 6. Reiniciar queue worker para liberar memoria
log "→ Paso 6/6: Reiniciando queue worker..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan queue:restart

# Limpiar imagenes huerfanas
log "→ Limpiando imagenes no utilizadas..."
docker image prune -f

log "=========================================="
log "Despliegue completado exitosamente"
log "=========================================="
