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
  jobs are refused (`Owners::sharedFilesRefusal`, `Module::INSTALL_FILE_ACTIONS`, `Transfer::availableTo`). Uploads have a folder per owner (below).
- **Isolation test** (`tests/Site/Multisite/OwnersIsolationTest.php`, `tests/Integration/OwnersTest.php`): two owners with the same page
  slugs, component names and media paths; public routes (page cache), search, sitemap, llms.txt, feed, admin lists, the other owner's component,
  session cookie replayed by hand, MCP tokens and `site`, signed preview links, handoff tokens, waiting files, backups and exports, owner
  state, cron and the start guard.

### Operators, owner administration, media per owner, cross-owner clone (stage B) – Proposed

- **Operators** are the administrators of owner 1 (`Owners::isOperator`: an unblocked administrator of owner 1 **asking from owner 1's own
  administration**). The row that stands for an operator inside a client owner is NOT one there: it is an ordinary administrator of that owner,
  because the client's own scripts (head code, components) run in the client's origin with that session. Operator powers – copying a site across
  owners, vouching for a host, adding a host without verification – exist only in owner 1's administration (the Owners area) and on an MCP
  connection of owner 1. Nothing is configured: a client owner has no operators of its own, and an editor of owner 1 is no operator.
- **Owners area** (Settings → Owners, `Admin\Modules\Owners`, only with `TALEA_OWNERS`, only in owner 1's administration; the module is
  not offered anywhere else and its actions answer like an unknown module). List: name, kind, state, sites, storage (tables of all its sites plus its
  media folder). Create: name, kind and the e-mail of the first administrator (`owners.admin_email`); the row is all the request writes, cron
  sets the owner up. Pause/archive/live through `Owners::setStatus` (owner 1 cannot be paused). Add the first host name to the owner's
  first site (`Sites::addDomain`, install-wide unique), then **Send the invitation**: `Owners::invite` makes the administrator in the owner's
  `users` (a password nobody knows) and mails a link valid for 3 days to the owner's first host, through the install's mail setup. Further host
  names and sites are the owner's own business. **Copy a site** (Owners → Copy a site) picks any set-up site of any owner and the owner that gets
  the copy; **Mark as verified** (owner → Host names waiting for verification) vouches for a client's host. The copy is also MCP `create_site`
  with `clone_of` and, for another owner, `owner` – on owner 1's connections only; the rest has no tool (ParityTest says so per action).
- **Cross-owner handoff** (`Owners::handoff`, action `handoff`): only an unblocked administrator of owner 1 asking from owner 1, for a live owner
  that is set up and a site of it that is set up. The token is written in the TARGET owner's `handoff_tokens` (so owner 1's
  and every other owner's table never know it), bound to that site and to the network of the person who asked (below), 60 seconds in UTC, single
  use (`Sites::claimHandoff`). It signs in the operator's row in the target owner (`Owners::operatorRow`), made on first use. It lands on the site's
  **preview host** when `TALEA_PREVIEW_DOMAIN` is set (the install controls that DNS), else on a **verified** host of the site; with neither there is
  nothing to open. Documented risk: with no preview domain the landing host is the client's own verified name.
- **Handoff tokens (every handoff, also inside one owner) are bound to the network of whoever asked** (`handoff_tokens.network_hash`, sha256 of
  `Antispam::visitorKey`, i.e. REMOTE_ADDR or the address behind `TALEA_TRUSTED_PROXIES`/Cloudflare, an IPv6 address by its /64). A claim from another
  network is refused and does not use the token up. Behind a CDN whose edge address changes between two requests of the same person, set
  `TALEA_TRUSTED_PROXIES` so the visitor's own address counts.
- **The operator's session in a client** also depends on the operator's password: the session keeps a fingerprint of the operator's password in owner 1
  (`Owners::operatorFingerprint`), compared on every request, so a new password of the operator ends it; turning two-factor sign-in on or off, removing a
  passkey, an administrator's 2FA reset or "end stay signed in" of the operator remove the operator's rows in the clients (`Auth::forgetOtherDevices` →
  `Owners::revokeOperator`).
