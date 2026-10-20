# Talea

A self-hosted website builder for small businesses and freelancers: pages, news/blog, a visual page
builder, collections, forms, bookings and a connection to language models (MCP). Plain PHP 8.5+ with its own PSR-4 autoloader
(`system/bootstrap.php`), MySQL 8 through PDO, server-rendered HTML and a little vanilla JS. Composer is used for infrastructure only
(Phinx migrations, PHPUnit); application code stays framework-free. GPL v2 or later; this is a hard fork of the Czech project Kaleta
(see `NOTICE` and `docs/DECISIONS.md`): no upstream merges, no installed base, no compatibility layers.

## Principles

- **Simplicity over abstraction.** A reasonably experienced reader must be able to follow the code. No DI containers, no ORM, no build
  step, no npm in the product. A new dependency needs a strong reason.
- **Web standards of 2026/2027, no old browsers.** CSS layers, `clamp()`, container queries, `color-mix()`/OKLCH, `:has()`, Popover API,
  `<dialog>`, `<details>`, View Transitions. Interaction without JavaScript where possible. No polyfills, no CDN, no foreign fonts
  (fonts are self-hosted).
- **What nothing emits has no style and no script.** A selector in `image/web.css` or the template CSS that no markup produces, and a
  script that looks for a `[data-…]` element nobody renders, are bugs (`tests/Unit/ScriptsTest.php` guards the scripts).
- **English everywhere.** Code, identifiers, comments, tests, docs, workflows and file names are English. Czech exists only as a
  selectable translation (`system/languages/*cs*.php`, `image/languages/admin-cs.js`) and in the WordPress import fixtures.
  `php tools/check-english.php` must exit 0; a line that must keep Czech on purpose says `check-english: allow`.
- **UI text through `t('English text')`** (`Core\Language`); the English text is the dictionary key. Dictionaries: site
  `system/languages/<code>.php`, admin `admin-<code>.php`, installer `install-<code>.php`, admin scripts `image/languages/admin-<code>.js`
  (`T()`). Languages: English (source), Czech, German (formal, plus an informal `-du` overlay), Spanish, French, Italian, Polish, Slovak.
  Add translations with `tools/add-translations.py`. Form values are not translated. Default texts shown to visitors:
  `Settings::TRANSLATED_DEFAULTS`.
- **No `window.confirm()`** in the admin: use the attribute `data-confirm="text"`. **Admin CSP is `script-src 'self'`:** no inline scripts,
  no `on*=` attributes; behaviour goes into `image/admin.js` via `data-` attributes.
- **URL query parameters are English** (`preview_key`, `build`, `page`, `search`, `status`, `item` …) in links, GET forms,
  `Request::get*()`, JS and tests.
- **Time:** the zone is the setting `time_zone` (`App::applyTimezone()`); write with `date()`, compare with `NOW()`.

## Data model and database

- Tables are English with the prefix `tl_` (written `{pages}` in code, never with the prefix); columns are English; every row that is
  addressed from outside carries a UUID v4 `public_id` (`Db::PUBLIC_ID_TABLES`, filled by `Db::insert`, looked up by
  `Db::byPublicId`); the integer key stays inside the database layer. Admin URLs, MCP arguments and results, previews, webhooks and
  exports use the public id, never a row number.
- **Schema changes are Phinx migrations** in `system/database/migrations/` (timestamped, table DSL, no raw SQL unless unavoidable, no
  `COLLATE`/`CHARSET`: the collation `utf8mb4_0900_ai_ci` is set once on the database). `bin/migrate` applies them (installer, Docker
  entrypoint, tests); no migration runs on a page request. Engine: MySQL 8 (also MariaDB 11.4+, driver `mysql`) or PostgreSQL 18 (`TALEA_DB_DRIVER`). MariaDB has no row
  alias in upserts: `Db::pdo()` detects it from the server version and `Dialect\MySql(mariaDb: true)` writes `VALUES(col)` instead of `new_row.col`.
