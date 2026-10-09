#!/bin/sh
# Makes sure the writable folders exist, applies pending database migrations (an installed site only), runs the background jobs
# every 5 minutes (what web cron does with /tasks), then the command.
set -e
cd /app
mkdir -p storage/cache storage/log storage/import media extensions
php bin/migrate --if-installed
(while true; do sleep 300; php system/docker.php cron > /dev/null || true; done) &
exec "$@"
