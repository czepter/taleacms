# Talea in Docker (FrankenPHP, PHP 8.5)

```bash
# set TALEA_DB_PASSWORD and TALEA_SITE_URL in docker-compose.yaml (or in your environment, e.g. Coolify)
docker compose up -d   # then open the site: it redirects to the installer (site name, administrator)
```

Services: `web` (FrankenPHP/Caddy, plain HTTP on `:8080` – put your TLS proxy in front, e.g. Traefik/Coolify; it also runs
`php system/docker.php cron` every 5 minutes = the `/tasks` jobs) and `db` (MySQL 8.4; MariaDB 11.4+ works too, 10.x lacks the collation `utf8mb4_0900_ai_ci`).
The database comes from the environment; the web installer (`/install.php`) asks only for the site and the administrator, creates the tables and
leaves `storage/.installed` behind. It refuses to run again once the site is installed. `config.php` is not used. **Updates = new image** (`docker compose pull && docker compose up -d`);
the entrypoint applies pending database migrations (`php bin/migrate --if-installed`) and stores a changed `TALEA_SITE_URL` (`php system/docker.php site-url`)
before the server starts; neither runs on a page request. A site never updates itself and never asks for new versions.

Volumes: `/app/storage` (logs, cache, backups), `/app/media` (uploads), `/app/extensions` (add-ons). Bind mounts must be owned by uid 33 (www-data).

## Development stack

`docker-compose-dev.yaml` runs everything needed to work on Talea without installing PHP or MySQL: the code is mounted from the checkout
(edit and reload), Composer packages are installed by a one-shot `vendor` service, Xdebug is built in but off.

```bash
export DEV_UID=$(id -u) DEV_GID=$(id -g)       # the container writes files as you
docker compose -f docker-compose-dev.yaml up -d --build
```

| Service | URL / port | Notes |
|---|---|---|
| `web` | http://localhost:8080 | first visit opens the installer; database comes from the environment |
| `mailpit` | http://localhost:8025 (SMTP `mailpit:1025`) | catches all mail; in the admin: Settings → Mail → SMTP, host `mailpit`, port 1025, encryption none |
| `adminer` | http://localhost:8081 | server `db`, user `talea`, password `talea` (root: `root`) |
| `db` | 127.0.0.1:33060 | MySQL 8.4, persistent volume |
| `db-test` | 127.0.0.1:33061 | in-memory MySQL for `composer test` (every test class creates and drops its own database) |
| `db-test-pg` | 127.0.0.1:33062 | in-memory PostgreSQL 18 for `composer test:pg` (user and password `postgres`) |
| `db-pg` | 127.0.0.1:33063 | PostgreSQL 18 to develop on, profile `db-pg`: `TALEA_DB_DRIVER=pgsql TALEA_DB_HOST=db-pg docker compose -f docker-compose-dev.yaml --profile db-pg up -d` (user, password, database `talea`) |

The suites run on every engine: `composer test` (MySQL), `composer test:pg` (integration suite on PostgreSQL, `TALEA_TEST_PG_HOST/_PORT/_USER/_PASSWORD`; set `TALEA_TEST_PG_PORT=33062` for the service above)
and `composer test:sqlite` (integration and site suites on SQLite files, no service needed).

Xdebug: `XDEBUG_MODE=debug docker compose -f docker-compose-dev.yaml up -d` (the IDE listens on 9003). Commands inside the container:
`docker compose -f docker-compose-dev.yaml exec web php bin/migrate` · `… exec web composer test:unit` · `… exec web composer require …`.
`docker compose -f docker-compose-dev.yaml down -v` removes the database, mails and uploads (delete `storage/.installed` to see the installer again).

Docker CLI without the buildx plugin (Colima/Lima on macOS): the Dockerfile uses BuildKit, so run compose in the VM, which has buildx and sees your
home folder: `colima ssh -- sh -c "cd $PWD && docker compose -f docker-compose-dev.yaml up -d --build"`.

## Coolify

