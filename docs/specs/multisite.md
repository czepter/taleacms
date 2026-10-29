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
  goes through site export and import. The file names its prefixes in a header line (`-- prefixes: tl_,tl2_`); a restore accepts exactly
  those (an older file without the line: the registry as it is now). Sites made after the backup are not in the restored registry, so their
  tables are dropped as part of the restore; a site removed since comes back (its tables are created first when missing: PostgreSQL and
  SQLite backups hold data only). The owner prefix is never dropped.
- **Media are owner rows, their use is per site:** `Media::findUsagesElsewhere()` (the clean-up's unused list, the bulk delete, MCP
  `delete_media`) reads the places that can hold a file on every set-up site (`Sites::otherReady()`), news usage of the others included. A file
  any site uses is in use. `LinkHealing` and a class rename rewrite only the current site's own components; shared ones belong to every site.

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
- **Forms and shared components:** forms are a site's own rows (`forms`, enquiries). A shared component may hold no Form or form_embed
  element (`Components::formRefusal()`): sharing such a component is refused (admin and MCP `shared: true`), and so is saving or
  publishing a form into a shared one. The Forms list and `adoptAll` look at the site's own and shared components only, and rewrite only its own.
- **Cache:** a change to a shared row calls `Front\Cache::clearAll()` (`storage/cache/pages` and every `pages-*`), a local one `clear()`.
- **Clone:** `Sites::provision()` also copies the source's own components (new rows, new public ids) and facts, and repoints the
  `component` elements in the copied builds (pages, site parts, pop-ups, collections and their templates, the version history, saved
  sections, and the component copies themselves), the variants of the copied experiments, the version keys `component:<id>` and the origins
  of the forms placed in them. Shared rows are not copied. External connections (`connectors`, `agent_schedules`, `fleet_sites`) are not
  cloned: a copy would act in the clone's name. `Sites::add` skips a number whose prefix already has tables.
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
  `site` (add-ons are loaded once for the called site). The runners (`run_read_tool`, `run_tool`, `run_destructive_tool`) take `site` too and
  pass it to the tool they run (a `site` inside `arguments` wins). **Known limit:** the listeners and filters an add-on registered at boot stay
  bound to the site the connection called, so an add-on event raised during a `site` call reaches the add-on's code of the calling site, not
  of the sibling; add-ons that react to content events should not be relied on across sites in phase 1.
- **Static state** switched for the call and put back afterwards: `Front\Cache` (page-cache folder), the time zone, `Core\Language` (site language
  and register), the memos of `Core\Slug` and `Core\Routes`. `Core\Facts` keys its memo by prefix by itself; `Sites::currentId()` is keyed by prefix.
