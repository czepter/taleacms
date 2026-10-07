#!/bin/sh
# Creates the tables and the first administrator on the first start (idempotent), then runs the command.
set -e
cd /app
mkdir -p storage/cache storage/log storage/import media extensions
php system/docker.php install
exec "$@"
