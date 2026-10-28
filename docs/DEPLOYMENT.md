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
3. starts the background-job loop (`TALEA_CRON=0` switches it off, see Replicas), then the web server.

Pin a version with a tag (`...:1.4.2`), follow a minor line (`...:1.4`) or a major (`...:1`). `latest` is the newest stable release;
a staging site follows a pre-release tag (`X.Y.Z-rc.N`). All tags: docs/RELEASING.md, "Image tags".

## Health

`GET /health` answers `200 ok` when the database answers and no migration is pending, and `503` with one word (`migrations pending`,
`database`) otherwise. No session, no cache. Use it as the orchestrator's readiness probe; the image's own `HEALTHCHECK` only checks
that the port answers, so a half-migrated container is not restarted in a loop.

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
