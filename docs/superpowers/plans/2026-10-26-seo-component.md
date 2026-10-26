# Search Engines Section Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Search engines" section in the admin: a focus keyword and a 0–100 score per page, news item and collection item; robots, canonical and sharing fields; title and description templates; redirects with match types; a log of missing pages; a site check with a score; and the Search Console / Bing Webmaster Tools data next to each page.

**Architecture:** This builds on what Talea already has instead of replacing it. `Core\ContentCheck` gains a scored analysis. `Front\Seo` and `views/front/base.php` print the new head tags. `Core\Audit` gets a score. `Core\SearchData` (the daily `search_data` job, `tl_search_stats`) stores three more breakdowns, and a new pure class `Core\SearchInsights` reads them. Each screen is a small admin module in a new sidebar section, `Search engines`, so every canvas menu item is one module (`Admin\Kernel::MODULES` and `SECTIONS`).

**Tech Stack:** PHP 8.5, Phinx migrations, MySQL 8 / PostgreSQL 18 (portable SQL), server-rendered views, vanilla JS in `image/admin.js`, PHPUnit / ParaTest (unit and site suites), MCP contract files.

**Spec:** the design canvas "Talea SEO component" (`https://claude.ai/artifact/Cr6DREhYSy9GS9xyUi6iqm`), boards Main, List, Analyzer, Titles, Redirects, NotFound and Performance. For Website mode the spec is the canvas "Builder frame with SEO sidebar" (`https://claude.ai/artifact/Xn7Q15SN3uEJkgTyr4hTqx`, board Main), which replaces the first canvas's Editor board. The decisions below apply to both. Where this plan and a board differ, the plan wins; each difference is listed under Decisions.

## Decisions (differences from the canvas, and calls made here)

- The "title has a tone" and "title uses a strong word" checks are left out. They need word lists in every language, and keeping those up to date costs more than the checks are worth. The Title group keeps "keyword at the start" and "contains a number".
- The overview shows only features that exist. Each switch writes the setting or extension that already exists (`schema_org`, `indexnow`, `llms_txt`, extension `redirects`) or the new extension `page_check`. Image descriptions, Breadcrumbs, News sitemap and Video sitemap are separate plans, so they get no card yet.
- The Redirects module is an extension today, so its card gets a switch, not "Always on".
- Site check is the existing `Audit` module, moved into the new section. Its menu name stays "Site audit" (docs, Guide anchors and the MCP tool `site_audit` use it). The score is computed per finding kind. "Check another address" is left out.
- Structured data tab: lists the types on the page (Web page, which is always added, plus each `structured_data` element in the build). "Add a type" appends a `structured_data` element to the draft. A default type for each kind of content is left out.
- Missing pages tabs: "To decide" and "Ignored". "Already redirected" is a tile counted from `tl_redirects`. Rows already drop out of `tl_not_found` once they resolve (`NotFound::pending`).
- Page × search data (the searches for one page) comes from Google only: Bing has no bulk call for it. The card says which service its numbers come from.
- Website mode: the right sidebar is where an existing page's settings live. Its five tabs are SEO, Page, Publishing, Sharing and Advanced. `pages/edit` for an existing page leads there; the full form stays only for creating a page. "Start from a template" stays in `pages/new`, and "Return page to text" stays in the builder: both change the whole page, not its settings.
- "SEO on the page" marks problems in the preview through the build, so it works for builder pages only. Text pages show the checks without marks.
- "Not in search yet" is shown only while the latest Google snapshot has fewer pages than `SearchData::ROW_LIMIT`. On a bigger site the absence of a row proves nothing.

## Global Constraints

- PHP, Composer and PHPUnit run only in the dev container. Shorthand (dev stack up, from the worktree root):
  `dc() { colima ssh -- sh -c "cd '$PWD' && docker compose -f docker-compose-dev.yaml exec -T web $*"; }`
  The site suite needs the `talea-ci-local` image and `--processes 2` (memory: reference-local-site-tests).
- English everywhere. UI text only through `t('English')`. Each new string needs a cs and a de entry (`tools/add-translations.py`), and German uses "AI", never "KI". Visible text never names a third-party product, except the connected services Google Search Console and Bing Webmaster Tools, where the source of the data has to be named.
- Portable SQL only (CLAUDE.md HF-14). `dc php tools/sql-dialect-scan.php` must report 0 lines.
- Schema changes go through Phinx migrations in `system/database/migrations/` (table DSL, no COLLATE or CHARSET).
- Rows are addressed from outside by `public_id`. MCP arguments and admin URLs never carry row numbers.
- Admin CSP is `script-src 'self'`: no inline scripts, no `on*=` attributes, confirmations only through `data-confirm`.
- A new MCP tool or parameter: run `dc php tools/contracts.php --update` and add every new admin action to `$parity` in `tests/Unit/Mcp/ParityTest.php`.
- A new setting means a key in `Settings::DEFAULTS`, a type in `Admin\Modules\Settings::FIELDS` and a row in its view. MCP may write it only if it is added to `Mcp\Tools::MCP_SETTINGS`.
- What nothing emits has no style: CSS goes into `image/admin.css` in the same commit as the first markup that uses it.
- Before each commit: `dc php tools/check-english.php` exits 0. Before each PR: `dc composer test`, and the site suite on PostgreSQL for PRs that touch SQL (`TALEA_TEST_DB_DRIVER=pgsql`).
- Stacked PRs (base = parent branch): PR 1 = Tasks 1–3, PR 2 = Tasks 4–8, PR 3 = Tasks 9–11, PR 4 = Tasks 12–14, PR 5 = Task 15, PR 6 = Tasks 16–19. PR 6 depends only on PR 3 (section and list columns) and can stack on it directly.

## Review Focus

- **A regex redirect that never ends or redirects to itself:** a pattern that does not compile, is longer than 255 characters, or matches its own target is refused on save with a message. A pattern that hits PCRE's backtrack limit at request time is skipped and the site answers 404, never 500. Tests are in Task 12.
- **A focus keyword with diacritics, other capitalisation, Arabic or a hyphen:** found the same way `ContentCheck::contains` finds it. Words are counted with `\p{L}`, never byte by byte. Tests are in Task 4.
- **A page with no text (a build of images only, or an empty draft):** score computed with no division by zero, density 0, word count 0, a "has images" pass. Tests are in Task 4.
- **Search data that is missing, stale or half there:** nothing connected, connected but no snapshot yet, one engine failing, a snapshot older than 3 days. Each shows its own empty state or the date of its data, never an error page. Tests are in Tasks 17 and 18.
- **Robots and canonical values from MCP or a crafted POST:** directives outside the allow-list are dropped. The canonical must be an absolute http(s) URL or empty. A noindex page prints no canonical and stays out of the sitemap (as today). Tests are in Tasks 1 and 3.

---

## PR 1 — Fields and head output

### Task 1: SEO columns and robots directives

**Files:**
- Create: `system/database/migrations/20261026100000_add_seo_fields.php`
- Create: `system/src/Core/Robots.php`
- Test: `tests/Unit/RobotsTest.php`, `tests/Integration/MigratorTest.php` (existing, must stay green)

**Interfaces:**
- Produces: columns on `pages`, `news` and `collection_items`:
  - `focus_keywords` VARCHAR(500) NOT NULL DEFAULT '': up to 5 keywords, one per line, the first is scored
  - `key_page` BOOLEAN NOT NULL DEFAULT FALSE
  - `robots` VARCHAR(200) NOT NULL DEFAULT '': normalised directives, see below
  - `canonical_url` VARCHAR(500) NOT NULL DEFAULT ''
  - `breadcrumb_title` VARCHAR(200) NOT NULL DEFAULT ''
  - `share_title` VARCHAR(200) NOT NULL DEFAULT ''
  - `share_description` VARCHAR(320) NOT NULL DEFAULT ''
  - `seo_score` TINYINT UNSIGNED NULL: NULL = no focus keyword
  - `seo_checked_at` DATETIME NULL
