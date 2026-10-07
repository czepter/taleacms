#!/bin/sh
# Makes sure the writable folders exist, runs the background jobs every 5 minutes (what web cron does with /ulohy), then the command.
set -e
cd /app
mkdir -p storage/cache storage/log storage/import media extensions
(while true; do sleep 300; php system/docker.php cron > /dev/null || true; done) &
exec "$@"
