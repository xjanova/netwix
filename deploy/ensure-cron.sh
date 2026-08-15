#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# ensure-cron.sh — make sure THIS app's `schedule:run` line exists in the crontab.
#
# Why this file exists (incident 2026-08-15): the box rebooted on 2026-08-06 and NetWix's
# `schedule:run` line vanished from the admin crontab. Nothing noticed for nine days. Everything
# scheduled stopped with it — auto-import, refresh-episodes, the queue workers, the LINE alert
# digest, find-backups, usdt:watch — and, worst of all, `netwix:source-canary`, which is what tells
# PlaybackHealth "this whole source is down, do not unpublish its titles". With the canary frozen the
# brake read a nine-day-old verdict of "every source is fine", so when goseries4k went dark the
# auto-suspend logic quietly unpublished 114 of its titles one viewer at a time. A watchdog that is
# not running still answers "all clear", which is the failure that hides every other failure.
#
# DirectAdmin/cPanel boxes are known to drop crontab entries across a system update or restore —
# that is precisely why the sibling thaiprompt app already self-heals its own line on every deploy.
# NetWix had no equivalent, so its line was the one that did not come back.
#
# Idempotent: safe to run on every deploy, never duplicates, and only ever touches lines that name
# THIS project path. Other apps' cron lines on the same account are left exactly as they are.
#
#   bash deploy/ensure-cron.sh              # auto-detect project path + php binary
#   bash deploy/ensure-cron.sh /path/to/app
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

PROJECT_PATH="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
# Prefer the same interpreter the app runs under. DirectAdmin's default `php` on PATH is often an
# older build than the one php-fpm serves with, and the scheduler must not run on a different one.
PHP_BIN="$(command -v php83 || command -v /usr/local/php83/bin/php || command -v php || echo /usr/local/bin/php)"
[ -x "/usr/local/php83/bin/php" ] && PHP_BIN="/usr/local/php83/bin/php"

MARKER="# laravel-scheduler:${PROJECT_PATH}"
CRON_LINE="* * * * * cd ${PROJECT_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"

if ! command -v crontab >/dev/null 2>&1; then
    echo "⚠ crontab not available in this shell — skipping cron self-heal"
    exit 0
fi

CURRENT="$(crontab -l 2>/dev/null || true)"

# Already correct → do nothing. Checked before any rewrite so the common case cannot touch the
# crontab at all: the safest edit is the one that is never made.
if printf '%s\n' "$CURRENT" | grep -qxF "$CRON_LINE"; then
    echo "✅ cron already installed for ${PROJECT_PATH}"
    exit 0
fi

# Keep a timestamped copy before ANY rewrite. This file is the only record of the other apps' cron
# lines, and a bad filter here would take them with it.
BACKUP="${HOME}/.crontab-backup-$(date +%Y%m%d-%H%M%S).txt"
printf '%s\n' "$CURRENT" > "$BACKUP"

# Drop our own previous entries (marker + any schedule:run line naming this path) so a changed php
# binary or project path replaces the old line instead of stacking a second one. The awk test needs
# BOTH the path and "artisan schedule:run", so a sibling app's line can never match.
#
# NB: the obvious `grep -v -F "artisan schedule:run.*${PROJECT_PATH}"` does NOT work — -F treats
# `.*` literally, so it matches nothing and the entry accumulates once per deploy. The thaiprompt
# copy of this script shipped that bug and reached 124 duplicate lines (incident 2026-06-29).
FILTERED="$(printf '%s\n' "$CURRENT" \
    | grep -vxF "$MARKER" \
    | awk -v p="${PROJECT_PATH}" '!(index($0, p) && index($0, "artisan schedule:run"))' || true)"

printf '%s\n%s\n%s\n' "$FILTERED" "$MARKER" "$CRON_LINE" | sed '/^[[:space:]]*$/d' | crontab -

# Verify rather than assume: a crontab that silently failed to install is the exact condition this
# script exists to prevent, so a failure here must be loud.
if crontab -l 2>/dev/null | grep -qxF "$CRON_LINE"; then
    echo "✅ cron installed: ${CRON_LINE}"
    echo "   previous crontab saved to ${BACKUP}"
else
    echo "❌ cron install FAILED — restoring ${BACKUP}"
    crontab "$BACKUP" || true
    exit 1
fi
