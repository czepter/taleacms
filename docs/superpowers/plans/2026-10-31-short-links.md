# Short Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Printable short addresses (`/flyer`, `/s/k7f2qm`) that 302 to a page, news item, collection item or URL with hidden UTM
parameters, retargetable at any time, with hit counter and QR code, in the admin and over MCP.

**Architecture:** One table `tl_short_links` and one class `Core\ShortLinks` that owns validation, code generation, collision checks,
target resolution and UTM merging. The front kernel resolves short links through two routes placed before add-on routes; the admin
module `Modules\ShortLinks` and the MCP trait `Mcp\Handlers\ShortLinkTools` both call `ShortLinks::save()`/`delete()`.

**Tech Stack:** PHP 8.5, Phinx, PHPUnit/ParaTest site tests, vanilla JS in `image/admin.js`.

**Spec:** `docs/superpowers/specs/2026-10-31-short-links-design.md` (read it first; the plan does not repeat its behaviour rules).

## Global Constraints

- Everything English (code, comments, tests, docs); UI text through `t('English')`, translations for cs, de (+ `-du` if the key needs
  informal), es, fr, it, pl, sk via `tools/add-translations.py`.
- Portable SQL (MySQL 8, PostgreSQL 18, SQLite 3.40): booleans `= TRUE`, no `INSERT IGNORE`, `ON DUPLICATE`, `IF()`; use `Db::upsert`.
- Schema only through a Phinx migration with the table DSL, no `COLLATE`/`CHARSET`; `public_id` via `MigrationSupport::publicId()`.
- Admin CSP `script-src 'self'`: no inline scripts, no `on*=`; `data-confirm` for deletes; behaviour in `image/admin.js` via `data-`.
- URL query parameters English (`target`, `edit`, `new`, `search`, `page`).
- Admin and MCP use public ids, never row ids.
- Random code: `s/` + 6 chars from `23456789abcdefghjkmnpqrstuvwxyz`. Name: `^[a-z0-9][a-z0-9-]{1,59}$`. UTM values ≤ 80 chars.
- Short-link answer: 302, `Cache-Control: no-store`, `X-Robots-Tag: noindex`; GET/HEAD only.
- PHP runs only in Docker. Commands below use:
  `RUN='docker run --rm --entrypoint sh --network talea-dev_default -e TALEA_TEST_DB_HOST=db-test -e TALEA_TEST_DB_PORT=3306 -v /Users/christian/www/kaletacms:/Users/christian/www/kaletacms -w /Users/christian/www/kaletacms/.claude/worktrees/git-history-cleanup-comments-50abaa talea-ci-local -c'`
  then `$RUN 'git config --global --add safe.directory "*"; vendor/bin/phpunit …'`. Site suites: `--processes 2` at most (3 GiB VM).
- Commit after every task (no push). Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A page created **after** a named short link with the same slug (admin, MCP `create_page`, import) → page gets `flyer-2`, link keeps
   answering. Test in Task 2.
2. `/Flyer` typed from paper → same 302 as `/flyer`. Test in Task 2.
3. A URL target that already has `?utm_source=x&a=1` plus stored `utm_source=flyer` → `a=1` kept, `utm_source=flyer`. Unit test in Task 1.
4. Renaming the target page's slug → the short link follows (no stored path). Test in Task 2.
5. A drafts-only MCP connection calling `save_short_link` → refused, nothing stored. Test in Task 3.

---

### Task 1: Table, extension and `Core\ShortLinks`

**Files:**
- Create: `system/database/migrations/20261111100047_create_short_links.php`
- Create: `system/src/Core/ShortLinks.php`
- Modify: `system/src/Core/Db.php:29` (add `'short_links'` to `PUBLIC_ID_TABLES`)
- Modify: `system/src/Core/Extensions.php` (catalog entry `'short_links' => ['Short links', 'Short addresses and QR codes for print, with campaign parameters that stay off the paper. A short link can be pointed at new content any time.', true]`)
- Test: `tests/Unit/Core/ShortLinksTest.php`, `tests/Integration/ShortLinksTest.php` (follow the setup of an existing `tests/Integration/*Test.php`)