- **The operator row** is a normal administrator row of the client's `users` flagged with `operator_user_id` (owner 1's user number; migration
  `20261104100047`), named "Operator: <name>", without e-mail, with a random password nobody has. It cannot escalate and cannot be used by
  the client: password sign-in and passkey, "stay signed in", password reset and MCP/OAuth tokens skip it (`operator_user_id IS NULL` in
  `Auth::account`, `verifyKey`, `restoreSignIn`, `PasswordReset`, `Mcp\Server::user`); the client's Users module refuses edit, save, delete,
  password link, reactivate and revoking its connections (`Users::operatorRefusal`); My account refuses every change to it (no password,
  e-mail, second factor or token); the security checks leave it out. It is only as good as the operator: every session and every claim of
  a handoff re-checks owner 1 (`Owners::operatorValid`: still an administrator, not blocked), and `Owners::revokeOperator` removes the rows
  (with their tokens) from all owners when the operator is deleted, blocked or demoted in Users. The change log shows the name, so every
  action reads "Operator: …".
- **Media per owner** (`Core\MediaFolder`): owner 1 keeps `media/Y/m/…`, a further owner writes `media/<owner public id>/Y/m/…` (the public id
  is the UUID of its `owners` row; the process learns it in `App::boot`, from routing or from the cron child's owner prefix). The path stored
  in `media` rows and content includes the folder, so rendering needs no knowledge of it; `MediaFolder::OWNER` is the optional folder in
  every pattern that recognises a media path (`Images`, `Files`, `Mailing`, `MediaStaging`, `MediaHygiene::paths`, `ImageHtml`, news, Media). Site icons
  sit in the owner's folder too. Waiting files stay in `storage/staging/` under the same relative path, which now carries the owner, so
  nothing collides and `MediaStaging` needs no per-owner root. Owner 1's archive, off-site media copy and size leave the folders of the
  other owners out (`SiteExport::mediaFiles`, `MediaFolder::bytes`). An owner's folder can be measured, copied or removed alone.
- **Cross-owner clone** (`Core\OwnerClone`, an operator's tool): the "A copy of …" list of the Sites area and MCP `create_site` with `clone_of`
  also take a site of another set-up owner, for an operator only (anybody else cannot even name such an id). The new site records
  `sites.source_owner` and is set up by cron like any clone. Copy semantics, nothing is linked: the site tables are copied as in a clone
  within one owner (never the install tables); the source's components and facts – its own and the ones it shared in its owner – become LOCAL
  rows of the new site with new ids and the builds are repointed; the media files its content (and its news usage) names are copied, with
  their variants, into the target owner's folder with new `media` rows (an earlier copy of the SAME file is reused; a different file already under that
  name is never touched – the copy gets a stem of its own and the paths in the content follow), and the paths in the copied
  content, settings, classes, menus and components are rewritten; authors, revision users, drafts of people and booking staff, which point at users
  of the source owner, are copied as NULL inside the `INSERT … SELECT` (the target's foreign keys to its own users would refuse them). Of the settings
  only `SiteExport::SETTINGS` (without `site_url`) cross owners – **never the account settings** (SMTP, mail identity, AI key). Waiting (staged) files are
  not copied. Both sites get a row in their change log (`clone_out` on the source, `clone_in` on the copy); a client's log says only "Created as a
  copy by the installation's operator" / "Copied by the installation's operator", the names stand in owner 1's log, and the request itself is logged in
  the operator's.
- **Rate-limit counters** (`storage/cache/limits`): a visitor's address still counts across the owners (open), but a key built from a row number or
  an account is prefixed with the owner (reset allowance per account, OAuth refresh, member link per e-mail) or the site (page password), so two owners'
  user number 1 do not share a budget.

## Domains and certificates (stage C) – Proposed

- **Verification.** `site_domains` gets `verified_at` (NULL = unverified), `token` (32 hex, kept until verified) and `kind` (`domain` | `preview`);
  migration `20261105100047` marks the hosts that exist verified. `Sites::resolve` returns `verified` (the row, or `TALEA_VERIFIED_HOSTS`) and `kind`;
  `App::routeHost` answers an unverified host like an unknown one (bare 404, `/health` excepted). `Sites::addDomain(…, $verify)` makes the host
  unverified with a token when `Domains::needsVerification` says so: only with `TALEA_OWNERS` and not for operators. Without several owners the
  solopreneur install is unchanged: verified on add. Operators may mark a host verified (Owners → the owner → Host names waiting for verification, in owner 1's administration only; not a tool);
  `TALEA_VERIFIED_HOSTS` vouches by name **for owner 1's hosts only** (`Sites::resolve`), never for a client's add or a later check.
- **Checks** (`Core\Domains::check`, "Check now" in the Sites area and MCP `check_site_domain`, listed as an open-world tool): **owner 1**: first
  `http://<host>/.well-known/talea-domain/<token>` must answer 200 with the token, else `TXT _talea.<host>` must hold `talea-verify=<token>`.
  **A client owner (`Domains::dnsOnly`): the TXT record only** – the token file would be answered for any name that already points at the install.
  A name a client adds must be a public name (`Sites::normalizeHost($host, true)`: at least two labels, no IP literal). The host is the
  client's input, so the fetch goes through `Links::target` (the one safe-target helper: `Outbound` normalisation, every resolved address public, standard
  ports, pinned connection, no redirects, 256 bytes) – never to loopback, private, link-local or CGNAT addresses; tests may fetch `127.0.0.1` only with
  `TALEA_LINKS_LOCAL=1`, as the link check does. The token file is answered by `Domains::answerEarly` for the host that has the pending token and no other.
- **Competing claims.** A host is unique per owner by key; across owners only VERIFIED rows block (`Owners::hostTaken`): a name nobody has proven does
  not hold it against the owner who can. When a name is verified (check, operator, or added verified), the unverified rows of the same name in every other
  owner are deleted (`Domains::dropUnverified`) and a name already verified elsewhere cannot be verified; routing prefers the verified row. An unverified
  host is removed after 14 days (`site_domains.created_at`, in the `sites_cleanup` job).
- **`GET /_talea/tls-allowed?domain=`** is answered in `App::routeHost` before routing, on any host: 200 for a verified host (owner 1's list `TALEA_VERIFIED_HOSTS`
  included) and a preview address (not of a trashed site or an archived owner), 404 for the rest, identical bodies, `no-store`, never the owner. Rate limit:
  `Antispam::tally` **per requested name**, 300 a minute, then 429, and per network unless the question comes from a trusted proxy (`TALEA_TRUSTED_PROXIES`):
  a flood of random names does not lock out the others. The token path has a counter of its own, per visitor network.
- **Preview subdomains** (`TALEA_PREVIEW_DOMAIN`): `kind = preview`, verified, never primary, not removable. Owner 1: `{slug}.{preview}`; owner n:
  `{slug}.{letters}.{preview}` (`tlb_` → `b`). Added by `Sites::provision` for a new site and by cron (`Domains::ensurePreviews`, the install's run) for the sites
  that have none – once. Always noindex: `X-Robots-Tag` in `routeHost`, the robots meta and `robots.txt` in `Front\Seo`, no IndexNow, and no page cache
  (`Front\Cache::file`), because the cache key follows the site address and not the host. `Sites::primaryHost` (handoff, switcher) prefers a verified host, so a
  site whose own host is not verified yet is still reachable through its preview address; a preview row does not count as the "first domain".
- **Open:** an alias that redirects to a primary host that is not verified yet sends visitors to a 404; DNS lookups of the TXT check use the container's
  resolver; no automatic re-verification (a host stays verified until removed).

## Agency tools (stage D) – Proposed

Decisions of the night build, marked **Proposed** for the owner's review. Needs `TALEA_MULTISITE`; the limits of owners need `TALEA_OWNERS`.

- **Roles per site.** Owner table `user_sites` (user_id, site_id; migration `20261106100047`, rows go with the user and with the site) and the flag
  `users.sites_limited` (migration `20261107100147`; people who had rows are limited). A person **who is not limited may work on every site**, one **who is
  limited only on the sites in their rows – limited with no rows works on none**: "every site" is never derived from an empty list (so a cascade after a
  site's clean-up, a saved empty checklist or a trashed site cannot make anybody unlimited). **Administrators always have every site** (flag and rows are
  ignored; so are the operators' rows in a client owner): the limit is for editors, news authors and custom roles. `Sites::limitedTo/allows/allowsHere`
  is the one question; it is asked in these places:
  - `Auth::user()`: a signed-in person (session or "stay signed in") who may not work on this site is **treated as signed out there** and sees the sign-in
    page – the simplest and safest answer, since the session is host-bound and nothing of the site is shown. `Auth::login` answers "You have no access to this
    site." only after the password was right, so the message does not reveal which accounts exist; nothing is recorded as a sign-in.
  - MCP: `Server::user()` returns no connection for a person who may not work on the site the token calls (401, like an invalid token); the `site`
    argument (`onSite`) answers "Unknown site" for a site the person may not use – the same text as for an id that does not exist.
  - The handoff: `Sites::handoffToken` refuses to make a token for such a person and `claimHandoff` returns nobody for one (a limit set after the token was made).
  - Lists: the site switcher, the inbox "all sites" filters (`Modules\Sites::inboxSites`) and the Overview (`SiteFigures::overview($db, $user)`) leave out
    the sites the person may not use. These screens are administrators' (who have every site), so the filter is defence in depth.
  - Edited in Users ("Every site" / "Only the sites ticked" and a check list, shown when there is more than one site and for anybody but yourself; saving an
    administrator clears flag and rows). The list changes only when the form carried it (`sites_shown`), and the rows of a site in the trash are kept (the list
    does not show them). There is no MCP tool for users, so there is none for this (ParityTest: users are an administration matter).
  A site made later is not in anybody's existing list: a limited person is added to it by hand. A site number is never given out twice (identity column,
    also SQLite's AUTOINCREMENT), so no old row can point at a new site.
- **Limits.** `TALEA_MAX_SITES` counts the sites that are not in the trash over **every owner** of the install (an owner not set up yet counts one);
  `TALEA_MAX_OWNERS` counts the owner rows. Creating more – and restoring a site from the trash (`Sites::restore`, admin and `restore_site`) – is refused with
  a message in the Sites and Owners areas and by `create_site`. A limit lower than what exists stops creation only; nothing is removed.
- **Health.** The Sites list shows per site the open enquiries (not resolved, not spam, not in the trash), the broken links and the storage; the Owners list per
  owner the sums over its sites, the images without a description and the storage. Media files belong to the owner (they are shared by its sites), so
  "images without a description" is **one number per owner**, not per site (`SiteHealth::mediaWithoutAlt`: the same test as the clean-up, without reading
  any file). MCP `list_owner_sites` carries `health` for each set-up site. An owner's administrator reads only its own owner; the Owners area is the operators'.
- **Usage CSV** (`SiteHealth::usageCsv`): per site and month (the last 12 by default, `months=` up to 24) – storage, e-mails sent, bookings, enquiries received.
  Storage is the size now and sits on the current month's row only (it is not recorded over time); e-mails sent come from the mail queue, which keeps 30 days, so
  older months read low. **The writing assistant is not in the file: it does not record its calls** (only the wizard and the Ask box write a change-log row).
  Administrators download their own owner's file in the Sites area, operators every owner's in the Owners area (`usageCsvAll`); `;`-separated with a byte order
  mark like the other exports, cells that start like a formula are quoted. Both are read-only actions (ParityTest `read`).
- **Smoke test** (`tests/Integration/AgencySmokeTest.php`, all three engines): two owners with ten sites each are registered, migrated, set up and given a host; each host
  is resolved to its owner and site and the scheduler runs once on every site but owner 1's first (its owner jobs write the install's backup folder). SQLite is the
  slow one (about 75 s for the migrations); the test's budget is 240 s.
- **Open:** a site made later is not added to the limited people automatically; the limit is not shown to a limited person anywhere but at the sign-in; storage
  history and AI usage would need recording first.

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
- Open for later phases: several owners (databases) in one install, per-site roles, moving a single site
  between installs.
