# Deployment and updates

The product ships as a container image. One installation is one image plus a MySQL 8.4 database and three volumes. The design
decisions are in `docs/specs/update-and-deployment.md`. This is where Talea differs most from [Kaleta](https://kaletacms.com),
which ships a ZIP for PHP hosting and installs its updates from the administration.

## Install

```bash
cp docker-compose.yaml compose.yaml        # on Coolify: docker-compose.coolify-{mysql,mariadb,postgres,sqlite}.yaml
docker compose up -d                       # pulls ghcr.io/czepter/talea; TALEA_TAG pins a version (default latest)
```

Open the site and walk through the installer. Configuration is environment variables (`TALEA_*`, every one also as
`TALEA_<NAME>_FILE` for Docker secrets); nothing inside the image needs editing. The full list is in `docker/README.md`.

## Visitor addresses behind the proxy

PHP's `REMOTE_ADDR` is the proxy in front of the container, so without help every visitor shares one limit key. `TALEA_TRUSTED_PROXIES`
(comma-separated addresses or networks) names the proxies whose `X-Forwarded-For` Talea believes: it reads the header from the right,
skips trusted proxies and takes the first other address. Sign-in, reset and form limits count by that address.

- The image leaves it unset: no `X-Forwarded-For` is trusted, which is right when the container is published directly.
- `docker-compose.yaml` publishes the port on `127.0.0.1` only, so a proxy on the host reaches the container from the Docker bridge
  gateway: it trusts Docker's default address pools, `172.16.0.0/12,192.168.0.0/16` (with other `default-address-pools`, set the variable).
  The `docker-compose.coolify-*.yaml` files set all private ranges, because Coolify's proxy is a container on a shared network.
  Narrow it to the proxy's exact address or network where you can; override with the variable.
- Never set it when the container port is reachable from untrusted clients. Docker's port publishing can make outside clients appear
  as a private gateway address, and then anyone can forge the header.
- Clients inside a trusted range (for example a private LAN) can choose their own address.
- The proxy must set or append `X-Forwarded-For`, and the container must not be reachable around it. Traefik does; plain nginx
  `proxy_pass` does not and forwards the client's header unchanged, so add `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`
  (or `$remote_addr`). A proxy that passes the client's `X-Forwarded-For` through lets anyone choose their address.
- On Coolify every container on the shared network is inside the trusted private range, so keep the application off networks you do not control.
- Behind Cloudflare also set Settings, `trusted_proxy`; this works with your own proxy between Cloudflare and the container too.

## The site's secret

Set `TALEA_SECRET_KEY` (or `TALEA_SECRET_KEY_FILE`) to a random value of at least 32 characters, e.g. `openssl rand -hex 32`
(`docker-compose.coolify.yaml` generates one). It encrypts the stored connector credentials (OAuth secrets and tokens, API tokens) and
signs forms, preview and download links and the builder clipboard, each with a key derived from it. Without it the key is the setting
`secret_key` in the database, so a database dump or backup alone decrypts every credential; System status warns about it.

When the variable is set on a site that still has its key in the database, the first request encrypts the stored credentials again
under the new key and clears the database key, in one transaction (a failure is logged and leaves everything as it was; the next
request tries again). Because the signing key changes with it, things signed before the switch stop working once: open forms ask to be
reloaded, preview and document links must be created again, visitors re-enter page passwords, a builder clipboard copied before is
pasted without its Custom HTML, and visitors of that day are counted again in the statistics.

**Keep the secret with your backups.** A database restored on another server, or after the variable was changed or removed, cannot
read the stored credentials without the same `TALEA_SECRET_KEY`: System status then reports them as unreadable. Set the variable the
database was used with, or connect the services again in Integrations.

## Update

1. Read the release notes on GitHub: what changed, and whether it is a security release (watch the repository's releases to hear of them).
2. Pull and restart:

   ```bash
   docker compose pull && docker compose up -d
   ```

On start the entrypoint

1. writes a database backup to `storage/backups/` **when migrations are pending** (`TALEA_BACKUP_BEFORE_MIGRATE=0` skips it),
2. runs `bin/migrate --if-installed`: pending migrations are applied before the container serves traffic; `GET_LOCK` serialises
   several replicas, so two containers never apply the same migration,
3. stores `TALEA_SITE_URL` as the site address when it differs (see "Site address and domain moves"),
4. starts the background-job loop (`TALEA_CRON=0` switches it off, see Replicas), then the web server.

Pin a version with a tag (`...:1.4.2`), follow a minor line (`...:1.4`) or a major (`...:1`). `latest` is the newest stable release;
a staging site follows a pre-release tag (`X.Y.Z-rc.N`). All tags: docs/RELEASING.md, "Image tags".

## Site address and domain moves

The site address (`site_url`, Settings → General) is what canonical addresses, `og:url`, the sitemap, `robots.txt`, feeds and the links
in e-mails (password resets, member sign-in, bookings) are built from; the Host header never is.

- **With `TALEA_SITE_URL` set** the variable wins. The installer takes it as the first address, and every container start compares it
  with the stored one (`php system/docker.php site-url`, never on a page request): a different value is stored, written to the change
  log (Settings, `site_url`, old → new, reason `TALEA_SITE_URL`) and the page cache is emptied, so no cached page keeps the old host.
  The admin field is read-only, and `update_settings` over MCP refuses `site_url`. A value that is not `https://host` or
  `https://host:port` (no path) is reported in the container log and changes nothing.
- **Without it** an administrator changes the address in Settings → General, or a Claude connection with full access sets `site_url`
  with `update_settings` (never a drafts-only one: password-reset and member links are built from it). The change log and the cache
  work as above.

A domain move on Coolify: add the new domain to the service (`SERVICE_URL_WEB`, which the compose files pass as `TALEA_SITE_URL`) and
restart it. Nothing to do in the administration.

**Other addresses.** "Redirect other addresses to the site address" (Settings → General, off by default; `redirect_to_site_url`) sends
a GET on any other host to the same path on the site address with a 301: with or without `www`, or the old host after a move. `/health`,
`/tasks`, `/mcp`, the OAuth and `/.well-known/` addresses, signed preview links (`preview_key`, `/_popup/…`) and the administration answer
on every host. Passkeys belong to the host they were registered on: after a move, sign in with the password (and the code) on the new
address and add the passkey again in My account.

## Health

`GET /health` answers `200 ok` when the database answers and no migration is pending, and `503` with one word (`migrations pending`,
`database`) otherwise. No session, no cache. Use it as the orchestrator's readiness probe; the image's own `HEALTHCHECK` only checks
that the port answers, so a half-migrated container is not restarted in a loop.

## Error tracking

Errors can go to a Sentry or GlitchTip project (GlitchTip is open source and self-hostable; both speak the same protocol). Create a
project there, copy its DSN and set it on the site:

```
TALEA_ERROR_DSN=https://<key>@glitchtip.example.com/<project>   # or TALEA_ERROR_DSN_FILE for a secret
TALEA_ERROR_ENVIRONMENT=production                             # default production; staging, test …
TALEA_ERROR_SAMPLE_RATE=1                                      # 0–1, the share of events sent
TALEA_ERROR_BROWSER=0                                          # 1 = also the public site's browser errors
```

On classic hosting the same keys go into `config.php` (`error_dsn`, `error_environment`, `error_sample_rate`, `error_browser`); the
variables win. The DSN is never a setting of the admin or of an MCP connection. After a restart, `php system/docker.php error-test`
sends a test event and prints its id or why it did not arrive; System status (Operation → Error tracking) shows the last report.

What is reported: uncaught errors and fatal errors of the site, the admin, MCP tool calls, the background jobs and `bin/migrate`,
elements and site parts that could not be rendered, an e-mail given up after its last retry, and JavaScript errors of the admin and the
builder (of the public site only with `TALEA_ERROR_BROWSER=1`). What is sent: the error with its stack trace (paths inside the install,
a few lines of source, no arguments), the release (`talea@<version>`), the area, route, admin module, MCP tool, job or add-on, the role of
the signed-in person and the browser's name and version. What is never sent: IP addresses, cookies, request bodies, names, e-mail
addresses, the values of query parameters other than `module`, `action`, `tab`, `page` and the like; messages are scrubbed of e-mail
addresses, tokens and the values in database errors. The same fault is sent once a minute at most; reports the tracker did not take
wait in `storage/log/outbox/` and are retried by the background jobs for a day. `storage/log/errors.log` keeps every uncaught error
with its trace and the event id, with or without a tracker. When the DSN is set, the record of processing names the tracker's host.

## Several sites

`TALEA_MULTISITE=1` turns the install into one owner with several sites (docs/specs/multisite.md). Each site answers to its own
domains (`site_domains`) and has its own tables; the first start with the flag gives site 1 the host of `TALEA_SITE_URL`.

- A host that belongs to no site, or to a trashed one, gets a bare 404; it never shows site 1. `/health` answers on any host.
- A secondary domain marked "redirect to primary" answers with a 301 to the site's primary host.
- A paused site answers visitors with 503 (signed-in people and signed preview links still see it); an archived site redirects to the
  site it points at, or stays readable and refuses forms, bookings and the member login with 403.
- Point every domain at the same container and have the proxy pass the `Host` header on unchanged.
- Without the flag the start (`bin/migrate`) stops while the database holds more than one site that is not trashed.
- With `TALEA_SECRET_KEY` set, each further site derives its own signing key from it, so a preview link of one site is void on the others.

## Rollback

Migrations only move forward, and a release never drops what the previous release still reads (expand, then contract in a later
release). To go back:

1. stop the containers,
2. restore the dump from `storage/backups/` taken before the update (Settings → Backups → restore, or the SQL file with
   your MySQL client),
3. start the previous image tag with the same `TALEA_SECRET_KEY` (see The site's secret).

## Testing an update end to end

`composer test:e2e` (needs Docker; not part of `composer test`) builds the production image from the checkout as version N, boots it in a
throw-away compose project (own name, own port, MySQL 8.4), installs a site and writes a page. It then builds N+1 (N plus one later
migration), replaces only the `web` container and asserts: `/health` turns 200, exactly the new migration is recorded and applied, the
page is intact and served, and the before-update dump is in `storage/backups/`. The project, its volumes and the images are removed
afterwards. The Dockerfile needs BuildKit (`docker buildx`); without it the test is skipped, or set `TALEA_E2E_IMAGE_N` to an already
built image to use as N (it is left in place).

## Replicas

Any number of web containers can share the `storage`, `media` and `extensions` volumes. Exactly one of them should run the
background loop: set `TALEA_CRON=0` on all but one. Migrations are safe to start everywhere at once.

## Files waiting for approval

Files that Claude uploads over a connection limited to drafts wait in `storage/staging/` (the `storage` volume) until a person
publishes content that uses them or approves them in Media; then they move to `media/`. Files nobody approves are deleted after
30 days. A request for a missing `/media/…` file must reach `index.php`, which shows a waiting file only to signed-in users and
draft previews: the image's Caddyfile does this; on your own nginx use the `/media/` block of `system/nginx.example.conf`
(without it, waiting files are 404 in previews too; publishing still works).

## Add-ons

`extensions/` is a volume, so add-ons survive an image update. After an update check that each add-on still supports the new core
version (its `extension.json` `requires`).

## Verify an image

Images are signed with cosign (keyless, GitHub OIDC) and carry provenance and an SBOM:

```bash
cosign verify ghcr.io/<owner>/talea:1.4.2 \
  --certificate-identity-regexp 'https://github.com/<owner>/.*' --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

Each GitHub release names the digest of its image.