**Interfaces (Produces):**
- Constants: `ShortLinks::ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz'`, `ShortLinks::UTM = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content']`, `ShortLinks::TYPES = ['page','news','item','url']`.
- `public static function randomCode(): string` — `s/` + 6 chars (uses `random_int`).
- `public static function nameError(Db $db, string $name): ?string` — null when free; refusal text otherwise (not English-translated here: returns the English key, callers wrap in `t()`). Refuses: bad shape, `Kernel::reservedSlugs()`, `isset(Language::AVAILABLE[$name])`, `Routes::isNewsSlug`, an add-on prefix (`\Talea\Extension\Registry` — the builder finds the accessor for registered route prefixes; add one if missing), any page slug (`SELECT 1 FROM {pages} WHERE slug = ?`, trash included), any collection slug, an active exact redirect `from_path`, an existing short link.
- `public static function takesName(Db $db, string $slug): bool` — true when a short link has `path = $slug` (cheap; used by page/collection/news-slug checks; ignores the extension switch on purpose).
- `public static function merge(string $url, array $utm): string` — appends non-empty UTM fields; stored field overrides the same key already in the query; keeps fragment.
- `public static function targetUrl(App $app, array $row): ?string` — absolute or site-relative URL the visitor goes to, **null** when the content target is unreachable (trashed, hidden page/news, item hidden without `hidden_redirect`, collection `detail` off, row gone). Gated content and hidden items with `hidden_redirect` return their own address. Page: home page → `''`; language prefix of the page's language; news through `Routes::publicPath('news/<slug>')`; item `<collection slug>/<item slug>` with language prefix. URL type: `https://…` as is, else the site path.
- `public static function save(App $app, array $input, int $id = 0): array|string` — validates and stores; returns the stored row (as `one()` returns it) or the English refusal. `$input` keys: `name` (optional, only on create; `''`/absent = random), `target_type`, `target_id` (int row id, resolved by caller from public id), `target_url`, the five UTM keys, `label`. On update the path is never changed. Validates: type in `TYPES`; content row exists and (for items) its collection has `detail` on; URL is `https?://` (≤ 2000) or a site path without scheme/whitespace; UTM trimmed, control chars removed, ≤ 80. Writes `change_log` via `\Talea\Admin\ChangeLog::write($app, 'short_links', 'save', '<path> → <target>')`.
- `public static function delete(App $app, int $id): void`.
- `public static function qrSvg(App $app, string $path): string` — `Qr::svg($origin . $app->url($path), …)` as `HoursSign` uses it.

- [ ] **Step 1: Write unit tests** (`tests/Unit/Core/ShortLinksTest.php`, no database)

```php
public function testRandomCodeShape(): void {
    for ($i = 0; $i < 200; $i++) { $this->assertMatchesRegularExpression('#^s/[23456789abcdefghjkmnpqrstuvwxyz]{6}$#', ShortLinks::randomCode()); }
}
public function testMergeAppendsAndOverrides(): void {
    $utm = ['utm_source' => 'flyer', 'utm_medium' => 'print', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => ''];
    $this->assertSame('/offers?utm_source=flyer&utm_medium=print', ShortLinks::merge('/offers', $utm));
    $this->assertSame('https://x.test/p?a=1&utm_source=flyer&utm_medium=print#top', ShortLinks::merge('https://x.test/p?utm_source=old&a=1#top', $utm));
    $this->assertSame('/offers', ShortLinks::merge('/offers', array_fill_keys(ShortLinks::UTM, '')));
}
```

- [ ] **Step 2: Write integration tests** (`tests/Integration/ShortLinksTest.php`): `nameError` refuses `s`, `news`, `de`, `pt-br`, an existing page slug, a collection slug, `Ab`, `a`, `-x`, and accepts `flyer`; `save()` with no name stores `s/xxxxxx`; `save()` on update ignores a new `name`; `save()` refuses `target_type=page` with a missing id and `target_url=javascript:alert(1)`; `targetUrl()` returns null for a trashed page and `''` for the home page.
- [ ] **Step 3: Run, expect FAIL** — `$RUN '… vendor/bin/phpunit --filter ShortLinksTest'` → class not found.
- [ ] **Step 4: Migration** — table per spec "Data model"; `path` string(70) unique index `uq_short_links_path`; `target_url` string(2000) null; `target_id` integer unsigned null; utm/label strings with default `''`; `hits` integer unsigned default 0; `last_hit_at` datetime null; `created_at` datetime. Lookup column `path` gets the case-insensitive treatment other lookup columns get (see `20261009120008_ignore_case_in_lookup_columns.php` / `MigrationSupport`).
- [ ] **Step 5: Implement `Core\ShortLinks`** per the interface block; the extension entry; `PUBLIC_ID_TABLES`.
- [ ] **Step 6: Run, expect PASS** — unit + integration filter; then `$RUN 'php tools/sql-dialect-scan.php'` → 0 lines.
- [ ] **Step 7: Commit** — `feat: add short links table and core`.