- **SQL is written portably (HF-14): the same code runs on MySQL 8 and PostgreSQL 18.** Not in product SQL: `INSERT IGNORE` / `ON DUPLICATE KEY`
  (`Db::insertIgnore()`, `insertIgnoreSelect()`, `upsert()` with `{old.col}`/`{new.col}` expressions), `IF()` / `IFNULL()` / `FIELD()` (`CASE`, `COALESCE`,
  `Dialect::listPosition()`), `GROUP_CONCAT`, `JSON_EXTRACT`, `MATCH … AGAINST` (`Dialect::groupConcat()`, `jsonExtract()`, `fulltextMatch()`), `CURDATE()`
  (`CURRENT_DATE`), `SHOW …` or `information_schema` with `DATABASE()` (`Db::columns()`, `uniqueKeys()`, `tableExists()`), `GET_LOCK` (`Db::lock()`), `"text"` for strings.
  Boolean columns: `= TRUE` / `= FALSE`, `SUM(CASE WHEN … THEN 1 ELSE 0 END)`, and `TRUE`/`FALSE` or a parameter in `INSERT`s; fetched booleans are 1/0 on both
  engines (`Core\PgStatement`). `INTERVAL 5 DAY` and `INTERVAL ? HOUR` are fine (`Postgres::rewrite()` turns them into PostgreSQL syntax). User-facing `LIKE` goes through
  `Dialect::likeInsensitive()`; NULL ordering that shows is made explicit (`col IS NULL, col`). Schema differences live in `Core\MigrationSupport`.
  `tests/Unit/NoMysqlOnlySqlTest.php` (`php tools/sql-dialect-scan.php`) fails when MySQL-only SQL appears outside `Core\Dialect`. SQL that a site test writes
  (`Site::value/rows/exec`) is translated for PostgreSQL by `tests/Site/Support/TestSql.php`. Run the site suite on both: `TALEA_TEST_DB_DRIVER=pgsql
  vendor/bin/paratest --testsuite site`; `composer test:pg` runs the integration suite on PostgreSQL. Design, decisions and differences met: `docs/specs/postgres.md`.
- Settings: a new option = a key in `Settings::DEFAULTS` + a type in `Settings::FIELDS` + a row in `views/admin/settings/<tab>.php`.
- Extensions (`Core\Extensions::CATALOG`) are built-in features that an administrator switches on and off; `Module::EXTENSION` and
  `Element::EXTENSION` tie modules and builder elements to them (off: the module disappears, the element is not offered or rendered, the
  routes answer 404). **Add-ons** by other developers live in `extensions/<slug>/` and talk to the core only through
  `Talea\Extension\Api` (`docs/EXTENSIONS.md`, contract `tools/contracts/extension-api.json`); code is never uploaded from the admin
  or downloaded from the internet.
- Roles: administrator (2), editor (1: all content, publishes), news author (0: own news, cannot publish) – `Auth::canPublish()`,
  `Auth::managedAuthors()`, `Auth::articleScope()`; per-section permissions in `user_permissions`; custom roles (`Roles` module).

## The public site (`Front\Kernel`)

- `/` is the home page (setting `home_page`, in a language version its counterpart `translation_of`); the home page on its own address
  redirects 301 to `/`. News at `/news`, `/news/<slug>` (+ `.md`), `/news/category/<slug>`, `/news/tag/<slug>`. Pages at `/<slug>`;
  reserved addresses: `Modules\Pages::RESERVED_SLUGS`. `GET /health` is the orchestrator probe.
- **Themeless:** the page frame is `system/views/front/base.php` + `image/template.css`; the views cannot be overridden and PHP layouts
  are not used. The look comes from the design system, shared classes, components and site parts. `base.php` prints the head and foot
  blocks (SEO, structured data, measurement, cookie bar: `Front\Seo`). Take colours, fonts, scale and sizes from the design-system
  tokens (`--tl-color-*`, `--tl-step-*`, `--tl-space-*`, `--tl-width` …) with a default value of your own. Cascade layers of the
  site: `@layer tokens, shared, template, builder, classes, elements;` (`DesignSystem::LAYERS`): write into `template`, never unlayered,
  never `!important`.
- Dark mode: `<html data-dark>` by the setting `dark_mode`; CSS `@media (prefers-color-scheme: dark) { :root[data-dark] { … } }`.
- Shared interactive pieces (gallery, photo viewer, video, outline, share, FAQ, edit-in-place) have style and script in
  `image/web.css` / `image/web.js` (added by `Seo::head()`); rules are in `:where()` so that a template overrides them.
- **Right to left** (Arabic, `ar`): `Language::isRtl()/dir()/dirAttribute()` give `<html dir>` per page language; site CSS uses logical properties, rules they cannot say
  live in `Element::rtlCss()` (added by `Build::css` only on RTL pages); `image/web.js` follows `document.documentElement.dir`; the admin stays LTR (`bdi()` isolates typed
  values in lists, content fields get `dir`); split lines with `\R/u`, never byte-wise. `slugify()` transliterates Arabic; a title with nothing to transliterate gets `Slug::fromId` (`page-12`).
