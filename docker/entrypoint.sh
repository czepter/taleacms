#!/bin/sh
# Makes sure the writable folders exist, applies pending database migrations (an installed site only, after a database backup), runs the
# background jobs every 5 minutes (what web cron does with /tasks; TALEA_CRON=0 switches it off), then the command.
set -e
cd /app
mkdir -p storage/cache storage/log storage/import media extensions
# TALEA_BACKUP_BEFORE_MIGRATE=0 skips the database backup that precedes pending migrations
if [ "${TALEA_BACKUP_BEFORE_MIGRATE:-1}" = "0" ]; then php bin/migrate --if-installed; else php bin/migrate --if-installed --backup; fi
# TALEA_CRON=0 on every replica but one: the background jobs must run once
if [ "${TALEA_CRON:-1}" != "0" ]; then
    (while true; do sleep 300; php system/docker.php cron > /dev/null || true; done) &
fi
exec "$@"
