# Kaleta on FrankenPHP. Build: docker build -t kaleta .   Run: see docker-compose.yaml and docker/README.md
FROM dunglas/frankenphp:1.13-php8.5.11-bookworm

RUN install-php-extensions pdo_mysql mbstring gd zip intl sodium opcache exif

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-kaleta.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/kaleta-entrypoint

WORKDIR /app
COPY . /app

# configuration comes from the environment; install.php (web installer) only creates the tables and the administrator
RUN rm -f config.php \
    && chmod +x /usr/local/bin/kaleta-entrypoint \
    && mkdir -p storage/cache storage/log storage/import media extensions /data/caddy /config/caddy \
    && chown -R www-data:www-data /app/storage /app/media /app/extensions /data /config

# the files that change at runtime live in volumes; everything else is the image (updates = a new image)
VOLUME ["/app/storage", "/app/media", "/app/extensions"]

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD php -r 'exit(@fsockopen("127.0.0.1", 8080) ? 0 : 1);'

ENTRYPOINT ["kaleta-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