- Language versions: the column `language` ('' = default) on pages, categories and news; every public query filters by
  `Language::siteColumn()`; `App::url()` adds `/en/` only to addresses without an extension.
  **Regional versions** (`en-gb`, `de-at`, `pt-br`, `zh-hant`) are ordinary codes in `Language::AVAILABLE`, stored and addressed in lowercase
  (`/pt-br/`); the shape of a code is `Language::TAG` (use it in every regex, never `[a-z]{2}`). `Language::tag()` prints the canonical case
  (`pt-BR`) for `<html lang>`, `hreflang`, feeds and structured data; `Language::base()` is the language (`pt`): a regional version uses the
  dictionary, number format and German register of its base unless it has its own file. Imports fold a regional code into the base language the
  site already has (`Language::forSite()`, `Language::column()`); `Language::fromForeign()` maps locales and foreign tags (`pt_BR`, `zh-TW`).
- **Shared slugs** (setting `slugs_per_language`, off by default, Settings → General): a page, news item or category may share its address with the version in
  another language (`/contact`, `/de/contact`). Language columns are `VARCHAR(35)`; pages, news and categories have a per-language unique key
  `(language, slug)` next to the global one. `Core\Slug::switchPerLanguage` is the only writer of the setting (drops/restores the global keys; refuses to go
  off while an address is shared; undoes the keys it already put back when a later table fails). Every "slug taken?" check asks `Slug::taken()` / `Slug::scope()`;
  redirects of a version carry its prefix (`Slug::redirectPath`) while it is on.
- Page cache (`Front\Cache`): anonymous visitors only; every admin POST calls `Cache::clear()`; `Auth::user()` must not start a session
  for an anonymous visitor.
- The site address is the setting `site_url`, not the Host header; absolute addresses come from `$app->request->origin()`.
- Images: variants and WebP in `Core\Images::save()`, `srcset` in `Front\NewsRepository::prepare()`, dimensions in `Front\ImageHtml::complete()`.
  News lists do not load long texts (`Front\NewsRepository::LIST_COLUMNS`; a new column is added there too).
- Edit in place (`Kernel::editInPlace()`): "Edit here" for signed-in people with the right; saves go through `save_text` in `Modules\News` and `Modules\Pages`.

## Builder and design system

- **Design system** (`Builder\DesignSystem`, setting `design_system`): a few decisions produce the tokens in `@layer tokens`; fluid scales by
  `clamp()`, tints by `color-mix(in oklch)`, WCAG contrast computed in PHP (`contrasts()`). The live preview and presets are computed by
  PHP only (action `preview`): never duplicate the token maths in JS.
- **A build** is `pages.build` (published) and `build_draft` (editor, MCP): `{"v":1,"children":[{id,type,tag,content,style,classes,anchor,label,children}]}`.
  One element = one tag. **One validator** `Build::sanitize()` (editor, MCP, import: never store a build without it) and **one renderer**
  `Build::render()`; the page CSS comes only from used types, classes and element styles. A broken element is skipped on the web, never
  an exception.
