#!/bin/bash
set -e

RESTART_GATEWAY=false

for arg in "$@"; do
    case $arg in
        --gateway) RESTART_GATEWAY=true ;;
    esac
done

REPO_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "==> Pulling latest code..."
cd "$REPO_DIR"
git pull

echo "==> Installing PHP dependencies..."
cd "$REPO_DIR/panel"
composer install --no-dev --optimize-autoloader --quiet

echo "==> Building frontend..."
npm ci --silent && npm run build

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Restarting panel services..."
systemctl restart velink-queue velink-reverb velink-agent-listen

if [ "$RESTART_GATEWAY" = true ]; then
    echo "==> Rebuilding and restarting gateway..."
    cd "$REPO_DIR/gateway"
    go build -o /usr/local/bin/velink-gateway ./cmd/gateway
    systemctl restart velink-gateway
fi

echo "==> Done."