- **Undo:** `agent_journal.site_prefix` (owner table, '' = the owner's own site) records the site an entry belongs to; `AgentJournal::undo`
  writes each row back on that site (same connection), so a session spanning several sites is taken back in one step.
- **Site tools** (`Mcp\Handlers\SiteTools`, only with the flag, administrators only, access "write" so a drafts-only or read-only connection cannot use
  them): `list_owner_sites` (read), `create_site`, `update_site` (name, status, redirect target), `trash_site` (destructive: `claude_destructive = 0` refuses it, and it routes through `run_destructive_tool`), `restore_site`, `add_site_domain`,
  `update_site_domain` (primary, redirect), `remove_site_domain` (destructive). `list_sites` and `get_site` belong to the fleet console.
  Results carry public ids only. The server instructions mention the `site` argument.

## Solopreneur additions (stage S)

What the freelancer with several sites still needed after phase 1. All of it needs `TALEA_MULTISITE` and an administrator; with the flag
off nothing of it exists (no tab, no setting, the element is neither offered nor printed).

- **Overview tab** (Sites area → Overview, `Core\SiteFigures`): one row per set-up site that is not in the trash, for 7 and 30 days, plus
  the totals: visitors, page views, enquiries, bookings, newsletter sign-ups and the conversion (enquiries + sign-ups per visitor). Each
  site is read through `Db::forSite()` with the Statistics screen's own query (`Report::glance`); only the bookings are counted here. The
  admin action is read-only (ParityTest: `read`); Claude gets the same numbers per site from `get_stats` with the `site` argument.
- **One monthly report** (`report_all_sites`, Settings → Reports, shown and saved on site 1 only; `Core\AllSitesReport`): site 1's report
  carries a section "Site: <name>" for each of the other set-up, not trashed sites (visits and page views against the month before,
  enquiries, sign-ups, bookings – counts only), and `MonthlyReport::runIfDue` of a further site answers "in the report of site 1" while
  the setting is on. The sections are part of the archived month (`sites` in `MonthlyReport::build`). Site 1's own report is the main
  body, so "a section per ready site" means the other sites. The setting lives in site 1's `settings`; a further site reads it from there
  (`AllSitesReport::coveredBySiteOne`).
- **Hub card** (`sites.card_summary` 300, `sites.card_image` 255, `sites.show_in_hub`; owner table, migration `20261102100047`): edited
  in the Sites area (Card on the other sites) and by MCP `update_site` (`card_summary`, `card_image`, `show_in_hub`; `list_owner_sites`
  returns `card`). The image is a `media/…` path (anything else is refused). `Sites::saveCard` clears every site's page cache, as do a
  site's state, trash and host name changes, because the cards show on the other sites.
- **Projects element** (`site_cards`, `Builder\Elements\SiteCards`, group Dynamic): the cards of the owner's OTHER sites that are set up,
  `live`, switched on for the hub and have a host name: name, summary, image (the page's image pass `Front\ImageHtml::complete` adds the
  dimensions) and a link to the primary host (scheme and port of the request, `Sites::originOn`). `Element::MULTISITE = true` marks it:
  `ElementSchema::disabledTypes()` leaves it out when the flag is off and `Renderer` prints nothing; a build that holds it stays. With one
  site (or no other card) it prints nothing; the builder shows a dashed notice.
- **Subscribers, all sites** (`sites=all`, `Core\SubscriberSites`): the same "this site / all sites" select as the inboxes; one row per
  e-mail address (compared in lower case) over the set-up sites' lists with a chip per site and its state (confirmed, awaiting
  confirmation); a chip of another site opens that site's Subscribers list through the handoff (`to=module=subscribers`). Read-only:
  deleting, sync and CSV stay per site.

## Several owners (agency, stage A) – Proposed

Decisions of the night build, marked **Proposed** for the owner's review. Needs `TALEA_MULTISITE` and `TALEA_OWNERS`; with the flag off
(or with only owner 1) nothing here is consulted and the install behaves as before.

- **Three classes of table.** `Db::INSTALL_TABLES` (`owners`) live under the install prefix (the configured `tl_`), `Db::OWNER_TABLES` under
  the owner's prefix, every other table under the site prefix. `Db` knows `installPrefix`, `ownerPrefix` and `prefix`; `table()` picks in that
  order, `forSite()` keeps the first two, `forOwner()` switches the owner (its site 1 is its owner prefix). The config `prefix` stays the
  install's; `owner_prefix` and `site_prefix` are optional.
- **Prefixes (`Owners::prefixFor`).** Owner 1 = the install prefix; owner *n* = install stem + letters (`tlb_`, `tlc_` … `tlz_`, `tlba_`); a
  further site of an owner = its prefix stem + number (`tlb2_`). Letters belong to owners and digits to sites, so no prefix starts with
  another and every `LIKE 'tlb\_%'` (backup, size, drop) is exact (unit test over 60 owners × 12 sites). The install prefix must be letters
  only to have further owners. `Owners::ownerOf()` reads the owner out of any prefix.