- Produces: `final class Core\Robots`:
  - `const array FLAGS = ['nofollow', 'noarchive', 'noimageindex', 'nosnippet']`
  - `const array IMAGE_PREVIEW = ['large', 'standard', 'none']`
  - `static normalize(string|array $input): string`: canonical order `nofollow,noarchive,noimageindex,nosnippet,max-snippet:N,max-video-preview:N,max-image-preview:X`. N is an integer ≥ −1, unknown or duplicate tokens are dropped, and an absent max-* means no limit.
  - `static meta(bool $noindex, string $pageRobots, string $siteRobots): string`: the content of `<meta name="robots">`, or '' when nothing differs from the default. It joins `noindex` (or `index` when only max-* are set), then the union of the page and site flags, then the page's max-* values, falling back to the site's.
  - `static canonical(string $url): string`: the URL if it is an absolute http(s) URL of at most 500 characters, otherwise ''.

- [ ] **Step 1: Write the failing test.** In `RobotsTest`:
  - `testNormalizeDropsUnknownAndOrders`: `normalize('nosnippet, NOFOLLOW,evil,max-snippet:-1,max-image-preview:huge')` → `'nofollow,nosnippet,max-snippet:-1'`.
  - `testNormalizeAcceptsList`: `normalize(['noarchive', 'max-image-preview:large'])` → `'noarchive,max-image-preview:large'`.
  - `testMetaMergesSiteAndPage`: `meta(false, 'nofollow', '')` → `'index, nofollow'`; `meta(true, '', 'noarchive')` → `'noindex, noarchive'`; `meta(false, '', '')` → `''`; `meta(false, 'max-snippet:50', 'max-snippet:-1,noarchive')` → `'index, noarchive, max-snippet:50'`.
  - `testCanonical`: `canonical('https://example.com/a')` returns it unchanged; `canonical('javascript:alert(1)')`, `canonical('/relative')` and a 501-character URL each → `''`.
- [ ] **Step 2: Run, expect FAIL.** `dc vendor/bin/phpunit tests/Unit/RobotsTest.php` fails with class not found.
- [ ] **Step 3: Implement** `Core\Robots` and the migration (one `change()` loops over the three tables with `->addColumn(...)->update()`; column comments as above).
- [ ] **Step 4: Run, expect PASS.** `dc vendor/bin/phpunit tests/Unit/RobotsTest.php tests/Integration/MigratorTest.php`, then `dc bin/migrate` on the dev database.
- [ ] **Step 5: Commit.** `feat(seo): SEO columns and robots directives`

### Task 2: Title templates and head tags

**Files:**
- Create: `system/src/Core/SeoTitle.php`
- Modify: `system/src/Core/Settings.php` (DEFAULTS), `system/src/Admin/Modules/Settings.php` (FIELDS), `system/src/Mcp/Tools.php` (MCP_SETTINGS)
- Modify: `system/views/front/base.php:40-52`, `system/src/Front/Seo.php::head()`, `system/src/Front/Kernel.php` (`$meta` for pages, news, items, categories, tags, home)
- Test: `tests/Unit/SeoTitleTest.php`, `tests/Site/PagesNews/SeoHeadTest.php`

**Interfaces:**
- Consumes: `Robots::meta`, `Robots::canonical` (Task 1).
- Produces: settings
  - `seo_separator` '–'
  - `seo_title_home` '%sitename% %sep% %tagline%'
  - `seo_title_page` '%title% %sep% %sitename%'
  - `seo_title_news` '%title% %sep% %sitename%'
  - `seo_title_item` '%title% %sep% %sitename%'
  - `seo_title_category` '%title% %sep% %sitename%'
  - `seo_title_tag` '%title% %sep% %sitename%'
  - `seo_description_page` ''
  - `seo_description_news` ''
  - `seo_description_item` '' (each '' means the current fallback: the intro, then the first text field)
  - `seo_capitalize` '0'
  - `seo_robots` '' (normalised by `Robots::normalize`)
  - `seo_hide_empty_terms` '1'
  - `seo_card` 'large' (large | small)
- Produces: `final class Core\SeoTitle`:
  - `const array VARIABLES = ['title', 'sep', 'sitename', 'tagline', 'category', 'page', 'year', 'excerpt']`
  - `static render(string $template, array $vars, bool $capitalize = false): string`: replaces `%name%` from `$vars`. An unknown or empty variable becomes '', separators left at the start or end or doubled are trimmed, and the result is cut to 200 characters. If the result is '', it returns `$vars['title'] ?? ''`.
- Produces: `$meta['seo_kind']` (home | page | news | item | category | tag | other), `$meta['title_vars']` (array), `$meta['robots']` (page robots string), `$meta['canonical']` (validated override), `$meta['share_title']`, `$meta['share_description']`.

- [ ] **Step 1: Write the failing tests.**
  - `SeoTitleTest::testRenders`: `render('%title% %sep% %sitename%', ['title' => 'About', 'sep' => '–', 'sitename' => 'Studio'])` → `'About – Studio'`.
  - `testTrimsDanglingSeparator`: `render('%title% %sep% %tagline%', ['title' => 'Home', 'sep' => '|', 'tagline' => ''])` → `'Home'`.
  - `testEmptyFallsBackToTitle`: `render('%category%', ['title' => 'X'])` → `'X'`.
  - `testCapitalize`: `render('%title%', ['title' => 'wedding photos in jena'], true)` → `'Wedding Photos In Jena'` (uses `mb_convert_case(MB_CASE_TITLE)`).
  - `SeoHeadTest` (site): a page with `seo_title` '', `robots` 'nofollow', `canonical_url` 'https://example.org/x' and `share_title` 'Share me', and the setting `seo_separator` '|'. The HTML contains `<title>About | My website</title>`, `<meta name="robots" content="index, nofollow">`, `<link rel="canonical" href="https://example.org/x">` and `<meta property="og:title" content="Share me">`. The same page with `noindex` 1 has no canonical and is absent from `/sitemap.xml`. With `seo_card` 'small' it gets `twitter:card` `summary`.
- [ ] **Step 2: Run, expect FAIL.** `dc vendor/bin/phpunit tests/Unit/SeoTitleTest.php` and `dc vendor/bin/phpunit tests/Site/PagesNews/SeoHeadTest.php`.
- [ ] **Step 3: Implement.** `base.php` builds `<title>` with `SeoTitle::render($s->get('seo_title_' . $meta['seo_kind']), $meta['title_vars'] + ['title' => $title, 'sep' => …, 'sitename' => …, 'tagline' => site_description, 'year' => date('Y')], $s->bool('seo_capitalize'))`. A page's own `seo_title` is used verbatim, as today. `seo_kind` 'other' keeps today's `title – siteName` rule. The robots line uses `Robots::meta($meta['noindex'], $meta['robots'] ?? '', $s->get('seo_robots'))` and replaces the fixed `noindex, follow`. `Seo::head` sets `twitter:card` from `seo_card`. Category and tag lists apply `seo_hide_empty_terms` by adding noindex when they have no published news. `Seo::structuredData` uses `breadcrumb_title`, when set, as the name of the last `BreadcrumbList` item (`Front/Seo.php` ~576 and ~602). The site test asserts this too.
- [ ] **Step 4: Run, expect PASS** (same commands, plus `dc vendor/bin/phpunit --testsuite unit`).
- [ ] **Step 5: Commit.** `feat(seo): title templates and robots, canonical and sharing tags`

### Task 3: Saving the fields everywhere

