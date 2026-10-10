# Updates and deployment (HF-13)

Status: plan, to be approved by the maintainer; implementation follows in the same issue.

## Goal

An operator updates an installation by pulling a new image and restarting. Migrations are applied before the new version serves
traffic. The administration shows a "new version available" notice from the project's own feed and **nothing else phones home**.
The in-app updater (download a zip, overwrite files) stays switched off (`Core\Updater::ENABLED`, HF-12) and is not extended.

## Decisions

| Question | Decision | Why |
|---|---|---|
| Registry | GitHub Container Registry, `ghcr.io/<owner>/talea` (the owner and name follow HF-15) | free for public images, tied to the repository, no extra account |
| Tags | `X.Y.Z` (immutable), `X.Y`, `X` (moving), `latest` = newest stable, `edge` = `main` | operators choose how much they follow; a patch pin never changes |
| Platforms | `linux/amd64`, `linux/arm64` | servers and Apple silicon development |
| Release trigger | a git tag `vX.Y.Z` on `main`; CI builds, tests, pushes and creates the GitHub release | one way to release, reproducible |
| Feed | `update.json`, a release asset (`.../releases/latest/download/update.json`), signed with the existing Ed25519 manifest signature (`Core\Signature`, key in `system/update.pub`) | static, cacheable, no server to run; the signature reuses what exists |
| Image signing | cosign keyless signature (GitHub OIDC) plus provenance and SBOM attestations; the feed carries the image digest | operators can verify what they pull |
| What a site sends | one `GET` of the feed at most every 24 h, no identifier, no query; switch off with `TALEA_UPDATE_CHECK=0` or the setting `update_check` | "nothing else phones home" |
| What a site does with it | shows a notice (new version, security flag, changes, the pull command); **never installs** | updating is the operator's act: `docker compose pull && up -d` |
| Migrations | `bin/migrate --if-installed` in the entrypoint, serialised by `GET_LOCK` (per database and prefix); forward-only | several replicas cannot race; a down-migration is not a rollback strategy |
| Backup before migrating | when migrations are pending, the entrypoint first writes a database dump to `storage/backups/` (`TALEA_BACKUP_BEFORE_MIGRATE`, default on) | the rollback has something to restore |
| Rollback | restore the dump and start the previous image tag; releases follow expand/contract: a release never drops what the previous release still reads | forward-only migrations stay safe |
| Replicas | any number of web containers share the `storage`, `media`, `extensions` volumes; exactly one runs the background loop (`TALEA_CRON=1`, default 1, set 0 on the others) | jobs run once |
| Health | `GET /health` (no session, no cache): 200 when the database answers and no migration is pending, 503 otherwise, with a one-word reason | an orchestrator restarts the right thing |
| Add-ons | `extensions/` is a volume: add-ons survive an image update; an add-on declares the core versions it supports (`extension.json` `requires`), the entrypoint logs add-ons that do not match | updates never delete operator code |
| Configuration | environment variables and `_FILE` secrets only (`TALEA_*`), no file in the image is edited | already in place |

## Pieces to build

1. `Core\UpdateFeed`: fetch, verify the signature, cache for 24 h (setting `update_feed_cache`), expose `available()` for the notice; honours `TALEA_UPDATE_CHECK`. Tests with a fake feed server.
2. Admin notice (dashboard, System status) from `UpdateFeed`; no install button.
3. `GET /health` in `Front\Kernel`.
4. `bin/backup` (database dump through PDO, no `mysqldump` needed) and the entrypoint step.
5. `release.yml`: build and push the multi-arch image, sign it, generate and sign `update.json`, create the release. Written now, enabled when the repository is ready.
6. `docs/DEPLOYMENT.md`: install, update, rollback, replicas, backup, verifying an image.
7. End-to-end test (needs Docker, skipped without it): boot version N in a compose project, write data, boot N+1, assert the data, the migration state and `/health`.

## Out of scope

Hosted multi-site offering, an add-on marketplace, automatic installation of updates, a rollback by down-migrations.
