#!/bin/bash
set -euo pipefail

# Navigate to the frontend directory relative to where the script is located
cd "$(dirname "$0")/frontend"

echo "=========================================="
echo "Building frontend (Vite) locally..."
echo "=========================================="

if [ -z "${VITE_TURNSTILE_SITE_KEY:-}" ] && ! grep -rqs '^VITE_TURNSTILE_SITE_KEY=.\+' .env .env.local .env.production .env.production.local; then
    echo ""
    echo "  WARNING: VITE_TURNSTILE_SITE_KEY is not defined."
    echo "  The bundle will be generated WITHOUT the Turnstile shield."
    echo "  If the target backend has TURNSTILE_SECRET_KEY configured,"
    echo "  no cashier will be able to log in using this build."
    echo "  See frontend/.env.example to configure it."
    echo ""
fi

npm ci
npm run build

if [ ! -f "dist/index.html" ]; then
    echo "ERROR: The build did not generate dist/index.html"
    exit 1
fi

echo "=========================================="
echo "Build completed: frontend/dist/ ($(du -sh dist | cut -f1))"
echo "=========================================="
echo ""
echo "Versioning and pushing the build to the repository..."

# Go back to the root directory to run git commands
cd ..

# Stage the frontend/dist folder
git add frontend/dist

# Check if there are changes to commit to prevent the script from failing
if git diff --cached --quiet; then
    echo "  -> No new changes detected in frontend/dist. Skipping commit and push."
else
    git commit -m "build: frontend dist for production"
    git push origin main
    echo "  -> Successfully committed and pushed to origin main!"
fi

echo ""
echo "=========================================="
echo "Next step -> On your Droplet run: bash deploy.sh"
echo "=========================================="