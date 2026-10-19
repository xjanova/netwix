#!/usr/bin/env bash
set -euo pipefail
root=/home/admin/domains/netwix.online/public_html
release=/home/admin/netwix-releases/20261019-brainx-urban-relay
backup=/home/admin/netwix-backups/20261019-brainx-urban-relay
cd "$root"
test "$(git rev-parse --short HEAD)" = d48ec84
git diff --quiet -- app/Http/Controllers/EpisodeSourceController.php app/Services/Import/Sources/RongYokSource.php app/Support/MirrorRotation.php app/Support/RongYokClientResolver.php config/services.php routes/api.php resources/js/rongyok-client.js resources/views/frontend/watch.blade.php resources/views/frontend/vertical-player.blade.php
umask 077
mkdir -p "$backup" "$release/staged"
tar -xzf "$release/relay-release.tgz" -C "$release/staged"
# Back up all replaced files before the first mutation. Old hashed assets remain available.
while IFS= read -r file; do
    case "$file" in /*|*..*) exit 2;; esac
    test -f "$release/staged/$file"
    if test -f "$root/$file" && ! test -f "$backup/$file"; then
        mkdir -p "$backup/$(dirname "$file")"
        cp -p "$root/$file" "$backup/$file"
    fi
done < "$release/files.txt"
while IFS= read -r file; do
    mkdir -p "$root/$(dirname "$file")"
    cp "$release/staged/$file" "$root/$file.relay-tmp"
    chmod 644 "$root/$file.relay-tmp"
    mv "$root/$file.relay-tmp" "$root/$file"
done < "$release/files.txt"
/usr/local/php83/bin/php artisan migrate --path=database/migrations/2026_10_19_020000_create_browser_relay_jobs_table.php --force
/usr/local/php83/bin/php "$release/configure.php"
/usr/local/php83/bin/php artisan config:cache
/usr/local/php83/bin/php artisan route:cache
/usr/local/php83/bin/php artisan view:cache
