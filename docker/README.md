# Kaleta in Docker (FrankenPHP, PHP 8.5)

```bash
cp .env.example .env      # set DB_PASSWORD, ADMIN_PASSWORD (min. 10 chars), SITE_URL
docker compose up -d --build
```

Services: `web` (FrankenPHP/Caddy, plain HTTP on `:8080` – put your TLS proxy in front, e.g. Traefik/Coolify),
`cron` (runs `php system/docker.php cron` every 5 minutes = the `/ulohy` jobs), `db` (MariaDB).
The first start creates the tables and the admin; later starts do nothing (`php system/docker.php install`, idempotent).
`install.php` is removed from the image, `config.php` is not used. **Updates = new image** (`docker compose pull/build && up -d`);
migrations run automatically on the first request. The in-app updater is switched off.

Volumes: `/app/storage` (logs, cache, backups), `/app/media` (uploads), `/app/extensions` (add-ons). Bind mounts must be owned by uid 33 (www-data).

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
| `KALETA_ADMIN_PASSWORD` | – | **Required on first start**, min. 10 chars |
| `KALETA_ADMIN_USER` / `_EMAIL` / `_NAME` | admin / – / – | First administrator |
| `KALETA_SITE_NAME` | My website | Site name |
| `KALETA_LANGUAGE` | `en` | `cs`, `en`, `de` |
| `KALETA_TIME_ZONE` | by language | e.g. `Europe/Berlin` |
| `KALETA_SITE_TEMPLATE` | `firemni` | Sample site (`export` = empty site for an import) |
| `KALETA_EXTENSIONS` | default set | Comma list: `novinky,poptavky,newsletter,bookings,statistika,presmerovani,jazyky,asistent,whistleblowing,fleet,claude` |

The `KALETA_ADMIN_*`, `SITE_*`, `LANGUAGE`, `TIME_ZONE`, `EXTENSIONS` values only matter on the very first start; afterwards
change them in the admin. SMTP is configured in the admin (Settings → Mail).
