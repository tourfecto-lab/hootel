#!/usr/bin/env bash
# Safe auto-update for the server. Cron runs it every minute (see DEPLOY.md).
#  - pulls only fast-forward changes from origin/main
#  - never touches data/ (database) or files uploaded from the admin panel
#  - checks every changed PHP file for syntax errors and rolls back if one is broken
#  - writes a one-line log to data/deploy.log
set -euo pipefail
cd "$(dirname "$0")"

exec 9>/tmp/kingspalace-deploy.lock
flock -n 9 || exit 0                       # another run is still going

PHP="${PHP_BIN:-php}"
git config core.fileMode false             # chmod/chown must not look like local edits
git fetch -q origin main
LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse origin/main)"
[ "$LOCAL" = "$REMOTE" ] && exit 0         # nothing new

git merge --ff-only -q origin/main

for f in $(git diff --name-only "$LOCAL" HEAD -- '*.php'); do
  if [ -f "$f" ] && ! "$PHP" -l "$f" >/dev/null 2>&1; then
    git reset --hard -q "$LOCAL"
    echo "$(date -u +%FT%TZ) ROLLED BACK: syntax error in $f" >> data/deploy.log
    exit 1
  fi
done

chown -R www:www app public cron tools 2>/dev/null || true
echo "$(date -u +%FT%TZ) deployed $(git rev-parse --short HEAD)" >> data/deploy.log