**Files:**
- Create: `system/src/Core/SeoFields.php`
- Modify: `system/src/Admin/Modules/Pages.php` (`actionSeoSave`, `actionSave`), `system/src/Admin/Modules/News.php` (save), `system/src/Admin/Modules/Collections.php` (item save)
- Modify: `system/src/Mcp/Tools.php` (definitions of create_page, update_page, create_news, update_news, save_collection_item), `system/src/Mcp/Handlers/PageTools.php`, `NewsTools.php`, `CollectionTools.php`
- Modify: `system/src/Core/SiteExport.php`, `system/src/Core/SiteImport.php` (columns), `system/src/Core/WpSeo.php` + `WpImport.php` (canonical and focus keyword)
- Test: `tests/Unit/SeoFieldsTest.php`, `tests/Site/McpBuilder/SeoFieldsMcpTest.php`

**Interfaces:**
- Consumes: `Robots::normalize`, `Robots::canonical`.
- Produces: `final class Core\SeoFields`:
  - `const array COLUMNS = ['focus_keywords', 'key_page', 'robots', 'canonical_url', 'breadcrumb_title', 'share_title', 'share_description']`
  - `static fromInput(array $in): array`: only the keys present in `$in` come back, cleaned. `focus_keywords` takes a string with commas or newlines, or a list. Entries are trimmed, compared case-insensitively for duplicates, limited to 5 entries of up to 100 characters each, and stored joined by "\n". `robots` takes a string or a list and goes through `normalize`. `canonical_url` goes through `canonical`. Text fields are trimmed and cut to the column length.
  - `static keywords(string $stored): list<string>`
- Produces: MCP parameters on the five tools:
  - `focus_keywords` (string): "comma separated, the first is scored, at most 5"
  - `key_page` (boolean)
  - `robots` (string): "nofollow,noarchive,noimageindex,nosnippet,max-snippet:N,max-video-preview:N,max-image-preview:large|standard|none"
  - `canonical_url` (string)
  - `breadcrumb_title` (string)
  - `share_title` (string)
  - `share_description` (string)

  `get_page`, `get_news` and `list_collection_items` return the same keys.

- [ ] **Step 1: Write the failing tests.**
  - `SeoFieldsTest::testKeywords`: `fromInput(['focus_keywords' => ' Wedding photographer, wedding photographer ,photos,a,b,c,d'])['focus_keywords']` → `"Wedding photographer\nphotos\na\nb\nc"`.
  - `testOnlyPresentKeys`: `fromInput(['robots' => 'nofollow'])` → `['robots' => 'nofollow']`.
  - `testCanonicalRefused`: `fromInput(['canonical_url' => 'ftp://x'])` → `['canonical_url' => '']`.
  - Site test: `update_page` with `focus_keywords` 'a, b', `robots` 'nofollow,bogus' and `canonical_url` 'https://example.org/'. Then `get_page` returns `"focus_keywords":"a\nb"`, `"robots":"nofollow"` and the canonical. A drafts-only connection (`DraftsOnlyTest` pattern) may set them too, since they are page content. A WordPress fixture post with `rank_math_focus_keyword` and a canonical imports into `focus_keywords` and `canonical_url`. A site export followed by an import keeps all seven columns.
- [ ] **Step 2: Run, expect FAIL.** `dc vendor/bin/phpunit tests/Unit/SeoFieldsTest.php`
- [ ] **Step 3: Implement.** Every save path merges `SeoFields::fromInput($postOrArgs)` into its update array. `WpSeo::raw` also returns `focus_keyword` (Yoast `_yoast_wpseo_focuskw`, Rank Math `rank_math_focus_keyword` (first value before a comma), SmartCrawl `_wds_focus-keywords`). WpImport stores it and the canonical, but only when the canonical points outside the old site (one that points to the old site itself becomes '').
- [ ] **Step 4: Run, expect PASS.** Run the unit test, the site test and `dc php tools/contracts.php --update` (the diff shows only additions).
- [ ] **Step 5: Commit.** `feat(seo): save focus keywords, robots, canonical and sharing fields from admin, MCP and imports`

---

## PR 2 — Page check

### Task 4: Scored analysis

**Files:**
- Modify: `system/src/Core/ContentCheck.php`
- Test: `tests/Unit/SeoAnalysisTest.php` (new); `tests/Unit/ContentHygieneTest.php` must stay unchanged and green

**Interfaces:**
- Produces: `ContentCheck::analyse(array $input): array{score: ?int, groups: array<string, list<array{check: string, ok: bool, message: string, why?: string}>>}`. The input is `run()`'s input plus:
  - `keywords` (list<string>)
  - `slug` (string)
  - `site_host` (string)
  - `keyword_taken` (bool): another row has the same first keyword
  - `has_outline` (bool)

  `run()` stays as it is.
- Produces: `const array GROUPS = ['basics' => 'Basics', 'more' => 'More checks', 'title' => 'Title', 'readability' => 'Readability']` and `const array WEIGHTS`. The check keys, their groups and their weights:
  - basics: `kw_title` 8, `kw_description` 6, `kw_address` 5, `kw_start` 6 (in the first 10 % of words), `kw_text` 6, `word_count` 8 (≥ 600), `title_length` 5, `description_length` 5, `single_h1` 4
  - more: `kw_subheading` 4, `kw_alt` 3, `kw_density` 4 (1.0 ≤ d ≤ 2.5), `address_length` 2 (≤ 75), `links_out` 3, `links_out_follow` 2, `links_in_text` 4 (a link to an own page), `kw_unique` 4, `heading_order` 2, `images_alt` 3
  - title: `kw_title_start` 3, `title_number` 1
  - readability: `has_outline` 2, `short_paragraphs` 3 (no `<p>` over 120 words), `media` 3 (`<img>`, `<video>` or `<iframe>`)
- Score: `round(100 × Σ weights passed / Σ weights applicable)`. It is `null` when `keywords` is empty; the checks without a keyword are still returned. Density = occurrences × words in the keyword ÷ total words × 100, rounded to 0.1. Words are runs of `\p{L}[\p{L}\p{N}'’-]*`. An address check compares `slugify(keyword)` with the slug. A link counts as external when its host is neither empty nor `site_host`.

- [ ] **Step 1: Write the failing tests** (`SeoAnalysisTest`; fixtures as heredoc HTML):
  - `testGoodPageScoresHigh`: about 650 words, keyword "wedding photographer" in the title, description, slug `wedding-photographer`, first sentence, an h2 and an alt, 8 occurrences, one followed external link, one internal link, an outline. Expect `score >= 95` and every check in `basics` ok.
  - `testNoKeywordNoScore`: `keywords` [] gives `score` null, and the `groups['basics']` keys contain `title_length` but no `kw_title`.
  - `testDensity`: 400 words with "photo shoot" 2 times. The `kw_density` check fails and its message contains '1.0%'.
  - `testDiacriticsAndCase`: keyword "Kuchyně na míru" (`// check-english: allow`), text "kuchyne NA miru …". `kw_text` is ok.
  - `testArabicWords`: a 3-word Arabic text has `word_count` message '3'.
  - `testEmptyText`: html '' and keyword 'x'. No exception, `kw_density` is false, the score is an int between 0 and 100.
  - `testNofollowOnlyFails`: all external links `rel="nofollow"` gives `links_out` ok and `links_out_follow` false.
- [ ] **Step 2: Run, expect FAIL.** `dc vendor/bin/phpunit tests/Unit/SeoAnalysisTest.php`
- [ ] **Step 3: Implement** `analyse()` reusing the private `contains`, `firstParagraph`, `headingLevels` and `keyword` helpers. Messages go through `t()` in the canvas wording (e.g. `t('The text has %d words.')` with the `why` `t('Pages that rank well usually have 600 or more.')`).
- [ ] **Step 4: Run, expect PASS.** Run the new test and `tests/Unit/ContentHygieneTest.php`.
- [ ] **Step 5: Commit.** `feat(seo): scored page analysis with focus keyword checks`

