# Kaleta in Docker (FrankenPHP, PHP 8.5)

```bash
# set KALETA_DB_PASSWORD and KALETA_SITE_URL in docker-compose.yaml (or in your environment, e.g. Coolify)
docker compose up -d --build   # then open the site: it redirects to the installer (site name, administrator)
```

Services: `web` (FrankenPHP/Caddy, plain HTTP on `:8080` – put your TLS proxy in front, e.g. Traefik/Coolify; it also runs
`php system/docker.php cron` every 5 minutes = the `/tasks` jobs) and `db` (MySQL 8.4; the schema needs `utf8mb4_0900_ai_ci`, which MariaDB does not have).
The database comes from the environment; the web installer (`/install.php`) asks only for the site and the administrator, creates the tables and
leaves `storage/.installed` behind. It refuses to run again once the site is installed. `config.php` is not used. **Updates = new image** (`docker compose pull/build && up -d`);
the entrypoint applies pending database migrations (`php bin/migrate --if-installed`) before the server starts; migrations never run on a page request. The in-app updater is switched off.

Volumes: `/app/storage` (logs, cache, backups), `/app/media` (uploads), `/app/extensions` (add-ons). Bind mounts must be owned by uid 33 (www-data).

## Development stack

`docker-compose-dev.yaml` runs everything needed to work on Kaleta without installing PHP or MySQL: the code is mounted from the checkout
(edit and reload), Composer packages are installed by a one-shot `vendor` service, Xdebug is built in but off.

```bash
export DEV_UID=$(id -u) DEV_GID=$(id -g)       # the container writes files as you
docker compose -f docker-compose-dev.yaml up -d --build
```

| Service | URL / port | Notes |
|---|---|---|
| `web` | http://localhost:8080 | first visit opens the installer; database comes from the environment |
| `mailpit` | http://localhost:8025 (SMTP `mailpit:1025`) | catches all mail; in the admin: Settings → Mail → SMTP, host `mailpit`, port 1025, encryption none |
| `adminer` | http://localhost:8081 | server `db`, user `kaleta`, password `kaleta` (root: `root`) |
| `db` | 127.0.0.1:33060 | MySQL 8.4, persistent volume |
| `db-test` | 127.0.0.1:33061 | in-memory MySQL for `composer test` (every test class creates and drops its own database) |

Xdebug: `XDEBUG_MODE=debug docker compose -f docker-compose-dev.yaml up -d` (the IDE listens on 9003). Commands inside the container:
`docker compose -f docker-compose-dev.yaml exec web php bin/migrate` · `… exec web php tools/unit-tests.php` · `… exec web composer require …`.
`docker compose -f docker-compose-dev.yaml down -v` removes the database, mails and uploads (delete `storage/.installed` to see the installer again).

Docker CLI without the buildx plugin (Colima/Lima on macOS): the Dockerfile uses BuildKit, so run compose in the VM, which has buildx and sees your
home folder: `colima ssh -- sh -c "cd $PWD && docker compose -f docker-compose-dev.yaml up -d --build"`.

## Coolify

New resource → Docker Compose (build pack) from this repository, compose file `/docker-compose.coolify.yaml`. Coolify generates the database
password (`SERVICE_PASSWORD_DB`) and the site URL (`SERVICE_FQDN_WEB_8080` / `SERVICE_URL_WEB`); set the domain on the `web` service,
open it and run the installer. Add `KALETA_AI_URL` or any variable below in the Environment Variables tab.

## Environment variables

Each one also has a `_FILE` twin (`KALETA_DB_PASSWORD_FILE=/run/secrets/db`) for Docker secrets.

| Variable | Default | Meaning |
|---|---|---|
| `KALETA_DB_NAME` | – | **Required.** Switches env mode on (otherwise `config.php` is used) |
| `KALETA_DB_HOST` / `_PORT` / `_USER` / `_PASSWORD` / `_PREFIX` | localhost / 3306 / – / – / `ka_` | Database |
| `KALETA_DEBUG` | `false` | Show errors in pages |
| `KALETA_ADDONS` | `true` | `false` = safe mode, no add-ons |
| `KALETA_AI_URL` | – | Custom gateway for the writing assistant |
| `KALETA_SITE_URL` | request host | Public URL, e.g. `https://example.com` (**set it**, used in e-mails/links) |

SMTP is configured in the admin (Settings → Mail).
