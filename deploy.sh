#!/bin/bash
# Apex Negotiations - Forge Deploy Script
# Copy this into your Forge site's deployment script, or reference it directly.
#
# Usage in Forge: bash deploy.sh

set -e

cd /home/forge/apexnegotiations.com

# Pull latest code
git pull origin $FORGE_SITE_BRANCH

# PHP dependencies
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Database migrations
php artisan migrate --force

# Python service (setup venv + deps, restart if systemd is installed)
if [ -f "python/setup.sh" ]; then
    echo "==> Setting up Python service..."
    bash python/setup.sh
fi

# Frontend assets
npm ci --no-audit
npm run build

# Laravel optimization
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Restart queue workers
php artisan queue:restart

# Restart Reverb WebSocket server (if managed by supervisor)
if command -v supervisorctl &> /dev/null; then
    sudo supervisorctl restart reverb 2>/dev/null || true
fi

echo "==> Deployment complete."
