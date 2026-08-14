#!/bin/bash
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
log "Starting deployment of Cronos POS"
log "=========================================="

cd "$APP_DIR"

# 1. Pull latest changes
log "→ Step 1/7: Pulling latest changes from main..."
git pull origin main --ff-only

# 2. Verify that the frontend was pre-compiled locally
#    (the Droplet NO LONGER runs npm install / npm run build)
log "→ Step 2/7: Verifying pre-compiled frontend build..."
if [ ! -f "frontend/dist/index.html" ]; then
    log "ERROR: frontend/dist/index.html does not exist"
    log "       The frontend must be compiled on the developer's machine"
    log "       BEFORE pushing. Run locally:"
    log "         bash build-frontend.sh"
    log "         git add frontend/dist && git commit -m 'build: frontend dist' && git push"
    exit 1
fi
log "   Frontend build found ($(du -sh frontend/dist | cut -f1))."

# 3. Build and spin up containers (only packages statics +
#    pre-compiled base images — no native compilation)
log "→ Step 3/7: Packaging images and starting services..."
docker compose -f "$COMPOSE_FILE" up --build -d

# 4. Wait for the backend to be healthy
log "→ Step 4/7: Waiting for the backend to start..."
RETRIES=0
MAX_RETRIES=30
until docker compose -f "$COMPOSE_FILE" exec backend php artisan about > /dev/null 2>&1; do
    RETRIES=$((RETRIES + 1))
    if [ "$RETRIES" -ge "$MAX_RETRIES" ]; then
        log "ERROR: Backend did not respond after ${MAX_RETRIES} attempts"
        exit 1
    fi
    sleep 2
done
log "   Backend is operational."

# 5. Run production migrations
log "→ Step 5/7: Running migrations..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan migrate --force

# 6. Optimize production caches
#    Only for `backend`: each container has its own writable layer, so these
#    caches do NOT propagate to reverb/scheduler/queue-worker. Those three
#    build their own on startup (backend/docker-entrypoint.prod.sh) — which is
#    exactly why step 7 restarts them instead of running artisan inside each.
log "→ Step 6/7: Optimizing caches..."
docker compose -f "$COMPOSE_FILE" exec backend php artisan config:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan route:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan event:cache
docker compose -f "$COMPOSE_FILE" exec backend php artisan view:cache

# 7. Restart the long-running single-process containers so they re-run the
#    entrypoint against the new code (and rebuild their own caches).
log "→ Step 7/7: Restarting Reverb, Queue Worker and Scheduler..."

# Restart containers that run infinite processes
docker compose -f "$COMPOSE_FILE" restart reverb scheduler queue-worker

# Clean up dangling images
log "→ Cleaning up unused images..."
docker image prune -f

log "=========================================="
log "Deployment completed successfully"
log "=========================================="