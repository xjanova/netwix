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

# Maintenance mode covers the risky window: the moment `git checkout` swaps the
# source, the old compiled views/config/opcache still reference the old tree, so a
# live request can hit a half-updated view and 500 (seen 2026-07-03: watch/vertical
# "unexpected end of file"). `down` returns a clean 503 instead. The trap guarantees
# the site is brought back up even if a step fails midway.
trap 'php artisan up >/dev/null 2>&1 || true' EXIT
echo "▶ Maintenance mode ON…"
php artisan down --render="errors.503" --retry=15 || true

echo "▶ Fetching source…"
# Check out the freshly-fetched REMOTE ref via FETCH_HEAD. Using `git checkout -f "$REF"`
# here was a trap: it checks out the LOCAL branch, and a shallow prod clone never
# fast-forwards local `main`, so `deploy.sh main` could silently roll production BACK to
# a stale local `main` (hit 2026-07-04). FETCH_HEAD is always the tip we just fetched;
# works for a branch or a tag. Keep the local branch ref in sync for readability.
git fetch --tags --force --prune --depth 1 origin "$REF"
git checkout -f FETCH_HEAD
git branch -f "$REF" FETCH_HEAD 2>/dev/null || true

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

echo "▶ Maintenance mode OFF…"
php artisan up

echo "✅ Deploy complete: $REF"