### Task 2: Front routing and slug protection

**Files:**
- Modify: `system/src/Front/Kernel.php` (`routes()`: two routes immediately **before** the `addOnRoute` line)
- Modify: `system/src/Front/Endpoints.php` (handler)
- Modify: `system/src/Core/Slug.php` (`taken()`: for `$table === 'pages'` also `ShortLinks::takesName($db, $slug)`)
- Modify: collection slug checks — `system/src/Admin/Modules/Collections.php:115`, `system/src/Mcp/Tools.php:970`, `system/src/Builder/Presets.php:148`, `system/src/Core/WpImport.php:651` — add `|| ShortLinks::takesName($db, $seo)`
- Modify: `system/src/Core/Routes.php` `slugError()` — refuse a news slug that `takesName`
- Modify: `tools/contracts/routes.json` (regenerate with `php tools/contracts.php`)
- Test: `tests/Site/LinksConnectors/ShortLinksTest.php` (new site test class, `use FakeServices;` like `RedirectsScreenTest`)

**Interfaces:**
- Consumes: `ShortLinks::targetUrl`, `ShortLinks::merge`, `ShortLinks::takesName`, `ShortLinks::save` (to seed rows in the test via MCP later; in this task seed with `Site::exec` inserts).
- Produces: routes `->add(['GET','HEAD'], '/s/{code:[a-z0-9]{6}}', [Endpoints::class, 'shortLink'], $extension('short_links'), 'extension:short_links')` and `->add(['GET','HEAD'], '/{name:[A-Za-z0-9][A-Za-z0-9-]{1,59}}', [Endpoints::class, 'shortLink'], $extension('short_links'), 'extension:short_links')`. `Endpoints::shortLink(string $code = '', string $name = ''): ?Response` — lower-cases, looks up `path` (`s/<code>` or `<name>`), returns null when none (next route tries), only when `$app->languagePrefix === ''`; counts `hits`/`last_hit_at`; `targetUrl()` null → home page; answers per Global Constraints. Check how `Router::add` takes methods and the condition label (match the existing calls); if `['GET','HEAD']` is not supported, use `'ANY'` and return null for other methods.
- Extension off: `/s/…` must 404 — the condition false lets the request fall to `pageAt`/404, which is correct.

- [ ] **Step 1: Site test** (`testNamedAndRandomFollowWithUtm`): insert a short link `flyer` → page `about` with `utm_source=flyer`, `utm_medium=print`, and `s/k7f2qm` → URL `https://example.org/x`; `client('visitor')->get('/flyer')` → `[302, base . '/about?utm_source=flyer&utm_medium=print']`, header `cache-control` contains `no-store`; `/FLYER` same; `/s/k7f2qm` → `https://example.org/x`; `hits` = 3 total. POST `/flyer` → not 302.
- [ ] **Step 2: Site test** (`testFollowsRenameAndFallsBackHome`): rename page slug via admin save (or MCP `update_page` with `slug`) → `/flyer` now redirects to the new slug; trash the page → `/flyer` 302 to `base . '/'`.
- [ ] **Step 3: Site test** (`testAddressesStayReserved`): MCP `create_page` with slug `flyer` → stored slug `flyer-2`; `/flyer` still 302; with extension `short_links` off (`update_settings` extensions list without it) `/s/k7f2qm` → 404 and `create_page` slug `flyer` still → `flyer-2`; news slug setting `flyer` refused.
- [ ] **Step 4: Run, expect FAIL** — `$RUN '… vendor/bin/phpunit --testsuite site --filter ShortLinksTest'`.
- [ ] **Step 5: Implement** routes, handler, slug checks; regenerate contracts (`$RUN 'php tools/contracts.php'`), review the diff of `tools/contracts/routes.json`.
- [ ] **Step 6: Run, expect PASS** — same filter; also `--filter PublicSiteTest` (routing regressions) and `vendor/bin/phpunit --testsuite unit`.
- [ ] **Step 7: Commit** — `feat: resolve short links on the public site`.

