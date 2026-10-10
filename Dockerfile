# syntax=docker/dockerfile:1
# Talea on FrankenPHP. Build: docker build -t talea .   Run: see docker-compose.yaml and docker/README.md
# Development: docker-compose-dev.yaml builds the "dev" stage (code is mounted, Xdebug and Composer inside).
# Layers run from the least to the most often changed, so a code change rebuilds only the last COPY.

# ---- base: PHP, extensions, web server config (shared by dev and production)
FROM dunglas/frankenphp:1.13-php8.5.11-bookworm AS base

# PHP extensions: the slowest step; the apt downloads are kept in a BuildKit cache between builds
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    rm -f /etc/apt/apt.conf.d/docker-clean \
    && install-php-extensions pdo_mysql pdo_pgsql mbstring gd zip intl sodium opcache exif

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-talea.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/talea-entrypoint

WORKDIR /app

# ---- vendor: Composer packages (Phinx, the database migrations) – production dependencies only
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# the PHP extensions (pdo_mysql …) live in the base stage, not in the composer image: platform checks are skipped here
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- dev: the code is mounted from the host (docker-compose-dev.yaml), Composer and Xdebug are inside
FROM base AS dev
RUN install-php-extensions xdebug
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php-dev.ini /usr/local/etc/php/conf.d/zz-talea-dev.ini
# the container runs as the host user (compose "user:"), so Caddy keeps its state in /tmp instead of /data and /config
ENV XDG_DATA_HOME=/tmp/caddy-data XDG_CONFIG_HOME=/tmp/caddy-config
EXPOSE 8080
# nosemgrep: dockerfile.security.missing-user-entrypoint.missing-user-entrypoint -- the dev stage runs as the host user (compose "user:")
ENTRYPOINT ["talea-entrypoint"]
# nosemgrep: dockerfile.security.missing-user.missing-user -- same: the dev stage is never published; production sets USER www-data
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---- production (the default target: the last stage)
FROM base AS production

# the files that change at runtime live in volumes (below); everything else is the image (updates = a new image)
RUN mkdir -p storage/cache storage/log storage/import media extensions /data/caddy /config/caddy \
    && chown -R www-data:www-data /app/storage /app/media /app/extensions /data /config

# application code last; .dockerignore keeps config.php, .env and the like out
COPY . /app
COPY --from=vendor /app/vendor /app/vendor
RUN chown -R www-data:www-data /app/storage /app/media /app/extensions
# declared last: later changes to these paths would be discarded
VOLUME ["/app/storage", "/app/media", "/app/extensions"]

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD php -r 'exit(@fsockopen("127.0.0.1", 8080) ? 0 : 1);'

ENTRYPOINT ["talea-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