### Task 5: Score on save

**Files:**
- Create: `system/src/Core/SeoScore.php`
- Modify: `Pages.php`, `News.php`, `Collections.php` (after save), `system/src/Mcp/Handlers/*` (after the same saves), `system/src/Builder/Publisher.php` (after publishing)
- Test: `tests/Site/PagesNews/SeoScoreTest.php`

**Interfaces:**
- Consumes: `ContentCheck::analyse`, `SeoFields::keywords`.
- Produces: `final class Core\SeoScore`:
  - `static input(App $app, string $kind, array $row): array`: builds `analyse()`'s input. For a page it uses the draft build, else the published build, else the text. For news it uses the intro and text. For items it uses `Collections` text fields. `keyword_taken` comes from `SELECT 1` on all three tables over the first line of `focus_keywords` (`Dialect::likeInsensitive`), excluding the row itself. `has_outline` means an `outline` element in the build, or the setting `article_outline` for news.
  - `static refresh(App $app, string $kind, int $id): ?int`: writes `seo_score` and `seo_checked_at`.
  - `static refreshAll(App $app): int`: used by the site check (Task 15); returns the number of rows updated.

- [ ] **Step 1: Write the failing test.** Saving a page through the admin with focus keywords 'Test page' sets `seo_score` (not NULL) and `seo_checked_at`. Clearing the keywords sets it back to NULL. Two pages with the same first keyword: the later save reports `kw_unique` false on `get_page` → `seo_analysis`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement**, and add `seo_analysis` (the `analyse()` result) to `get_page` and `get_news` next to `content_check`.
- [ ] **Step 4: Run, expect PASS**, then `dc php tools/contracts.php --update`.
- [ ] **Step 5: Commit.** `feat(seo): keep each row's score current on save and publish`

### Task 6: Website mode frame and settings sidebar

Replaces the old Website → SEO tab (`website_seo.php`) and, for existing pages, the page form. Spec: the canvas "Builder frame with SEO sidebar", tabs Page, Publishing, Sharing and Advanced. The SEO tab comes in Task 7.

