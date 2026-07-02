#!/usr/bin/env bash
# ============================================================================
#  NetWix production deploy (DirectAdmin + Laravel 12, PHP 8.3)
#
#  Runs on the server, inside the app root:
#     /home/admin/domains/netwix.online/public_html
#
#  Usage:  ./deploy/deploy.sh [git-ref]      # default: main
#          ./deploy/deploy.sh v0.1.0         # deploy a tagged release
#
#  It never touches .env (created once during initial setup), so the
#  config:cache step always caches the real, correct configuration.
# ============================================================================
set -euo pipefail

REF="${1:-main}"
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"

echo "▶ Deploying ref: $REF  (in $APP_DIR)"

echo "▶ Fetching source…"
git fetch --tags --prune --depth 1 origin
git checkout -f "$REF"

echo "▶ Composer (production)…"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "▶ Front-end build…"
npm ci
npm run build

echo "▶ Database migrate…"
php artisan migrate --force

echo "▶ Storage symlink…"
php artisan storage:link || true

echo "▶ Root .htaccess (rewrite to public/)…"
cp -f public/.htaccess-root .htaccess

echo "▶ Rebuilding caches…"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "▶ Restart PHP-FPM…"
sudo systemctl restart php-fpm83.service || true

echo "✅ Deploy complete: $REF"