- **Registry.** `owners` (owner_id, public_id, name, kind person|organisation, prefix unique, status live|paused|archived, created_at,
  `ready_at`), migration `20261103100047`, row of owner 1 with the install prefix. `Owners::add` inserts a row only; cron
  (`Owners::provisionPending`, install's run) migrates, makes sure the owner's `sites` has its site 1 (the migration seeds it) and sets `ready_at`.
- **Migrations.** `Migrator::migrate`: install (owner 1: install tables, owner tables, site 1), owner 1's sites, then each further owner (its owner
  tables and site 1, then its sites). `PrefixAdapter`/`MigrationSupport`: an install table is made in the install run only and named with
  `install_prefix` everywhere; an owner table in each owner's own run. Index names carry the table's prefix unless it is the install's, so
  PostgreSQL and SQLite names stay unique database-wide (`tlb_uq_users_username`); `Db::keyName()` reads them back.
  `bin/migrate` refuses to start when further owners exist and `TALEA_OWNERS` is off.
- **Backup.** One file, header `-- prefixes: tl_,tlb_,tlb2_` (each owner followed by its sites). A restore accepts exactly the shapes of this
  install, drops the tables of owners and sites made after the backup (sites before their owner) and recreates the ones missing
  (`Migrator::migratePrefix` finds the owner from the prefix).
- **Routing.** `Sites::resolve` reads every set-up owner's `site_domains` in one `UNION ALL` and returns the owner prefix and status;
  `App::boot` sets `owner_prefix` and `site_prefix`. A host is unique in the whole install: `Owners::hostTaken` counts the hosts of all owners
  whatever their status. A paused owner answers 503 on all its hosts (admin too, `/health` excepted), an archived owner 404; owner 1 can be
  neither (`Owners::setStatus`). The page cache folder is `pages-<site prefix>`; `Cache::clearAll()` clears only the owner being served.
- **Signing.** `Antispam::key()` derives a key for every site prefix but the install's own, so owner B's site 1 differs from owner A's.
  `TALEA_SITE_URL` locks only the install's site 1 (`SiteUrl::locked`).
- **Sessions.** The PHP session store is shared, so a session of a further owner carries `_owner`; an id presented on another owner's host
  (the cookie is host-only but a header is not) gets a fresh empty session and is left untouched. Without it a user number and password
  hash that two owners happen to share would sign one in as the other.
- **Cron.** `cron` runs owner 1's site 1, then children `cron <site>` for its sites and `cron <owner> <owner>` for each live further owner;
  that owner's run provisions its new sites and starts `cron <owner> <site>` for its live sites. A paused or archived owner runs nothing.
  The owner-wide jobs run once per owner (with its site 1); `backup` and `media_sync` only for owner 1.
- **Inside one owner, as before.** Sites list, switcher, inbox, overview, subscribers, MCP `site`, media usage, staging, provisioning, handoff
  all read the current owner's `sites` table. Only routing, `Owners`, migrations, backup and cron read another owner's registry.
- **Files of the install belong to owner 1 for now.** Backups and their off-site copy, exports/imports (the Import and export module, the staged
  WordPress file), the error log and the media archive are single folders of the install; for a further owner the screens, actions and
  jobs are refused (`Owners::sharedFilesRefusal`, `Module::INSTALL_FILE_ACTIONS`, `Transfer::availableTo`). Uploads still share `media/`:
  names end in six random hex digits and waiting files are looked up in the owner's own `media` table; per-owner folders are stage B.
- **Isolation test** (`tests/Site/Multisite/OwnersIsolationTest.php`, `tests/Integration/OwnersTest.php`): two owners with the same page
  slugs, component names and media paths; public routes (page cache), search, sitemap, llms.txt, feed, admin lists, the other owner's component,
  session cookie replayed by hand, MCP tokens and `site`, signed preview links, handoff tokens, waiting files, backups and exports, owner
  state, cron and the start guard.

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
- `TALEA_SITE_URL` is the address of site 1 only (`SiteUrl::locked()`); a further site edits its own `site_url`.
- The `site` argument is added to the schema like `reason` and is not in the contract file.
- Importing a Talea archive is refused while the install has several sites (above).
- Open for later phases: several owners (databases) in one install, per-site roles, domain verification and certificates, moving a single site
  between installs.
