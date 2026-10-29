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

## Shared components and facts, the inbox (phase 1, stage 3)

- `components.site_id` and `facts.site_id` (nullable): the site a row belongs to, or NULL = shared with every site of the owner. Rows that
  existed before belong to site 1. `Sites::currentId()` (the prefix's number, memoised) and `Sites::visible()` (`site_id IS NULL OR site_id = n`)
  are in every read; a component of another site is not found by number (`Components::byId`, the `/_component/<public id>` preview, MCP).
  New rows are written with `Components::create()` (the current site, or shared). With the flag off every row is site 1's and nothing changes.
- **Facts:** the unique key is `(fact_key, language, site_id)`, but a NULL never collides in a unique key (MySQL, PostgreSQL, SQLite), so
  "one shared row per key and language" is kept by `Facts::save()`, not by the database. A site's own row wins over the shared one with the
  same key and language (`Facts::all()` skips the shared row); saving with `shared = false` on a shared fact gives the site its own copy,
  deleting removes the site's own rows first and the shared row only when it has none. A translation follows its default row.
  `fact_history` is not split by site. **Components** go one way only: shared stays shared (other sites may use it); delete removes it for all.
- **Cache:** a change to a shared row calls `Front\Cache::clearAll()` (`storage/cache/pages` and every `pages-*`), a local one `clear()`.
- **Clone:** `Sites::provision()` also copies the source's own components (new rows, new public ids) and facts, and repoints the
  `component` elements in the copied builds (pages, site parts, pop-ups, collections and their templates, the version history, saved
  sections, and the component copies themselves) and the variants of the copied experiments. Shared rows are not copied.
  Removing a site (`Sites::cleanup`) deletes its own components and facts (the site number may be given out again).
- **Inbox:** Enquiries and Bookings get "this site / all sites" (`sites=all`, administrators with more than one set-up site). Enquiries:
  `UNION ALL` of the sites' tables, paged and filtered like the list of one site; Bookings: the sites' agendas merged by time (people and
  services are per site, so those two filters are off). A row of another site names its site and opens there through the sign-in handoff
  (`Sites::landing()`: the handoff may carry an administration address to land on, validated, never a host).
- **Talea import:** `SiteImport` empties and fills owner tables (media, components, facts), which would delete the other sites' files and
  shared rows. It is refused (`SiteImport::refusal()`: the Import screen, the start and the batch itself) while the flag is on and more
  than one site is not in the trash. The Backups tab says a restore puts back every site.

## Claude (MCP) across sites (phase 1, stage 4)

- A connection (a token is an owner row) acts on the site whose host it calls. When the flag is on, `Server::withReason()` adds an optional
  `site` string (a site's public id) to every tool's schema except the site tools and the meta tools – exactly like `reason`, so the
  contract file `tools/contracts/mcp-tools.json` does not change for it. `Server::call` strips it and `Server::onSite()` resolves it among the
  owner's sites: unknown, trashed or not yet set up is an error result, never a silent fallback. The call then goes through `call()` again on a
  `Server` built for an `App` of that site (same configuration with `site_prefix`, the user and the connection signed in), so access, guardrails,
  the journal, the change log and the cache all hold for that site; a result that is an object names the site (`on_site`). Add-on tools refuse
  `site` (add-ons are loaded once for the called site).
- **Static state** switched for the call and put back afterwards: `Front\Cache` (page-cache folder), the time zone, `Core\Language` (site language
  and register), the memos of `Core\Slug` and `Core\Routes`. `Core\Facts` keys its memo by prefix by itself; `Sites::currentId()` is keyed by prefix.
- **Undo:** `agent_journal.site_prefix` (owner table, '' = the owner's own site) records the site an entry belongs to; `AgentJournal::undo`
  writes each row back on that site (same connection), so a session spanning several sites is taken back in one step.
- **Site tools** (`Mcp\Handlers\SiteTools`, only with the flag, administrators only, access "write" so a drafts-only or read-only connection cannot use
  them): `list_owner_sites` (read), `create_site`, `update_site` (name, status, redirect target, trash, restore), `add_site_domain`,
  `update_site_domain` (primary, redirect), `remove_site_domain` (destructive). `list_sites` and `get_site` belong to the fleet console.
  Results carry public ids only. The server instructions mention the `site` argument.

## Phases (phase 1 is done)

- **Phase 0** (no behaviour change): two prefixes in `Db`, the site registry with the row for site 1, the migration loop,
  backups over all prefixes, the cron loop, per-site cache folders.
- **Phase 1:** the flag and its startup guard, host routing (`site_domains`), the Sites area and switcher, sign-in handoff
  between hosts, clone, lifecycle states, the inbox across sites, shared components and facts, one Claude connection with an
  optional `site` argument.

### Deviations from the first design, and what stays open

- Signed links are bound to a site by a per-site derived key, not by a site id in the payload (above).
- A new site is provisioned by cron (`docker.php cron`), never on a page request: the admin and MCP only insert the row.
- A clone is an SQL copy (`INSERT … SELECT`) of the source's site tables without logs, queues, counters and visitors' data, with an
  allow-list of settings (`SiteExport::SETTINGS` and the account settings); secrets are made again.
- The `site` argument is added to the schema like `reason` and is not in the contract file.
- Importing a Talea archive is refused while the install has several sites (above).
- Open for later phases: several owners (databases) in one install, per-site roles, domain verification and certificates, moving a single site
  between installs.