New resource → Docker Compose (build pack) from this repository, compose file `/docker-compose.coolify-mysql.yaml` (MySQL 8.4),
`/docker-compose.coolify-mariadb.yaml` (MariaDB 11.4), `/docker-compose.coolify-postgres.yaml` (PostgreSQL 18) or `/docker-compose.coolify-sqlite.yaml` (SQLite: no database
service, the file lives in the storage volume). Coolify generates the database
password (`SERVICE_PASSWORD_DB`), the site's secret (`SERVICE_PASSWORD_64_SECRET` → `TALEA_SECRET_KEY`) and the site URL (`SERVICE_FQDN_WEB_8080` / `SERVICE_URL_WEB`); set the domain on the `web` service,
open it and run the installer. Add `TALEA_AI_URL` or any variable below in the Environment Variables tab.

## Environment variables

Each one also has a `_FILE` twin (`TALEA_DB_PASSWORD_FILE=/run/secrets/db`) for Docker secrets.

| Variable | Default | Meaning |
|---|---|---|
| `TALEA_DB_NAME` | – | **Required** (except for `sqlite`). Switches env mode on (otherwise `config.php` is used); for `sqlite` the database file (default `storage/database/talea.sqlite`) |
| `TALEA_DB_DRIVER` | `mysql` | Database engine: `mysql` (MySQL 8), `pgsql` (PostgreSQL 18, see docs/specs/postgres.md) or `sqlite` (a file in the storage volume, no database service; one replica, see docs/specs/sqlite.md) |
| `TALEA_DB_HOST` / `_PORT` / `_USER` / `_PASSWORD` / `_PREFIX` | localhost / 3306 (5432 for `pgsql`) / – / – / `tl_` | Database |
| `TALEA_DEBUG` | `false` | Show errors in pages |
| `TALEA_ADDONS` | `true` | `false` = safe mode, no add-ons |
| `TALEA_AI_URL` | – | Custom gateway for the writing assistant |
| `TALEA_SITE_URL` | request host | Public URL, e.g. `https://example.com` (**set it**: canonical addresses, the sitemap, feeds and e-mail links are built from it). Applied on every start: a changed value replaces the stored site address (change log, page cache emptied), and the admin field is read-only while it is set. A domain move = change it and restart; docs/DEPLOYMENT.md, "Site address and domain moves" |
| `TALEA_BACKUP_BEFORE_MIGRATE` | on | `0` skips the database backup that precedes pending migrations on start |
| `TALEA_CRON` | on | `0` on every replica but one: the background-job loop must run once |
| `TALEA_SECRET_KEY` | – | The site's secret, at least 32 characters (`openssl rand -hex 32`): encrypts the connector credentials and signs links, so a database dump alone holds no key. Without it the key stays in the database (System status warns). Keep it with your backups, see docs/DEPLOYMENT.md |
| `TALEA_MULTISITE` | off | `1` serves several sites of one owner from this install, each on its own domain (docs/specs/multisite.md, docs/DEPLOYMENT.md, "Several sites"). The start refuses while the database holds more than one site and this is off. With `TALEA_SECRET_KEY` set, every further site signs links with a key of its own |
| `TALEA_ERROR_DSN` | – | DSN of a Sentry or GlitchTip project: errors of the site, the admin, MCP, jobs and the admin's scripts are reported there, without personal data (docs/DEPLOYMENT.md, "Error tracking"). Test it with `php system/docker.php error-test` |
| `TALEA_ERROR_ENVIRONMENT` | `production` | The environment the reports carry (`staging`, `test` …) |
| `TALEA_ERROR_SAMPLE_RATE` | `1` | Share of the error events sent, 0–1 |
| `TALEA_ERROR_BROWSER` | off | `1` = report JavaScript errors of the public site too (no cookies, no identifiers) |
| `TALEA_INTERNAL_URL` | `http://127.0.0.1:8080` | Where the background loop reaches this container's own server to warm the page cache |

SMTP is configured in the admin (Settings → Mail).