**Files:**
- Modify: `system/views/admin/pages/website.php` (rewrite: a bar above the frame, the frame, a status line, the right sidebar), `system/views/admin/pages/website_sidebar.php` (the left "SEO" tab opens the right sidebar's SEO tab; the `$more` links for SEO move to the new section in Task 9)
- Create: `system/views/admin/pages/settings.php` (sidebar shell: head with the score pill, tab strip, one form per tab, sticky Save footer), `system/views/admin/pages/settings/page.php`, `publishing.php`, `sharing.php`, `advanced.php`
- Delete: `system/views/admin/pages/website_seo.php`
- Modify: `system/src/Admin/Modules/Pages.php`:
  - `actionWebsite`: loads the full page row, `?tab=`, and the translations the form showed
  - new `actionSettingsSave` and `actionSeoAddType`
  - `actionSave`: its body moves into `persist()`
  - `actionEdit`: an existing page redirects to Website mode
  - `actionSeoSave` removed
- Modify: `image/admin.css` (replace the `.website-frame` … `.website-preview` rules around lines 1640–1653), `image/admin.js` (sidebar collapse)
- Test: `tests/Site/PagesNews/WebsiteSidebarTest.php`, `tests/Unit/ScriptsTest.php` and `tests/Browser/BrowserWalkTest.php` (must stay green)

**Interfaces:**
- Consumes: `SeoFields::fromInput` (Task 3), `SeoScore::refresh` (Task 5), `Core\PageLock::fromForm`, `Core\Validity::date`, `Core\Members::saveFromForm`, `Core\Slug::scope`, `Redirects::add` (as `actionSave` uses them today).
- Produces: `private function persist(?array $page, array $in): array{id: int, errors: array<string, string>}`. It is `actionSave`'s current body reading `$in` instead of `$this->request`. `actionSave` calls it with the request's POST array, so its behaviour and tests are unchanged.
- Produces: `pages/settings_save` (POST `id`, `tab` = page | publishing | sharing | advanced | seo, plus that tab's fields). It merges the page's stored values with the fields the tab owns, so a field a tab does not show is never cleared. Then it calls `persist()` and `SeoScore::refresh`, and redirects to `website?id=<public_id>&tab=<tab>` with "Saved." or the first error. The publishing tab and the page tab's address require `requirePublishPermission`. `head_code` is accepted only from administrators, as in `form.php`. The fields each tab owns:
  - page: `title`, `slug`, `parent_id`, `in_menu`, `sort_order`
  - publishing: `visible`, `publish_at`, `valid_until`, `review_by`, `page_password`, `remove_password`, member groups
  - sharing: `share_title`, `share_description`, `image`, the card size (site-wide `seo_card` stays a setting; this tab shows it read-only with a link to Task 10's screen)
  - advanced: `noindex`, `robots`, `canonical_url`, `breadcrumb_title`, `head_code`
  - seo: `seo_title`, `description`, `focus_keywords`, `key_page` (its view is Task 7)
- Produces: `pages/seo_add_type` (POST `id`, `type` = a type from `Builder\StructuredData::types()`). It appends `{type:'structured_data', content:{data:{'@type': type}}}` to `build_draft` through `Build::sanitize`, then redirects to the builder with the new element selected. Text pages get the button disabled, with the help "Open the page in the builder to add structured data."
- Produces: the markup contract Task 7 and Task 19 fill:
  - `<aside class="website-settings" data-settings-panel>`, the tab strip `nav.website-settings-tabs`, and links `?tab=…` with `aria-current` (they work without JS)
  - the bar's toggle `button[data-settings-toggle][aria-expanded]`: admin.js toggles `.website.settings-closed` on the wrapper and remembers it in `localStorage` (`talea.websiteSettings`); without JS the sidebar stays open
  - in the collapsed rail, one link per tab
- Frame: the bar holds the title, status ("Published" / hidden, "home page", time since the last edit), the live address and the tools: device radios desktop / tablet (820 px) / phone (390 px), still without JS via `:has(:checked)`; open the live page; the sidebar toggle; Edit as the only `.btn`. The `iframe` sits in a rounded frame. Tablet and phone sizes centre it on a dotted ground. The status line shows "All changes saved" plus the links Versions and comments (when draft comments exist).
- Publishing tab actions reuse existing POSTs: `pages/duplicate`, `pages/export`, the versions screen, `pages/delete` (with its existing `data-confirm` text).
- `pages/edit` for an existing page answers 302 to `website?id=…&tab=page`. `pages/new` keeps the form (creating, templates, "start from a template"), and "Return page to text" stays in the builder.

- [ ] **Step 1: Write the failing test** (`WebsiteSidebarTest`).
  - GET `website?id=<about>`: contains the five tab links, `data-settings-panel`, three device radios, the `iframe`, and "Save".
  - POST `settings_save` with `tab=page` and slug `about-us-new`: the slug changes and a redirect from `about-us` exists; `description` and `seo_title` are unchanged.
  - POST with `tab=publishing`, `visible=0`, `valid_until=2027-01-31` and `page_password=secret1234`: hidden, date stored, `password_hash` set (not the plain text).
  - POST with `tab=advanced`, `robots[]=nofollow` and `canonical_url=https://example.org/x`: both stored.
  - An editor's `head_code` is ignored; an administrator's is stored.
  - An editor without the publish right gets the refusal on `tab=publishing`.
  - `pages/edit?id=<about>` answers 302 to Website mode; `pages/new` still answers 200 with the form.
  - `seo_add_type` with `type=Event` adds a `structured_data` element to the draft.
- [ ] **Step 2: Run, expect FAIL.** `dc vendor/bin/phpunit tests/Site/PagesNews/WebsiteSidebarTest.php`
- [ ] **Step 3: Implement** after the canvas board. Parity in `ParityTest`: `pages/settings_save` → `update_page`, `pages/seo_add_type` → `edit_build`, and remove `pages/seo_save`. The dark admin theme needs no extra work: everything uses the admin tokens.
- [ ] **Step 4: Run, expect PASS.** Run `WebsiteSidebarTest`, `PagesFlowTest`, `tests/Unit/ScriptsTest.php` (new hook `data-settings-toggle`) and `dc composer test:browser`.
- [ ] **Step 5: Commit.** `feat(website): framed preview with a tabbed settings sidebar`

### Task 7: The SEO tab: score, live check and marks on the page

**Files:**
- Create: `system/views/admin/pages/settings/seo.php`, `system/views/admin/seo/checks.php` (score ring, keyword chips, groups with counts, the open problems, passed checks in `<details>`), `system/views/admin/seo/serp.php` (search result preview), `image/seo-marks.css`
- Modify: `system/src/Core/ContentCheck.php` (new `marks()`), `system/src/Builder/Context.php` (`seoMarks`), `system/src/Builder/Build.php` (~line 659, attribute assembly), `system/src/Front/Kernel.php` (~478 and ~674, where `editor` is read), `system/src/Front/Seo.php::head()`, `system/src/Admin/Modules/Pages.php` (new `actionSeoCheck`), `system/views/admin/pages/website.php` (the "SEO on the page" tool), `website_sidebar.php` (score dots), `image/admin.js` (live check, `data-insert`, `data-chips`), `image/admin.css`
- Test: `tests/Unit/SeoMarksTest.php`, `tests/Site/PagesNews/SeoTabTest.php`

**Interfaces:**
- Consumes: the Task 6 markup contract and `settings_save` with `tab=seo`, plus `ContentCheck::analyse` and `SeoScore::input`.
- Produces: `ContentCheck::marks(array $build, list<string> $keywords): array<string, string>`. It maps element id → message for:
  - image elements with an empty `alt`: `t('Image without description (alt)')`
  - the first `heading` element with tag h1 when the first keyword is missing from it: `t('Main heading without the focus keyword “%s”', …)`
  - every h1 after the first: `t('A second main heading')`

  The function is pure, and an empty or broken build returns [].
- Produces: `Builder\Context::$seoMarks` (`array<string, string>`, default []). `Build::render` adds `data-seo-mark="<message>"` to an element whose id is in the map. The front sets it only when `?seo_marks=1`, the request is a draft or page preview a signed-in person with the Pages right may see (the same check as `editor=1`), and the page has a build. When it is set, `Seo::head` links `image/seo-marks.css`: fixed colours that read on any site look (a dashed `#f79009` outline and a dark label from `attr(data-seo-mark)` in `::before`), no script. Anonymous requests ignore the parameter, so `Front\Cache` never stores marked output.
- Produces: `pages/seo_check` (POST, the SEO tab's fields). It returns the `seo/checks.php` fragment with status 200 and saves nothing. The form carries `data-seo-live="<url>"`: admin.js posts it 600 ms after the last input and replaces `[data-seo-checks]`. Without JS the tab shows the saved state.
- The bar tool "SEO on the page" is a link that toggles `&marks=1` on the Website URL. The iframe `src` then carries `seo_marks=1`. The tool shows `count(marks)` as a badge and is hidden for text pages. "Show on page" in the checks list is the same link.
- The left page tree shows a score dot per page (`good` ≥ 80, `mid` 50–79, `low` < 50, an empty ring without a keyword) from `seo_score`. The pill in the sidebar head shows the current page's score. Both are hidden when `page_check` is off.

- [ ] **Step 1: Write the failing tests.**
  - `SeoMarksTest::testMarks`: a build with an image (alt ''), an h1 "eure Fotografen für Hochzeit" (`// check-english: allow`), a second h1 and keywords ['Fotografen Jena'] returns three entries keyed by their element ids, with the messages above. `marks([], ['x'])` → [].
  - `SeoTabTest`:
    - `website?id=<home>&tab=seo` contains `name="focus_keywords"`, `data-seo-checks`, `data-seo-live` and the search result preview with the composed title
    - POST `seo_check` with a title containing the keyword: the fragment has `data-check="kw_title"` with class `pass`, and the database is unchanged
    - `website?id=<home>&marks=1`: the iframe `src` contains `seo_marks=1`
    - the draft preview with `seo_marks=1` fetched as the administrator contains `data-seo-mark="Image without description (alt)"` and `seo-marks.css`; fetched anonymously, it contains neither
    - the tree shows a dot with the page's score
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the canvas board's SEO tab:
  - meters under title and description (60 / 160, `warning` bar when over)
  - template variable buttons that insert at the cursor (`data-insert`)
  - keyword chips over a plain textarea (`data-chips`; one keyword per line without JS)
  - "To fix" with the counts per group, the problems first and the passed checks folded away
  - the "When shared" mini preview, which links to `?tab=sharing`

  Parity: `pages/seo_check` is read-only.
- [ ] **Step 4: Run, expect PASS.** Run both tests and `tests/Unit/ScriptsTest.php` (hooks `data-seo-live`, `data-insert`, `data-chips`).
- [ ] **Step 5: Commit.** `feat(seo): SEO tab with live check, score dots and marks on the page`

### Task 8: News and collection items

**Files:**
- Modify: `system/views/admin/news/form.php` (the "advanced" details), `system/views/admin/collections/item.php`, `system/views/admin/content_check.php`
- Test: `tests/Site/PagesNews/SeoNewsItemsTest.php`

**Interfaces:**
- Consumes: `SeoFields`, `SeoScore`, `seo/checks.php` (Task 7).
- Produces: the same fields (focus keywords, key page, robots, canonical, sharing) in the existing forms, and `content_check.php` rendering `seo/checks.php` when the extension `page_check` is on.

- [ ] **Step 1: Write the failing test.** The news form contains `name="focus_keywords"`. Saving it sets `seo_score`. A collection item form saves `canonical_url`. With `page_check` switched off (Task 9 adds the key; until then this asserts the default-on path), the score panel is present.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(seo): focus keyword and score for news and collection items`

---

## PR 3 — The Search engines section

### Task 9: Section, overview and feature switches

**Files:**
- Create: `system/src/Admin/Modules/Seo.php`, `system/views/admin/seo/overview.php`
- Modify: `system/src/Admin/Kernel.php` (`MODULES`: `Seo` first in the new group, then later tasks' modules, then `Redirects` and `Audit` moved; `SECTIONS`: insert `'Search engines'` after `'Website'`), `system/src/Admin/Modules/Redirects.php` and `Audit.php` (`GROUP = 'Search engines'`), `system/src/Core/Extensions.php` (`CATALOG['page_check']`), `system/src/Admin/Guide.php` (anchor `seo` → `seo#overview`)
- Test: `tests/Site/AdminBuilder/SeoSectionTest.php`

**Interfaces:**
- Produces: `Modules\Seo` with `IDENT = 'seo'`, `NAME = 'Overview'`, `GROUP = 'Search engines'`, `ICON = 'search'` and actions:
  - `list` (the overview)
  - `feature` (POST `feature` = page_check | schema_org | indexnow | llms_txt | redirects, `on` = 0 | 1; writes the extension through `Extensions::save` or the setting; administrators only)
- Produces: `Extensions::CATALOG['page_check'] = ['Page check', 'A focus keyword, a score from 0 to 100 and a list of what to fix for every page, news item and collection item.', true]`. When it is off, `seo_score` columns, panels and filters are not rendered.
- The overview tiles:
  - site audit score: the last value stored by Task 15, else "—"
  - pages with a focus keyword: `COUNT` where `focus_keywords <> ''` over visible pages
  - redirects: `COUNT(*)` active
  - missing pages this week: `NotFound::pending($app, 7)` count

  A notice appears when published pages lack a description (count via the existing Audit `description` query).

- [ ] **Step 1: Write the failing test.** The sidebar lists "Search engines" with "Overview", "Redirects" and "Site audit". The overview shows the tiles and one switch per feature above. POSTing `feature=page_check&on=0` removes the score column from `?module=pages`, and removes the score pill, the tree dots and the checks from the Website sidebar's SEO tab. An editor gets 403 on `seo/feature`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the Main board, minus the cards Decisions removes. Add the parity entry `seo/feature` → "admin: switching features changes what the site offers; MCP uses update_settings".
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(seo): Search engines section with overview and feature switches`

### Task 10: Titles and descriptions screen

**Files:**
- Create: `system/src/Admin/Modules/SeoTitles.php`, `system/views/admin/seo/titles.php`
- Modify: `system/src/Admin/Kernel.php` (`MODULES`, after `Seo`)
- Test: `tests/Site/AdminBuilder/SeoTitlesTest.php`

**Interfaces:**
- Consumes: the settings of Task 2, `SeoTitle::render`.
- Produces: `Modules\SeoTitles` (`IDENT = 'seo_titles'`, `NAME = 'Titles and descriptions'`, `GROUP = 'Search engines'`, `ADMIN_ONLY = true`) with:
  - `list` (`?kind=defaults|home|page|news|item|category|tag`)
  - `save`
  - `reset` (POST `kind`; restores that section's keys to `Settings::DEFAULTS`; `data-confirm`)

  The "Reads as" sample is rendered server-side with the latest page title.

- [ ] **Step 1: Write the failing test.** Saving `seo_separator=|` and `seo_title_page=%title% %sep% %sitename%` changes a public page's `<title>` to "About | My website". Saving `seo_robots[]=noarchive` adds `noarchive` to every page's robots meta. `seo_title_page=<script>` is saved as text and printed escaped. `reset` with kind `page` restores `seo_title_page`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the Titles board: the separator picker as radio buttons (CSS `:has(:checked)`), template fields with variable buttons (`data-insert`, Task 7), robots checkboxes and limits, sharing image (the existing media picker field), card size. Parity: `seo_titles/save` → `update_settings`, `seo_titles/reset` → "admin: resets a section in one step".
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(seo): titles and descriptions settings`

### Task 11: Pages list columns and editing titles in the list

**Files:**
- Modify: `system/src/Admin/Modules/Pages.php` (`actionList`, new `actionSeoBulkSave`), `system/views/admin/pages/list.php`, `system/src/Mcp/Handlers/PageTools.php` (`list_pages`)
- Test: `tests/Site/PagesNews/PagesListSeoTest.php`

**Interfaces:**
- Consumes: `seo_score`, `focus_keywords`, `Core\InternalLinks::compute()` (links in per page), and links out counted from each page's text at render.
- Produces:
  - columns Search score, Focus keyword and Links in · out, shown only when `page_check` is on
  - query filter `score` = good | mid | low | none | hidden (good ≥ 80, mid 50–79, low < 50, none = no keyword, hidden = noindex)
  - `pages/seo_bulk_save` (POST `rows[<public_id>][seo_title|description|focus_keywords|noindex]`; updates only the rows sent, through `SeoFields::fromInput` and `SeoScore::refresh`; publishes nothing)
  - `?seo_edit=1` renders the edit rows
  - `list_pages` items gain `seo_score` and `focus_keyword`

- [ ] **Step 1: Write the failing test.** The list shows "No keyword" for a page without keywords and the score for one with them. `?score=low` lists only scores under 50. A bulk save of two rows updates both and leaves a third unchanged. `list_pages` carries `"seo_score"`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the List board. Parity: `pages/seo_bulk_save` → `update_page`.
- [ ] **Step 4: Run, expect PASS**, then `dc php tools/contracts.php --update`.
- [ ] **Step 5: Commit.** `feat(seo): score, keyword and link columns in the pages list, editing titles in the list`

---

## PR 4 — Redirects and missing pages

### Task 12: Match types and more redirect kinds

**Files:**
- Create: `system/database/migrations/20261026100100_extend_redirects.php`, `system/src/Core/RedirectRules.php`
- Modify: `system/src/Front/Kernel.php` (`redirectRule`, `notFound`), `system/src/Core/SiteExport.php` / `SiteImport.php` (columns)
- Test: `tests/Unit/RedirectRulesTest.php`, `tests/Site/LinksConnectors/RedirectMatchTest.php`

**Interfaces:**
- Produces: columns on `redirects`:
  - `match_type` VARCHAR(10) NOT NULL DEFAULT 'exact' (exact | contains | starts | ends | regex)
  - `active` BOOLEAN NOT NULL DEFAULT TRUE
  - `last_hit_at` DATETIME NULL

  `type` accepts 301, 302, 307, 410 and 451.
- Produces: `final class Core\RedirectRules`:
  - `const array TYPES = [301, 302, 307, 410, 451]`
  - `const array MATCHES = ['exact', 'contains', 'starts', 'ends', 'regex']`
  - `static validate(string $from, string $to, string $match, int $type): ?string`: the refusal message, or null. It refuses:
    - a regex that `preg_match` cannot compile
    - a pattern over 255 characters
    - an empty `to` for 301, 302 or 307
    - a `to` that the rule itself would match (a loop)
    - `from` equal to `to`
  - `static target(array $rule, string $path): ?string`: null when the rule does not match. `starts` with a `to` ending in '/' appends the rest of the path. `regex` substitutes `$1`…`$9`. Matching is case-insensitive, and a PCRE error returns null.
- Front: the exact lookup stays the indexed query, with `AND active = TRUE` added. Only when it finds nothing, the non-exact active rules (at most 500, ordered by `redirect_id`) are tried in PHP. A 410 or 451 rule answers with the site's 404 page and that status. Hits update `hits` and `last_hit_at`.

- [ ] **Step 1: Write the failing tests.**
  - `RedirectRulesTest::testTargets`: (starts, `blog/`, `news/`) and `blog/a/b` → `news/a/b`; (regex, `^gallery/(\d+)$`, `projects/$1`) and `gallery/12` → `projects/12`; (ends, `.php`, `/`) and `x/index.php` → `/`; (contains, `old`, `/new`) and `OLD-prices` → `/new`.
  - `testValidate`: `validate('(', 'x', 'regex', 301)` is not null; `validate('^a', 'a/b', 'regex', 301)` is not null (loop); `validate('a', '', 'exact', 410)` is null.
  - `testBacktrackingReturnsNull`: pattern `^(a+)+$` with a path of 30 'a' and '!', with `ini_set('pcre.backtrack_limit', '1000')`, returns null.
  - Site test: a `starts` rule redirects `/blog/x` with 301 to `/news/x`; a 410 rule answers 410 with the 404 body; an inactive rule does not apply; `last_hit_at` is set.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS** (unit, then the site test on MySQL and PostgreSQL).
- [ ] **Step 5: Commit.** `feat(redirects): match types, 307/410/451 and switching rules off`

### Task 13: Redirects screen and MCP

**Files:**
- Modify: `system/src/Admin/Modules/Redirects.php` (`actionList`, `actionSave`, new `actionToggle`, `actionDuplicate`, `actionTest`), `system/views/admin/redirects/list.php`, `edit.php`, `system/src/Mcp/Tools.php` (`save_redirect`, `list_redirects`)
- Test: `tests/Site/LinksConnectors/RedirectsScreenTest.php`

**Interfaces:**
- Consumes: `RedirectRules::validate`, `RedirectRules::target`.
- Produces:
  - tabs `?status=all|active|off|auto` (auto = `auto_score IS NOT NULL`, the redirects the daily job made from missing addresses; the tab label is "Made by Talea")
  - several sources per add (each line of `from[]` becomes one row)
  - row menu Test, Duplicate, Turn off/on, Delete
  - `save_redirect` gains `match` (exact | contains | starts | ends | regex) and `active` (boolean), and `code` accepts 307, 410 and 451
  - `list_redirects` items gain `match`, `active` and `last_hit_at`
  - `actionTest` (GET `id`, `path`) shows the target the rule gives for a sample path, or "does not match"

- [ ] **Step 1: Write the failing test.** Adding two sources creates two rows. A refused regex shows the `validate` message and saves nothing. Toggle sets `active` to 0, and the tab counts update. `save_redirect` with `match: "starts"` and `code: 410` is stored. `list_redirects` returns `"match":"starts"`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the Redirects board. Parity: `redirects/toggle` and `redirects/duplicate` → `save_redirect`; `redirects/test` is read-only.
- [ ] **Step 4: Run, expect PASS**, then `dc php tools/contracts.php --update`.
- [ ] **Step 5: Commit.** `feat(redirects): add form with match types, tabs and row actions`

### Task 14: Missing pages module

**Files:**
- Create: `system/database/migrations/20261026100200_add_not_found_referrer.php`, `system/src/Admin/Modules/MissingPages.php`, `system/views/admin/seo/missing.php`
- Modify: `system/src/Front/Kernel.php::notFound` (referrer), `system/src/Core/NotFound.php` (`pending` returns `referrer` and `first_seen_at`; new `ignored(App, int $limit): list`), `system/src/Admin/Modules/Redirects.php` (the 404 part and its actions `ignore`, `ignore_all` and `clear` move out), `system/views/admin/redirects/list.php`, `system/src/Admin/Kernel.php`
- Test: `tests/Site/LinksConnectors/MissingPagesTest.php`; the existing `LinksTest::testTheNotFoundListSuggestsTheMovedPage` gets its URL changed to `?module=missing_pages`

**Interfaces:**
- Produces: columns on `not_found`:
  - `referrer` VARCHAR(255) NOT NULL DEFAULT '': the host and path of the last referrer, without the query; '' for none or for own-site referrers already shown as "your site: /path"
  - `first_seen_at` DATETIME NULL
- Produces: `Modules\MissingPages` (`IDENT = 'missing_pages'`, `NAME = 'Missing pages'`, `GROUP = 'Search engines'`, `EXTENSION = 'redirects'`, `ADMIN_ONLY = true`) with actions:
  - `list` (`?status=pending|ignored`, `?sort=hits|new`)
  - `redirect` (POST `path`, `to`; creates a 301 through `RedirectRules::validate`)
  - `ignore`, `ignore_all`, `clear` (moved from Redirects; same POST fields and `data-confirm` texts)

  The suggestion column uses `RedirectMatcher`, as the Redirects screen does today.
- Parity: the moved actions keep their MCP mapping (`ignore_not_found`). `missing_pages/redirect` → `save_redirect`.

- [ ] **Step 1: Write the failing test.** Three visits to `/old-x` with `Referer: https://other.example/list?q=1` store `referrer` 'other.example/list'. The list shows it, plus the suggestion and Redirect and Ignore buttons. Redirect creates the 301 and the row leaves "To decide". Ignore moves it to "Ignored". `/wp-login.php` is never recorded (`NotFound::isBot`).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the NotFound board.
- [ ] **Step 4: Run, expect PASS** (also `LinksTest`).
- [ ] **Step 5: Commit.** `feat(seo): missing pages screen with referrer and one-click redirect`

---

## PR 5 — Site audit score

### Task 15: Score and passed checks

**Files:**
- Modify: `system/src/Core/Audit.php` (`score()`, `CATEGORY`, `WEIGHT`), `system/src/Admin/Modules/Audit.php`, `system/views/admin/audit/*.php`, `system/src/Mcp/Tools.php` (`site_audit` description), the `site_audit` handler, `system/src/Core/Settings.php` (`seo_audit_score` '' and `seo_audit_previous` '', both written only by the audit)
- Test: `tests/Unit/AuditScoreTest.php`, `tests/Site/AdminBuilder/AuditScoreTest.php`

**Interfaces:**
- Consumes: `SeoScore::refreshAll` (run before the audit so `kw_unique` is current).
- Produces:
  - `Audit::WEIGHT` (sums to 100): sample 10, link 10, description 10, title 8, build 8, accessibility 8, speed 8, orphan 6, not_found 6, handover 6, menu 4, blueprint 4, review 3, job 3, document 3, fact 3
  - `Audit::CATEGORY`: basics = sample, description, title, build, orphan, menu; advanced = link, not_found, review, job, document, fact, blueprint; speed = speed; security = handover, accessibility
  - `static score(array $findings): array{score: int, passed: list<string>, failed: array<string, int>}`: the score is the sum of the weights of kinds with no findings; `failed` maps kind → finding count
  - `site_audit` returns `score`, `previous_score` and `passed` next to `findings`

- [ ] **Step 1: Write the failing tests.**
  - `AuditScoreTest::testScore`: findings `[['kind' => 'description'], ['kind' => 'description'], ['kind' => 'speed']]` → score 82, failed `['description' => 2, 'speed' => 1]`, and passed contains 'link'.
  - `testWeightsSumTo100`: `array_sum(Audit::WEIGHT) === 100`, and every key of `Audit::KINDS` has a weight and a category.
  - Site test: the audit screen shows the ring with the score, the tabs per category with counts, and "N checks passed" in a closed `<details>`. `site_audit` returns `"score":`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the Analyzer board, minus "Check another address".
- [ ] **Step 4: Run, expect PASS**, then `dc php tools/contracts.php --update`.
- [ ] **Step 5: Commit.** `feat(audit): site score, categories and passed checks`

---

## PR 6 — Search Console and Bing Webmaster Tools in the section

### Task 16: More search data per snapshot

**Files:**
- Create: `system/database/migrations/20261026100300_add_search_stats_page.php`
- Modify: `system/src/Core/SearchData.php` (`fetchGoogle`, `fetchBing`, `store`, new pure mappers), `tools/fake/search.php`
- Test: `tests/Unit/SearchDataRowsTest.php` (new), `tests/Site/LinksConnectors/SearchDataTest.php` (extend; update the counts asserted in `testTheDailyJobLoadsBothEnginesIntoOneSnapshot`)

**Interfaces:**
- Produces: column `search_stats.page` VARCHAR(255) NOT NULL DEFAULT '', and index `ix_search_stats_engine_kind_day_page` (engine, kind, day, page). New kinds:
  - `total`: key '', one row; Google from a query without dimensions; Bing as the sum of the `day` rows, position 0 = unknown
  - `day`: key 'YYYY-MM-DD'; Google dimension `date`; Bing `GetRankAndTrafficStats`, filtered to the last 28 days
  - `page_query`: key = the query, `page` = the page URL; Google only, dimensions `['page', 'query']`, `rowLimit` 1000
- Produces: pure mappers:
  - `SearchData::googleTotal(array $json): array`
  - `googleDays(array $json): list<array>`
  - `googlePageQueries(array $json): list<array>` (each row also has `page`)
  - `bingDays(array $json, int $since): list<array>`

  Each returns the same row shape as `row()`, plus `page`.

- [ ] **Step 1: Write the failing tests.** `SearchDataRowsTest`:
  - `googlePageQueries(['rows' => [['keys' => ['https://example.com/a', 'q'], 'clicks' => 3, 'impressions' => 40, 'ctr' => 0.075, 'position' => 4.26]]])` → one row with kind `page_query`, key `q`, page `https://example.com/a`, ctr 7.5, position 4.3.
  - `bingDays` drops a row older than `$since` and parses `/Date(…)/`.
  - `googleTotal(['rows' => [['clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 7.0]]])` → kind `total`, key ''.

  Site test: after `search_data`, today's snapshot has kinds `total`, `day` (28 rows for Google in the fake) and `page_query` for Google, and `total` and `day` for Bing. The job message names the new counts.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** The fake answers dimension `date` with 28 rows, `['page', 'query']` with 3 rows, no dimension with a total, and `GetRankAndTrafficStats` with 2 days. A failure of the `page_query` call alone keeps the other rows (as sitemaps do today).
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(search): totals, days and searches per page in the daily snapshot`

### Task 17: Reading the data (SearchInsights)

**Files:**
- Create: `system/src/Core/SearchInsights.php`
- Test: `tests/Unit/SearchInsightsTest.php`, `tests/Site/LinksConnectors/SearchInsightsTest.php`

**Interfaces:**
- Consumes: `tl_search_stats` rows from Task 16.
- Produces: `final class Core\SearchInsights`:
  - `const int OPPORTUNITY_MIN_IMPRESSIONS = 50`
  - `const float OPPORTUNITY_FROM = 8.0`, `const float OPPORTUNITY_TO = 20.0`
  - `const int LOW_CTR_MIN_IMPRESSIONS = 200`
  - `const array EXPECTED_CTR = [1 => 25.0, 2 => 15.0, 3 => 10.0, 5 => 6.0, 10 => 3.0, 100 => 1.0]` (position ceiling → per cent); a page is "rarely clicked" when its ctr < 0.5 × expected
  - `static latestDay(Db $db, string $engine): ?string`
  - `static summary(Db $db, string $engine): ?array{day, totals: array{clicks, impressions, ctr, position}, previous: ?array, days: list<array{day, clicks, impressions}>}`: `previous` is the total row of the snapshot nearest to `day − 28 days` (±3 days), else null. `engine` = google | bing | both (both sums clicks and impressions, and the position is the impression-weighted mean of the engines with a position).
  - `static opportunities(array $queryRows, array $pageQueryRows): list<array{query, position, impressions, page: ?string}>` (pure; top 10 by impressions)
  - `static lowCtr(array $pageRows): list<array{page, impressions, ctr, position}>` (pure; top 10 by impressions)
  - `static byPath(Db $db, string $engine, string $origin): array<string, array{clicks, impressions, position}>`: the key is a path without the leading slash, made from the page URL's path when its host matches `$origin`'s
  - `static forPath(Db $db, string $path, string $origin): array{totals: ?array, queries: list<array{query, clicks, impressions, position}>}` (Google `page_query` rows)
  - `static notInSearch(Db $db, string $origin, list<string> $publishedPaths): ?list<string>`: null when the Google page row count ≥ `SearchData::ROW_LIMIT` or there is no snapshot

- [ ] **Step 1: Write the failing tests** (pure ones in Unit).
  - `opportunities` keeps position 11.4 with 2,140 impressions, drops 3.0 and drops 40 impressions, and fills `page` from the `page_query` row with the most impressions.
  - `lowCtr` flags position 6.1 with ctr 0.9 (expected 3.0) and not position 1.2 with ctr 20.
  - Site test: `summary` with only one snapshot has `previous` null. With no connection all methods return null or [] and do not throw. A snapshot dated 5 days ago is returned with its day.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Use only portable SQL (`Dialect` for quoting `key`).
- [ ] **Step 4: Run, expect PASS** (the site test on both engines).
- [ ] **Step 5: Commit.** `feat(search): summaries, opportunities and per-page search data`

### Task 18: Search performance screen and overview tiles

**Files:**
- Create: `system/src/Admin/Modules/SearchPerformance.php`, `system/views/admin/seo/performance.php`, `system/views/admin/seo/chart.php` (an SVG line chart partial)
- Modify: `system/src/Admin/Kernel.php` (`MODULES`, after `Seo`), `system/views/admin/seo/overview.php` (two tiles and the feature card)
- Test: `tests/Site/LinksConnectors/SearchPerformanceTest.php`

**Interfaces:**
- Consumes: `SearchInsights::*`, `Connectors::isConnected`.
- Produces: `Modules\SearchPerformance` (`IDENT = 'search_performance'`, `NAME = 'Performance'`, `GROUP = 'Search engines'`) with action `list` (`?engine=both|google|bing`). The view shows:
  - the connection lines (a status dot per service)
  - four tiles with change against `previous` (position: lower is better, shown as "N higher")
  - the day-by-day chart: server-built `points` for two `<polyline>`s, `role="img"` with an `aria-label` that sums up the trend
  - Almost on the first page, Seen often and clicked rarely, Top searches (with the "Focus keyword" tag when a row's query equals the first focus keyword of a page; otherwise "Use for a page", a link to the pages list filtered by `?search=`), Top pages (mapped to Talea pages by `byPath`, with score), and In the search index (Google sitemaps, plus the `notInSearch` line)
- Empty states:
  - nothing connected: one notice with a button to Connections
  - connected, no snapshot: "The first data arrives after tonight's update."
  - a snapshot older than 3 days: "Last updated on %s." in `warning-text`

- [ ] **Step 1: Write the failing test.** With the fake services connected and `search_data` run, the screen shows "Clicks", the fake total, a `<polyline` and the query "talea cms", and the page row links to the editor of the page with slug `services` (created in the test). `?engine=bing` shows the Bing total only. With nothing connected, the empty-state notice links to `?module=connectors`. The Bing key never appears in the body.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** after the Performance board. The chart partial takes `list<array{day, clicks, impressions}>` and draws in a `viewBox="0 0 1000 200"` with `vector-effect: non-scaling-stroke`. Parity: `search_performance/list` is read-only.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(search): search performance screen and overview tiles`

### Task 19: Search data at each page, in the list and over MCP

**Files:**
- Modify: `system/views/admin/seo/checks.php` (the "In search, last 28 days" block), `system/src/Admin/Modules/Pages.php` (new `actionSeoKeywordAdd`; list column), `system/views/admin/pages/list.php`, `system/src/Mcp/Tools.php` (new tool `get_search_performance`), `system/src/Mcp/Catalog.php` (`'get_search_performance' => ['read', '']`), the `get_page` handler (`search` block)
- Test: `tests/Site/LinksConnectors/SearchAtPageTest.php`

**Interfaces:**
- Consumes: `SearchInsights::forPath`, `byPath`, `summary`, `opportunities`, `lowCtr`.
- Produces:
  - `pages/seo_keyword_add` (POST `id`, `keyword`): appends the keyword to `focus_keywords` through `SeoFields::fromInput` (the 5-keyword limit applies), then refreshes the score
  - the pages list column "Clicks · place" (from `byPath`, engine both; "Not in search yet" only for paths in `notInSearch`)
  - MCP tool `get_search_performance` (read-only): `{engine?: "google"|"bing"|"both", page?: <page public id>}`. It returns `summary`, `queries` (top 25), `pages` (top 25 with `page_id` as a public id where mapped), `opportunities`, `low_ctr`, `sitemaps`, and with `page` that page's `forPath`. Each engine with no data gives `null` plus `"note"`.
  - `get_page` gains `search`, which is `forPath` or null

- [ ] **Step 1: Write the failing test.**
  - The Website sidebar's SEO tab for page `services` (`website?id=…&tab=seo`) shows the "In search · 28 days" block with the clicks and a query from `page_query`, with "Add as keyword". POSTing it adds the query to `focus_keywords`. A sixth keyword is refused with a notice.
  - The pages list shows "31 · 6.2" for `services`.
  - `get_search_performance` with `page` = that page's public id returns `"queries":[{"query":`. Without a connection it returns `"google":null` with a note.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Parity: `pages/seo_keyword_add` → `update_page`.
- [ ] **Step 4: Run, expect PASS**, then `dc php tools/contracts.php --update` and `dc composer test`.
- [ ] **Step 5: Commit.** `feat(search): search data at each page, in the pages list and over MCP`
