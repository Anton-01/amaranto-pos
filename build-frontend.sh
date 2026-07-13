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
