# Kaleta in Docker (FrankenPHP, PHP 8.5)

```bash
# set KALETA_DB_PASSWORD and KALETA_SITE_URL in docker-compose.yaml (or in your environment, e.g. Coolify)
docker compose up -d --build   # then open the site: it redirects to the installer (site name, administrator)
```

Services: `web` (FrankenPHP/Caddy, plain HTTP on `:8080` – put your TLS proxy in front, e.g. Traefik/Coolify; it also runs
`php system/docker.php cron` every 5 minutes = the `/ulohy` jobs) and `db` (MariaDB).
The database comes from the environment; the web installer (`/install.php`) asks only for the site and the administrator, creates the tables and
leaves `storage/.installed` behind. It refuses to run again once the site is installed. `config.php` is not used. **Updates = new image** (`docker compose pull/build && up -d`);
migrations run automatically on the first request. The in-app updater is switched off.

Volumes: `/app/storage` (logs, cache, backups), `/app/media` (uploads), `/app/extensions` (add-ons). Bind mounts must be owned by uid 33 (www-data).

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
