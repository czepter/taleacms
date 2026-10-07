# syntax=docker/dockerfile:1
# Kaleta on FrankenPHP. Build: docker build -t kaleta .   Run: see docker-compose.yaml and docker/README.md
# Layers run from the least to the most often changed, so a code change rebuilds only the last COPY.
FROM dunglas/frankenphp:1.13-php8.5.11-bookworm

# PHP extensions: the slowest step; the apt downloads are kept in a BuildKit cache between builds
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean \
    && install-php-extensions pdo_mysql mbstring gd zip intl sodium opcache exif

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kaleta.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/kaleta-entrypoint

# the files that change at runtime live in volumes (below); everything else is the image (updates = a new image)
WORKDIR /app
RUN mkdir -p storage/cache storage/log storage/import media extensions /data/caddy /config/caddy \
    && chown -R www-data:www-data /app /data /config

# application code last; .dockerignore keeps config.php, .env and the like out
COPY . /app
RUN chown www-data:www-data /app/storage /app/media /app/extensions
# declared last: later changes to these paths would be discarded
VOLUME ["/app/storage", "/app/media", "/app/extensions"]

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD php -r 'exit(@fsockopen("127.0.0.1", 8080) ? 0 : 1);'

ENTRYPOINT ["kaleta-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