### Task 3: MCP tools

**Files:**
- Create: `system/src/Mcp/Handlers/ShortLinkTools.php` (trait; add to the `use Handlers\…` list in `system/src/Mcp/Tools.php:29`)
- Modify: `system/src/Mcp/Catalog.php` (`'list_short_links' => ['read', 'short_links']`, `'save_short_link' => ['write', 'short_links']`, `'delete_short_link' => ['destructive', 'short_links']`)
- Modify: `system/src/Mcp/Tools.php` `definitions()` (three definitions next to `save_redirect`)
- Modify: `system/src/Mcp/PublicIds.php` (`save_short_link` argument `target.id` by `target.type` → pages/news/collection_items; results `short_links.*.id`, `short_links.*.target.id`) — follow the `BY_KIND` pattern
- Modify: `tools/contracts/mcp-tools.json` (regenerate)
- Test: extend `tests/Site/LinksConnectors/ShortLinksTest.php`

**Interfaces:**
- Consumes: `ShortLinks::save/delete/targetUrl/qrSvg`.
- Produces (tool shapes):
  - `list_short_links {}` → `{short_links: [{id, url, path, label, target: {type, id}|{type:"url", url}, utm: {source, medium, campaign, term, content}, hits, last_hit_at, target_public: bool}]}`.
  - `save_short_link {id?, name?, target: {type: page|news|item, id} | {type: "url", url}, utm?: {source?, medium?, campaign?, term?, content?}, label?}` → the listed shape of that link plus `qr_svg`. Update keeps omitted fields.
  - `delete_short_link {id}` → `{deleted: true}`.
  - Permission: `$auth->hasModule('short_links')`; save/delete refused when `$auth->draftsOnly()` with "A connection limited to drafts cannot change short links: a change is live at once."
  - Refusals from `ShortLinks::save` become `\InvalidArgumentException`.

- [ ] **Step 1: Site test** (`testMcpCreateRetargetDelete`): `save_short_link` with `name: 'spring'`, page target, utm source/medium → result `path === 'spring'`, `qr_svg` starts with `<svg`; visitor `/spring` → page URL with UTM; `save_short_link {id, target: {type:'url', url:'https://example.org/new'}}` → `/spring` → `https://example.org/new?utm_source=…`; `list_short_links` contains it with `target.type === 'url'`; `delete_short_link` → `/spring` no longer 302.
- [ ] **Step 2: Site test** (`testDraftsOnlyCannotChange`): a drafts-only token (see how other site tests create one, e.g. grep `drafts` in `tests/Site`) → `save_short_link` errors, no row added; `list_short_links` works.
- [ ] **Step 3: Run, expect FAIL.**
- [ ] **Step 4: Implement** trait, catalog, definitions, public-id mapping; regenerate contracts; `vendor/bin/phpunit --testsuite unit --filter 'Catalog|Contract'` green.
- [ ] **Step 5: Run, expect PASS** (site filter + unit suite).
- [ ] **Step 6: Commit** — `feat: manage short links over MCP`.

### Task 4: Admin module, entry links, QR download, translations

**Files:**
- Create: `system/src/Admin/Modules/ShortLinks.php` (`IDENT='short_links'`, `TABLE='short_links'`, `NAME='Short links'`, `GROUP='Search engines'`, `EXTENSION='short_links'`, not `ADMIN_ONLY`; register next to `Modules\Redirects::class` in `system/src/Admin/Kernel.php:105`)
- Create: `system/views/admin/short_links/list.php`, `system/views/admin/short_links/edit.php` (model on `views/admin/redirects/*`)
- Modify: page, news and collection-item edit views — a "Short link" link to `admin.php?module=short_links&new=1&target=page:<public_id>` (`news:`, `item:`) plus the count of existing links for that content (find the edit views under `system/views/admin/pages`, `news`, `collections`)
- Modify: `image/admin.js` (copy-to-clipboard for `[data-copy]` if no such behaviour exists yet — grep first; the target-type switch in the form, if needed, via `data-` attributes)
- Modify: `tests/Unit/Mcp/ParityTest.php` (`'short_links' => ['list' => $readOnly, 'save' => 'save_short_link', 'delete' => 'delete_short_link', 'qr' => $readOnly]`)
- Modify: `system/languages/admin-*.php`, `image/languages/admin-*.js` via `tools/add-translations.py`
- Test: extend `tests/Site/LinksConnectors/ShortLinksTest.php`

