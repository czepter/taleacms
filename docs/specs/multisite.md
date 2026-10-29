# Multisite: many sites of one owner

Decided on 29 October 2026 (`docs/DECISIONS.md`, Multisite). One install serves several sites of one owner – a freelancer's
studio, side business and course site – behind `TALEA_MULTISITE` (off by default). Several owners in one install (agencies) are
a later spec; the design below leaves room for it.

## Model

- **Owner = database.** Everything of one owner lives in one database. A second owner will be a second database, so nothing is
  ever shared across owners.
- **Site = table prefix.** Site 1 keeps the configured prefix (`tl_`); site *n* gets `tl{n}_` (`Sites::prefixFor()`). A site prefix
  never starts with the owner prefix: `Backup` and `Db::tablesSize()` select tables with `LIKE 'tl\_%'`, which must not catch
  `tl2_…`.
- **Owner tables** (`Db::OWNER_TABLES`) always use the owner prefix and are shared by every site: accounts and roles, media and
  folders, components, facts, the Claude connection (tokens, OAuth, agent sessions and journal) and the site registry
  (`sites`, `site_domains`). Every other table – pages, news, settings, jobs, stats, mail, media usage, add-on tables … – exists
  once per site prefix.
- `Db` knows both prefixes: `{pages}` expands with the site prefix, `{users}` with the owner prefix. Queries are written as before;
  a site can never read another site's rows. `Db::forSite()` opens the tables of another site on the same connection.
- A foreign key may point from a site table to an owner table (`news.author_id → users`), never the other way: an owner row
  cannot reference rows that exist once per site.

## Migrations

`Migrator::migrate()` runs the owner prefix first (owner tables and site 1), then every further site prefix. In a site run
`PrefixAdapter` sends owner table names to the owner prefix and skips every write to them (they were migrated in the owner run),
so a migration file is written once, as before. Raw SQL in a migration that touches an owner table skips it with
`MigrationSupport::ownerTableInSiteRun()` (the `table_prefix` option is the site prefix there). Index names of a further site get its prefix
(`tl2_uq_pages_slug`, `MigrationSupport::indexName()`), because PostgreSQL and SQLite want them unique in the whole database;
`Db::uniqueKeys()`, `dropUniqueKey()` and `addUniqueKey()` keep using the migration names. Any failure stops the run and the entrypoint refuses to start;
`bin/migrate --backup` dumps the whole database (every prefix) first.

## Per-site and owner-wide pieces

- **Cron:** `php system/docker.php cron` runs site 1 and then each further site in a child process (`cron <prefix>`), so static
  state never leaks between sites. Only live sites run, and site 1 always: the owner-wide jobs (`Scheduler::OWNER_JOBS`:
  backups, media sync, media staging, the undo journal, unused accounts) run only with it.
- **Page cache:** one folder per site: site 1 keeps `storage/cache/pages`, site *n* uses `storage/cache/pages-tl{n}_`.
- **Backups:** one file holds every prefix of the database; a restore puts back all sites at once. Rolling back a single site
  goes through site export and import.

## Routing and signing (phase 1, stage 1)

- `App::boot()` resolves the request's host through `site_domains` when `TALEA_MULTISITE` is on (never in the CLI, never without
  the flag: with it off the database is not asked). An unknown or trashed host is a plain 404; `/health` answers for site 1 on
  any host. `App::$site` carries the site row. The lifecycle lives in `Front\Kernel::siteLifecycle()`: paused = 503 for visitors,
  archived = 301 to the target's primary host or read-only: every request other than GET/HEAD answers 403, except a page password, unsubscribing and cancelling a booking (no GET route writes).
- `bin/migrate` is the startup guard: more than one site that is not trashed without the flag stops the container; with the flag
  and no domain on site 1, the host of `TALEA_SITE_URL` (or the `site_url` setting) becomes its primary domain.
- **Signing is bound by a per-site key, not by payloads.** Signed links (preview, form time stamp, page lock, share images …) all go
  through `Antispam::key()`. With `TALEA_SECRET_KEY` set it derives a key per site (`talea-signing|<secret>|<prefix>`; site 1 keeps
  the plain one), so no payload carries a site id and a link of one site is void on another by construction. Without the
  environment secret every site already has its own `secret_key` setting. Connector credentials keep using the environment secret
  directly: they are the owner's, not the site's.

## Phases

- **Phase 0** (no behaviour change): two prefixes in `Db`, the site registry with the row for site 1, the migration loop,
  backups over all prefixes, the cron loop, per-site cache folders.
- **Phase 1:** the flag and its startup guard, host routing (`site_domains`), the Sites area and switcher, sign-in handoff
  between hosts, clone, lifecycle states, the inbox across sites, shared components and facts, one Claude connection with an
  optional `site` argument.
