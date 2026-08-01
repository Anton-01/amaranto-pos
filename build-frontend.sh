#!/bin/bash
# ==============================================================
# Cronos POS — Build Local del Frontend (Fase 7 — Deploy Agil)
# Ejecutar en la MAQUINA DEL DESARROLLADOR antes de cada push
# a produccion. Genera frontend/dist/ que se versiona en git y
# que el Droplet solo empaqueta dentro de Nginx (sin Node.js).
#
# Uso: bash build-frontend.sh
# ==============================================================

set -euo pipefail

cd "$(dirname "$0")/frontend"

echo "=========================================="
echo "Compilando frontend (Vite) en local..."
echo "=========================================="

# --------------------------------------------------------------
# Fase 9 — Guardia del escudo anti-bots.
# La site key de Turnstile se incrusta en el bundle en tiempo de
# build. Si el backend de produccion tiene TURNSTILE_SECRET_KEY
# pero este bundle sale sin site key, el frontend no enviara token
# y el backend rechazara TODOS los logins (ERR_AUTH_CAPTCHA_REQUIRED).
# --------------------------------------------------------------
if [ -z "${VITE_TURNSTILE_SITE_KEY:-}" ] && ! grep -rqs '^VITE_TURNSTILE_SITE_KEY=.\+' .env .env.local .env.production .env.production.local; then
    echo ""
    echo "  AVISO: VITE_TURNSTILE_SITE_KEY no esta definida."
    echo "  El bundle saldra SIN escudo Turnstile."
    echo "  Si el backend destino tiene TURNSTILE_SECRET_KEY configurada,"
    echo "  ningun cajero podra iniciar sesion con este build."
    echo "  Ver frontend/.env.example para configurarla."
    echo ""
fi

npm ci
npm run build

if [ ! -f "dist/index.html" ]; then
    echo "ERROR: El build no genero dist/index.html"
    exit 1
fi

echo "=========================================="
echo "Build completado: frontend/dist/ ($(du -sh dist | cut -f1))"
echo ""
echo "Siguiente paso — versionar y publicar el build:"
echo "  git add frontend/dist"
echo "  git commit -m 'build: frontend dist para produccion'"
echo "  git push origin main"
echo ""
echo "Despues, en el Droplet: bash deploy.sh"
echo "=========================================="
