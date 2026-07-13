#!/bin/bash
# ==============================================================
# Cronos POS — Script de Despliegue de Produccion
# Ejecutar en el Droplet: bash deploy.sh
#
# ESTRATEGIA DE BUILD (Fase 7 — Deploy Agil):
#   El bundle de React/Vite NO se compila en el Droplet. Se
#   compila en la maquina del desarrollador ANTES del push:
#
#     1. (Local)   bash build-frontend.sh   -> genera frontend/dist/
#     2. (Local)   git add frontend/dist && git commit && git push
#     3. (Droplet) bash deploy.sh           -> solo empaqueta estaticos
#
#   El backend usa la imagen pre-compilada serversideup/php, por lo
#   que 'docker compose up --build' no compila extensiones de PHP.
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
log "→ Paso 1/7: Descargando cambios de main..."
git pull origin main --ff-only

# 2. Verificar que el frontend fue pre-compilado en local
#    (el Droplet ya NO ejecuta npm install / npm run build)
log "→ Paso 2/7: Verificando build pre-compilado del frontend..."
if [ ! -f "frontend/dist/index.html" ]; then
    log "ERROR: No existe frontend/dist/index.html"
    log "       El frontend debe compilarse en la maquina del desarrollador"
    log "       ANTES del push. Ejecuta en local:"
    log "         bash build-frontend.sh"
    log "         git add frontend/dist && git commit -m 'build: frontend dist' && git push"
    exit 1
fi
log "   Build de frontend encontrado ($(du -sh frontend/dist | cut -f1))."

# 3. Compilar y levantar contenedores (solo empaqueta estaticos +
#    imagenes base pre-compiladas — sin compilacion nativa)
log "→ Paso 3/7: Empaquetando imagenes y levantando servicios..."
docker compose -f "$COMPOSE_FILE" up --build -d

# 4. Esperar a que el backend este saludable
log "→ Paso 4/7: Esperando que el backend inicie..."
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

# 5. Ejecutar migraciones de produccion
log "→ Paso 5/7: Ejecutando migraciones..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan migrate --force

# 6. Optimizar caches de produccion
log "→ Paso 6/7: Optimizando caches..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan config:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan route:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan event:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan view:cache

# 7. Reiniciar queue worker para liberar memoria
log "→ Paso 7/7: Reiniciando queue worker..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan queue:restart

# Limpiar imagenes huerfanas
log "→ Limpiando imagenes no utilizadas..."
docker image prune -f

log "=========================================="
log "Despliegue completado exitosamente"
log "=========================================="
