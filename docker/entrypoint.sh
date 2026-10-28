#!/bin/sh
# Makes sure the writable folders exist, applies pending database migrations (an installed site only, after a database backup), runs the
# background jobs shortly after the start and then every 5 minutes (what web cron does with /tasks; TALEA_CRON=0 switches it off), then the command.
set -e
cd /app
mkdir -p storage/cache storage/log storage/import storage/database media extensions
# TALEA_BACKUP_BEFORE_MIGRATE=0 skips the database backup that precedes pending migrations
if [ "${TALEA_BACKUP_BEFORE_MIGRATE:-1}" = "0" ]; then php bin/migrate --if-installed; else php bin/migrate --if-installed --backup; fi
# TALEA_CRON=0 on every replica but one: the background jobs must run once
if [ "${TALEA_CRON:-1}" != "0" ]; then
    # the first run warms the page cache of a new version (Front\Cache::maintain) over this container's own server, once it listens
    export TALEA_INTERNAL_URL="${TALEA_INTERNAL_URL:-http://127.0.0.1:8080}"
    (sleep 10; while true; do php system/docker.php cron > /dev/null || true; sleep 300; done) &
fi
exec "$@"