**Interfaces:**
- Consumes: `ShortLinks::save/delete/targetUrl/qrSvg/randomCode`.
- Actions: `list` (list or form with `new`/`edit=<public_id>`/`target=<type>:<public_id>`), `save` (POST; fields `short_link_id`, `kind` = `random|name`, `name`, `target_type`, `target_public_id`, `target_url`, `utm_source…utm_content`, `label`), `delete` (POST, `data-confirm`), `qr` (GET `id=<public_id>` → `image/svg+xml`, `Content-Disposition: attachment; filename="qr-<path with / as ->.svg"`).
- Content picker: grouped `<select name="target_public_id">` (optgroups Pages / News / per collection with `detail` on), non-trashed rows, ordered by title, plus type `url` with a text field — the builder may reuse an existing admin content picker if one fits.
- List columns per spec "Admin"; badge "Target not public" when `targetUrl()` is null.

- [ ] **Step 1: Site test** (`testAdminScreen`): POST save with `kind=name`, `name=poster`, page target, utm source → 302 back, row stored; list page contains `/poster` and the hit count; `qr` returns 200 `image/svg+xml`; POST save with `kind=name`, `name=news` → refusal text shown, no row; edit retargets to a URL; page edit screen contains `module=short_links&amp;new=1&amp;target=page:`; delete removes the row.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** module, views, entry links, JS, parity entries.
- [ ] **Step 4: Translations** — collect the new `t()` keys, add all languages with `tools/add-translations.py`; `$RUN 'git add -A; php tools/check-english.php'` exits 0.
- [ ] **Step 5: Run, expect PASS** — site filter, `vendor/bin/phpunit --testsuite unit` (ParityTest, ScriptsTest).
- [ ] **Step 6: Commit** — `feat: short links admin screen with QR codes`.

### Task 5: Export/import, audit, docs, full verification

**Files:**
- Modify: `system/src/Core/SiteExport.php` (export `short_links`, content target as `{type, public_id}`; readme text lines ~409–419)
- Modify: `system/src/Core/SiteImport.php` (`TABLES`, map public ids back, every row through `ShortLinks::save`-equivalent validation; keep `public_id` and `path`)
- Modify: `system/src/Core/Audit.php` (finding kind `short_link` for each link whose `targetUrl()` is null, linking to the edit form)
- Modify: `CLAUDE.md` (one line under "Content and services": short links = `Core\ShortLinks`, routes before add-ons, 302 + no-store, names reserved in `Slug::taken` and collection checks)
- Modify: `system/src/Mcp/Prompts.php` only if a runbook lists redirect tools where short links belong (print campaigns) — optional, skip if none fits
- Test: extend the export/import integration or site test that covers redirects (grep `redirects` in `tests/` for the round-trip test) and `ShortLinksTest` for the audit finding

- [ ] **Step 1: Tests** — export→import round trip keeps a page-target short link working on the new site; `site_audit` lists a `short_link` finding after the target page is trashed.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Full verification** — `$RUN '… vendor/bin/phpunit --testsuite unit'`; `vendor/bin/paratest --testsuite integration --processes 2`; site suite filter `ShortLinks|Redirects|PublicSite|Export|Import` on MySQL, then with `TALEA_TEST_DB_DRIVER=pgsql` (`TALEA_TEST_PG_HOST=db-test-pg`) and `TALEA_TEST_DB_DRIVER=sqlite`; `php tools/sql-dialect-scan.php` 0; `php tools/contracts.php` no diff; `php tools/check-english.php` 0.
- [ ] **Step 5: Commit** — `feat: export, import and audit short links`.
