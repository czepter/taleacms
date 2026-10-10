# Deployment and updates

The product ships as a container image. One installation is one image plus a MySQL 8.4 database and three volumes. The design
decisions are in `docs/specs/update-and-deployment.md`.

## Install

```bash
cp docker-compose.yaml compose.yaml        # or use docker-compose.coolify.yaml on Coolify
docker compose up -d                       # builds the image from source, or set `image:` to a published tag
```

Open the site and walk through the installer. Configuration is environment variables (`TALEA_*`, every one also as
`TALEA_<NAME>_FILE` for Docker secrets); nothing inside the image needs editing. The full list is in `docker/README.md`.

## Update

1. Read the notice in the administration (or the release notes): a new version, and whether it is a security release.
2. Pull and restart:

   ```bash
   docker compose pull && docker compose up -d
   ```

On start the entrypoint

1. writes a database backup to `storage/backups/` **when migrations are pending** (`TALEA_BACKUP_BEFORE_MIGRATE=0` skips it),
2. runs `bin/migrate --if-installed`: pending migrations are applied before the container serves traffic; `GET_LOCK` serialises
   several replicas, so two containers never apply the same migration,
3. starts the background-job loop (`TALEA_CRON=0` switches it off, see Replicas), then the web server.

Pin a version with a tag (`...:1.4.2`), follow a minor line (`...:1.4`) or a major (`...:1`). `latest` is the newest stable release.

## The "new version" notice

Once a day the site reads the signed release feed (`update.json`, the address in `TALEA_UPDATE_FEED`) and shows a notice to
administrators. No identifier and no query string are sent; the feed is verified against `system/update.pub` and ignored when the
signature does not match. Nothing is ever installed by the site. Switch the check off with `TALEA_UPDATE_CHECK=0` or in
Settings → Backups and updates. Without `TALEA_UPDATE_FEED` nothing is requested at all.

## Health

`GET /health` answers `200 ok` when the database answers and no migration is pending, and `503` with one word (`migrations pending`,
`database`) otherwise. No session, no cache. Use it as the orchestrator's readiness probe; the image's own `HEALTHCHECK` only checks
that the port answers, so a half-migrated container is not restarted in a loop.

## Rollback

Migrations only move forward, and a release never drops what the previous release still reads (expand, then contract in a later
release). To go back:

1. stop the containers,
2. restore the dump from `storage/backups/` taken before the update (Settings → Backups and updates → restore, or the SQL file with
   your MySQL client),
3. start the previous image tag.

## Replicas

Any number of web containers can share the `storage`, `media` and `extensions` volumes. Exactly one of them should run the
background loop: set `TALEA_CRON=0` on all but one. Migrations are safe to start everywhere at once.

## Add-ons

`extensions/` is a volume, so add-ons survive an image update. After an update check that each add-on still supports the new core
version (its `extension.json` `requires`).

## Verify an image

Images are signed with cosign (keyless, GitHub OIDC) and carry provenance and an SBOM:

```bash
cosign verify ghcr.io/<owner>/talea:1.4.2 \
  --certificate-identity-regexp 'https://github.com/<owner>/.*' --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

The feed lists the image digest; the publisher signs it locally with `php tools/release.php X.Y.Z --feed ...` (docs/RELEASING.md).