- **Element** = a class in `Builder\Elements\` (extends `Element`, listed in `Build::ELEMENTS`): content fields (`properties()`),
  allowed tags, base CSS in layer `builder` through `:where()`. **Style** (`Builder\Style::PROPERTIES`) has the states
  `base`/`tablet` (≤1023 px)/`mobile` (≤767 px)/`hover`; values are tokens or safe free values. A class's own CSS goes through
  `Style::customCss()` (no `url()`, blocks or `@`).
- **Publishing** (`Builder\Publisher`, also from MCP): the previous version goes to `build_revisions` (20 per page or part), the content
  without layout (`Build::asText`) is stored in `text` for search, llms.txt, the API and the return to text. The draft preview is
  `?build=draft` (needs the Pages right), `&editor=1` adds `data-tl-id`. A signed preview link (`Core\Preview`, HMAC, one target,
  limited time) is returned by every write.
- **Editor** `image/builder.js` + `builder.css`: the canvas is the real page in an iframe, autosave of the draft, the section library
  `Builder\Library`, versions. Site parts (`Builder\SiteParts`: header, footer, wrappers), pop-ups (`Builder\Popups`), components
  (`Builder\Components`), collections (`Builder\Collections`) and the schema.org element (`Builder\StructuredData`, vocabulary in
  `system/data/schemaorg.json`, generated by `tools/schemaorg-vocabulary.php`) share the builder actions (`Admin\BuilderActions`).
  **Direct manipulation on the canvas** (`docs/specs/visual-compose.md`, phase A): `builder-overlay.js` (overlay layer inside the iframe,
  floating toolbar, move gesture, marquee, file drop), `builder-handles.js` (spacing, resize, column dividers), `builder-keys.js`; they talk to
  the editor only through `window.taleaBuilder`. Gestures are pointer events; they write the normal `Style` of the current breakpoint in one
  `applyChange` (one undo step), snap to tokens measured on the canvas (never recomputed in JS) and add no style property, so MCP is unchanged.
  **Compose section** (phase B): the Section content field `layout` = `stack` | `compose` (+ `stack_from`): a fixed 12-column grid, rows of
  `--tl-space-l` (`Elements\Section::composeCss`, only on pages that use it, `Context::$compose`). The direct children are placed by the style
  properties `grid_column_start/end` (lines 1–13), `grid_row_start/end` (1–40) and `layer` (below|base|above|top); `Build::sanitize` drops them
  (reported) anywhere else, so overlap exists only there. Tablet and phone stack the children in source order (CSS). Editor: `builder-compose.js`
  (column overlay, move grip and eight resize handles snapping to grid lines, Stack ⇄ Compose conversion, tidy up, layers).
- **No invented content** (#54): starter sections, elements' defaults and templates carry bracketed placeholders (`[Customer’s name]`, `[number]`, `[price]`), never realistic reviews, people, figures or prices. `Builder\SampleContent` finds them (and the demo texts of older starters) in a build, a text, news or a collection item; it warns in the MCP pre-publish `check`, the builder's check, the content check and `site_audit` (kind `sample`), and Claude's guide says to ask the owner. It never blocks publishing. New starter text: use a placeholder, not an invented value.
- **Looks gallery** (`Builder\Looks`, data in `system/looks/<key>.json`): 14 finished looks = design system (colours with a dark mode incl. optional
  `colors_dark.primary/secondary`, library fonts) + header/footer template (`PartTemplates`) + recommended library sections. Applied with
  `Looks::apply` (admin Appearance → Looks, MCP `list_looks`/`apply_look`, installer field `look`) as a draft look; the part drafts are marked in
  `look_draft.parts`, published/discarded with the look. Each file carries `source` and `"assets": "none-third-party"` (`tests/Unit/Builder/LooksTest.php`).
- **Look draft** (`Core\Look`): design system, class changes and menus go into one draft (`look_draft`), published with
  `Look::publish` (previous looks in `look_versions`, 20); drafts render only with `Look::activate()` (whole-site preview cookie or the
  builder for administrators).
- **Forms** (element `form`, `Front\Forms`, `POST /form`): fields and recipient are read from the PUBLISHED build by `source` + element id,
  never from the request; `Core\Antispam` (signed time, honeypot, per-IP limit) without cookies; the result is only a code in the URL.
- **HTML to build** (`Builder\HtmlConverter`): semantic HTML + a `<style>` with single-class rules become elements and classes.

## Content and services

- News (`Modules\News`): draft/published (also scheduled), trash for 30 days, revisions (20), broken-link check, AI assistant and
  translation. A changed address of a published item, page or category writes a redirect (`Redirects::add`). Search through
  `news.search_text` (`Core\Search`; whoever saves news elsewhere calls `Search::index()`).
- Release announcements (webhook, IndexNow) only through `Core\Notifications::process()`. Webhooks (`Core\Webhook`) are queued in
  `webhook_deliveries` and sent after the response; signature `X-Talea-Signature: sha256=HMAC(timestamp.body, webhook_secret)`.
- Mail always through `Core\Mail::send()` (queue). Newsletters (`Core\Mailing`): one template `views/email/newsletter.php`, SMTP and cron
  only, frozen at start, per-recipient unsubscribe with `List-Unsubscribe-Post`. Mailing services: `Core\Newsletter`.
- Stats without cookies (`Front\Stats`, `Core\Report`); web vitals histogram (`Core\WebVitals`, `image/vitals.js`).
- Audit (`Core\Audit`, MCP `site_audit`), company data (`Front\Company`, structured data from it in `Front\Seo`), site backups to FTPS/S3
  (`Core\RemoteBackup`), uploads (`Core\Images`, `Core\Files`).
- AI assistant (`Core\Assistant`): providers anthropic | openai | google | mistral; the key is a `secret` setting; model output is untrusted input.
- **In-admin AI flow** (#31, the owner's own key only): the first-run wizard (`Modules\Wizard`, `Core\SiteWizard`: questions → plan to review → blueprint + draft look + hidden draft pages) and the builder's Ask box (`BuilderActions::actionBuildAsk`, `Builder\AskBox`: a request becomes `Builder\Edits` operations on the draft). Nothing is sent before a click, nothing publishes, model output goes through `Build::sanitize` (no code), `Guardrails::assistantRefusal` applies, every run is one `AgentJournal` session (undo in Change log → Claude sessions) and a `change_log` row `assistant`/`ask|wizard`. Tests use `tools/fake/ai.php` (`TALEA_AI_URL`).
- **MCP** (`Mcp\Server`, `Mcp\Tools`, `/mcp`) is the main way sites are built by AI: whatever the editor can do must be possible there
  (pages and builder, classes, design system, media, settings, redirects, trash, news, collections …). The interface and the code are
  English; the recorded contract is `tools/contracts/mcp-tools.json` (`php tools/contracts.php`). Nothing over MCP may write outside
  content or run code or queries; PHP templates are never created or changed through MCP. A connection lists all tools or the **Essentials** set (`tool_set` on `api_tokens`/`oauth_codes`, chosen on the consent screen, kept by a refresh; personal tokens and existing connections default to all): the rest is reached through `find_tools` and three runners `run_read_tool` / `run_tool` / `run_destructive_tool` (`Mcp\Discovery::runner()` classifies every tool by `Catalog::access()`, `Catalog::SENDS` and `Catalog::OPEN_WORLD`; the inner call is a normal `tools/call`). Every admin action is in the `$parity` map of
  `tests/Unit/Mcp/ParityTest.php`: read-only, an MCP tool, or "admin: reason".
- **Member login** (`Core\Members`, extension `members`, off by default; public pages `Front\MemberArea` at `/member`; admin `Modules\Members`): visitor accounts
  with e-mailed one-time links only (token stored as sha256, single use, claimed atomically; the GET only shows a button), own session cookie `tl_member`
  (sha256 in `member_sessions`, never a PHP session, never created for anonymous visitors; the cookie bypasses `Front\Cache`). Pages, collection items and news are
  restricted to groups through `content_groups` (no row = public). **Every query that lists or exports content leaves gated rows out with
  `Members::notGated(type, idColumn)`** (lists, sitemap, llms.txt, feeds, search, newsletters, announcements); a gated page answers
  `private, no-store` + noindex and is never cached (`Kernel::gate()`). A group that still gates content cannot be deleted. Limits through `Antispam::tally`.
- Login: `password_hash`, TOTP, passkeys (`Core\Passkey`, only instead of the code for a TOTP account), password reset `Admin\PasswordReset`.

## Import and export

`Modules\Transfer` + `Core\WpFile` (streamed WXR), `Core\WpContent` (cleaning, Gutenberg blocks), `Core\WpImport`, `Core\ImageDownloader`;
`Core\SiteExport` writes an open archive (`content.json`), `Core\SiteImport` reads it into an EMPTY site (rows keep their public ids, every
row goes through the sanitizers, settings only from the allow-list `SiteExport::SETTINGS`). Never relax: XML with DOCTYPE/entities is
refused, content passes an allow-list of tags, the image downloader only fetches public addresses of the old site with pinned connections
and size/time limits, never SVG.

## Running and testing

- Development: `docker compose -f docker-compose-dev.yaml up -d` (PHP + Xdebug, MySQL 8.4, Mailpit, Adminer, a throw-away test database;
  see `docker/README.md`), or `php -S 127.0.0.1:8095 system/dev-router.php` with a local MySQL; `config.php` is not in git.
- **Tests: `composer test`** (PHPUnit with ParaTest): unit tests, integration tests against MySQL 8, and the site tests that install a whole
  site per test class (`tests/Site/Support/Site`) and drive it over HTTP; `composer test:browser` walks the admin and the builder in Chrome
  (needs Node and Chrome); `composer test:unit` is the unit suite alone (no database, seconds). Needs a MySQL (`TALEA_TEST_DB_*`; the dev stack's
  `db-test` service); every class creates and drops its own database.
- `php tools/check-english.php` (English everywhere), `php tools/contracts.php` (MCP, design tokens and element contracts),
  `tools/screenshots.sh` (screenshots for docs after admin changes), Lighthouse `tools/test-lighthouse.sh`.
- Releases: `docs/RELEASING.md` (container image, signed feed), `docs/DEPLOYMENT.md` (update, rollback, health, replicas).
  The in-app updater is switched off (`Core\Updater::ENABLED`); `Core\UpdateFeed` only shows a "new version" notice.

<!-- claude-reflect: learned rules (saved from the band; edit freely) -->
## Learned rules
- Run CI tests against a single database engine instead of both MariaDB and MySQL, since they are considered equivalent.
<!-- end claude-reflect -->
