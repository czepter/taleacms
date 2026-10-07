#!/bin/sh
# Makes sure the writable folders exist, then runs the command.
set -e
cd /app
mkdir -p storage/cache storage/log storage/import media extensions
exec "$@"
