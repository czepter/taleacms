# Admin List Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every admin overview follows one slim list design: page head with count and one primary button, status tabs + search, one-line rows with a dot status, a pencil and a "⋯" menu, and a selection bar that appears on demand.

**Architecture:** Shared building blocks are PHP partials under `system/views/admin/list/` plus one CSS section in `image/admin.css`; behaviour uses native HTML (Popover API, `:has()`, CSS counters, `form` reset) so `image/admin.js` barely changes. The layout gets two slots (count, actions) that a list view fills through `View::slot()`. `table.listing` stays the element (semantics, phone card mode, existing tests); it is restyled globally first, then the entity lists are converted in batches.

**Tech Stack:** PHP 8.5 views, plain CSS (tokens in `image/admin.css`), vanilla JS (`image/admin.js`), PHPUnit/ParaTest (unit + site suites), `tools/test-browser.mjs`.

**Spec:** `docs/specs/admin-lists.md` (mock-ups: Claude design artifact `QENHvGKpeTQaVHNiTH6uQn`, boards Main, Kit, News, Enquiries, Items, Redirects, Users, Bookings, Categories, Components, Popups).

## Global Constraints

- PHP, Composer and PHPUnit run only in the dev container. Shorthand used below (dev stack up, from the worktree root):
  `dc() { colima ssh -- sh -c "cd '$PWD' && docker compose -f docker-compose-dev.yaml exec -T web $*"; }`
- English everywhere: code, CSS comments, test names; UI text only through `t('English')`. Every new `t()` string gets a Czech
  and a German entry in `system/languages/admin-cs.php` / `admin-de.php` (`tools/add-translations.py`); `TranslationsTest::testGermanAdministration` enforces de for every cs key.
- Admin CSP is `script-src 'self'`: no inline scripts, no `on*=` attributes. `data-confirm` stays the only confirmation mechanism.
- No `!important`, no new dependency, no build step. Colours only through `:root` tokens with dark values in **both** dark blocks of `admin.css` (lines ~48-66 and ~68-85).
- What nothing emits has no style: a CSS rule lands in the same commit as the first markup that uses it; a removed emitter takes its rule with it.
- POST contracts do not change: action URLs, field names (`selected[]`, `bulk`, `page_id` …), CSRF fields and `data-confirm` texts stay, so MCP parity (`tests/Unit/Mcp/ParityTest.php`) is untouched. No new admin action unless a task says so.
- URL query parameters are English (`status`, `search`, `language`).
- Before each commit: `dc php tools/check-english.php` exits 0. Before each PR: `dc composer test`.
- PRs are stacked (base = parent branch): PR 1 = Tasks 1-4, PR 2 = Task 5, PR 3 = Task 6, PR 4 = Task 7, PR 5 = Task 8, PR 6 = Task 9.

## Review Focus

- **Row menu on the last rows / near the viewport edge:** the popover must stay on screen (anchor fallback `position-try-fallbacks: flip-block, flip-inline`) — check in Task 4 with the last page row.
- **Long titles and RTL/Arabic titles:** one line with ellipsis, `bdi()` kept, `title` attribute holds the full text — Task 3 partial test with a 200-character title.
- **Keyboard and no-JS use:** search submits on Enter, the "⋯" menu opens with Enter/Space and closes with Esc (native popover), the selection bar works without admin.js — Task 3 tests assert the hidden submit button and `popovertarget`.
- **Confirmations inside the menu:** a `data-confirm` form inside an open popover still shows the dialog and submits — Task 4 browser check (move a page to the trash from the menu).
- **Phone width (≤ 640 px):** the card layout of `table.listing` (admin.js adds `.listing-cards`) must show the new cells sensibly (checkbox, main cell, actions on one line) — Task 4 browser check at 375 px.

---

### Task 1: Icons and relative dates

**Files:**
- Modify: `system/views/admin/icons.php` (add keys)
- Modify: `system/src/helpers.php` (add `format_ago`)
- Modify: `system/languages/admin-cs.php`, `admin-de.php`
- Test: `tests/Unit/Admin/ListIconsTest.php`, `tests/Unit/FormatAgoTest.php`

**Interfaces:**
- Produces: icon keys `edit` (pencil), `more` (three filled dots), `check`, `external`, `home`, `search`, `plus`, `close`, `upload`, `download`, `subpage`, `copy`, `trash`, `restore`, `eye`, `attachment`, `shield`, `layers`; `function format_ago(string|\DateTimeInterface|null $value, ?int $now = null): string`.

- [ ] **Step 1: Write the failing tests**

`ListIconsTest::testActionIconsExist` — `$icon = require ROOT . '/system/views/admin/icons.php';` for each key above assert `str_contains($icon($key), '<svg')` and that `$icon($key) !== $icon('article')` (no fallback).
`FormatAgoTest` with `$now = strtotime('2026-10-22 12:00:00')` and the app time zone UTC:
`format_ago(null)` → `'—'`; `'2026-10-22 11:59:30'` → `'just now'`; `'2026-10-22 11:15:00'` → `'45 min ago'`; `'2026-10-22 07:00:00'` → `'5 h ago'`; `'2026-10-21 18:00:00'` → `'yesterday'`; `'2026-10-19 12:00:00'` → `'3 days ago'`; `'2026-10-14 12:00:00'` → `format_date('2026-10-14 12:00:00')`.

- [ ] **Step 2: Run, expect FAIL** — `dc vendor/bin/phpunit tests/Unit/Admin/ListIconsTest.php tests/Unit/FormatAgoTest.php` → unknown function / fallback icon.
- [ ] **Step 3: Implement.** Icon paths: 24×24 stroke paths matching the existing set (the mock-up's pencil is `<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>`; `more` uses three `<circle r="1.8" fill="currentColor" stroke="none"/>`). `format_ago`: thresholds < 60 s, < 60 min, < 24 h, previous calendar day, < 7 days, else `format_date()`; strings `t('just now')`, `t('%d min ago', n)`, `t('%d h ago', n)`, `t('yesterday')`, `t('%d days ago', n)`.
- [ ] **Step 4: Run, expect PASS** (same command). Add cs/de translations (de: "gerade eben", "vor %d Min.", "vor %d Std.", "gestern", "vor %d Tagen").
- [ ] **Step 5: Commit** — `feat(admin): action icons and relative dates for lists`

### Task 2: Page head slot

**Files:**
- Modify: `system/src/Core/View.php` (slot store)
- Modify: `system/views/admin/layout.php:100-107` (header-pages)
- Create: `system/views/admin/list/head.php`
- Modify: `image/admin.css` (`.header-pages`, `.page-count`, `.page-actions`)
- Test: `tests/Unit/Admin/ListPartialsTest.php` (new file, used by Task 3 too)

**Interfaces:**
- Produces: `View::slot(string $name, ?string $html = null): string` — with `$html` stores and returns `''`, without returns the stored HTML or `''`. Partial `admin/list/head` with data `count: ?string`, `links: list<array{0: string, 1: string}>` (url, label), `secondary: string` (raw HTML, default `''`), `primary: ?array{0: string, 1: string}`; renders nothing itself and fills slots `count` and `actions`.
- Layout prints: `<div class="header-pages"><div class="page-title"><h1>…</h1>[<span class="page-count">count</span>]</div><div class="page-actions">[guide link][actions slot]</div></div>`.

- [ ] **Step 1: Write the failing test** — `ListPartialsTest::testHeadFillsSlots`: `$view = new View([ROOT . '/system/views']);` render `admin/list/head` with `count => '19 pages'`, `links => [['?a', 'Categories']]`, `primary => ['?new', 'New page']`; assert render output `''`, `$view->slot('count') === '19 pages'` (escaped), `$view->slot('actions')` contains `class="btn"` with the plus icon and `href="?new"`, and the quiet link `class="quiet-link"`.
- [ ] **Step 2: Run, expect FAIL** — `dc vendor/bin/phpunit tests/Unit/Admin/ListPartialsTest.php`.
- [ ] **Step 3: Implement** the slot store (private array), the partial and the layout change; CSS: `.header-pages` flex, wrap, `justify-content: space-between`; `.page-count` 15 px `--text-weak`; `.page-actions` flex gap 8 px; `.quiet-link` 15 px `--text-weak`, no underline, hover `--text`.
- [ ] **Step 4: Run, expect PASS**, then `dc composer test:unit`.
- [ ] **Step 5: Commit** — `feat(admin): page head with count and actions slot`

### Task 3: List building blocks

**Files:**
- Create: `system/views/admin/list/toolbar.php`, `status.php`, `actions.php`, `selection.php` (`switch.php` comes with Task 6, `attention.php` with Task 8, each with its CSS and its unit test in `ListPartialsTest`)
- Modify: `image/admin.css` (new section "Lists – docs/specs/admin-lists.md"; global `table.listing` restyle; `--planned` token)
- Modify: `image/admin.js:25-29` only if Step 1's no-JS assertions need it (expected: no change)
- Test: `tests/Unit/Admin/ListPartialsTest.php`

**Interfaces** (all partials take `csrf` where they post; every user value goes through `e()`/`bdi()`):
- `admin/list/toolbar`: `action: string` (GET target, `admin.php`), `hidden: array<string, string>` (e.g. `module`, `status`), `tabs: list<array{label: string, count?: int, url: string, active: bool}>`, `tabsLabel: string`, `search: ?array{name: string, value: string, placeholder: string, label: string}`, `filters: string` (raw HTML of selects carrying `data-submit-on-change`). Output: `<div class="list-toolbar"><nav class="list-tabs" aria-label>` with `<a aria-current="page">` on the active tab and `<span class="list-tab-count">`; `<form class="list-filters" method="get">` with hidden fields, filters, `<label class="list-search">` (visually hidden label text, search icon, `<input type="search">`) and `<button type="submit" class="visually-hidden">t('Search')</button>`.
- `admin/list/status`: `kind: 'live'|'hidden'|'draft'|'planned'|'review'|'error'|'off'`, `label: string`, `note?: string`, `noteKind?: 'warning'|'error'` (default warning), `noteUrl?: string`. Output `<span class="status status-{kind}">label</span>` and, with a note, `<a|span class="status-note status-note-{noteKind}">`.
- `admin/list/actions`: `id: string` (public id; popover id `row-menu-{id}`), `name: string` (row title), `edit: ?string` (URL), `quick: ?array{action: string, fields: array<string, string>, label: string, icon: string, filled: bool}`, `menu: list<array|string>` where an item is `['url' => …, 'label' => …, 'icon'? => …, 'external'? => bool]`, `['post' => url, 'fields' => array<string, string>, 'label' => …, 'icon'? => …, 'confirm'? => string, 'danger'? => bool]`, `['note' => string]` or the string `'-'` (separator), `csrf: string`. Output `<div class="row-actions">`: pencil `<a class="icon-btn" aria-label="t('Edit: %s', name)" title="t('Edit')">`, optional quick form, `<button type="button" class="icon-btn" popovertarget="row-menu-{id}" aria-label="t('More actions for %s', name)">` and `<div class="row-menu" id="row-menu-{id}" popover>` with links/forms; a danger form gets `class="danger"` on its button. Empty menu → no button, no popover.
- `admin/list/selection`: `form: string` (id, normally `bulk`), `action: string`, `csrf: string`, `hidden: array<string, string>`, `actions: list<array{value: string, label: string, kind?: 'primary'|'danger'|'', confirm?: string}>`, `extra: string` (raw HTML, e.g. category select). Output `<form id class="selection-bar" method="post">` with `<span class="selection-count"><span class="selection-number"></span> t('selected')</span>`, one `<button name="bulk" value>` per action (`data-confirm` on the button when given), `<button type="reset" class="icon-btn" aria-label="t('Clear selection')">`.
- `admin/list/switch`: `action: string`, `fields: array<string, string>`, `on: bool`, `label: string`, `csrf: string`. Output a POST form with `<button type="submit" class="switch" role="switch" aria-checked="true|false" aria-label>`.
- `admin/list/attention`: `title: string`, `meta?: string`, `rows: string` (raw HTML, one `.attention-row` each), `aside?: string` (raw HTML, e.g. "Ignore all" form). Output `<section class="attention" aria-labelledby>`.
- Cell classes used by every converted list: `th/td.cell-select` (52 px, header checkbox gets `form="{form}"` and no name so `reset` clears it too), `td.cell-main` with `.cell-title` (link, weight 600, ellipsis) and `.cell-sub` (13 px weak, ellipsis) — ellipsis works in auto table layout via `.cell-main { max-width: 0; width: 100% }`; `td.cell-actions` (width 1%, right aligned); `.tag` (the exception pill: 12 px, border `--line-field`, radius 6 px).

- [ ] **Step 1: Write the failing tests** in `ListPartialsTest`:
  - `testToolbarMarksActiveTabAndKeepsKeyboardSubmit`: active tab has `aria-current="page"` and its count; the form has `method="get"`, the hidden `module` field, `type="search"` with the value escaped, and `class="visually-hidden"` submit.
  - `testStatusRendersDotWordAndNote`: `kind => 'hidden', label => 'Hidden', note => 'review by 01.11.'` → `class="status status-hidden">Hidden<` and `status-note status-note-warning`.
  - `testActionsMenuHasPopoverAndDangerLast`: menu `[['url' => '?edit', 'label' => 'Settings'], '-', ['post' => '?delete', 'fields' => ['page_id' => 'u1'], 'label' => 'Move to trash', 'confirm' => 'Sure?', 'danger' => true]]` → `popovertarget="row-menu-u1"`, `id="row-menu-u1" popover`, the CSRF field, `data-confirm="Sure?"`, `class="danger"`, and the danger form's position after `role="separator"`.
  - `testActionsWithoutMenuHasNoMoreButton`: `menu => []` → no `popovertarget`.
  - `testLongTitleIsEscapedAndCut`: a 200-character title with `<` in `name` → escaped in `aria-label`, nothing unescaped.
  - `testSelectionBarSubmitsBulkValues`: two actions → two `name="bulk" value=…` buttons, the danger one with `data-confirm`, `type="reset"` present, `id="bulk"`.
- [ ] **Step 2: Run, expect FAIL** — `dc vendor/bin/phpunit tests/Unit/Admin/ListPartialsTest.php`.
- [ ] **Step 3: Implement the partials.**
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: CSS.** Load the `modern-web-guidance` skill first (Popover API, anchor positioning, `:has()`). Add `--planned` (light `#1F5FBF`, dark `#8FB8FF`, contrast ≥ 4.5 : 1 on `--surface`). Global `table.listing`: header 13 px weight 500 `--text-weak`, no header background, cells `padding: 10px 16px`, row min height 64 px, `border-top: 1px solid var(--line)`, hover `color-mix(in oklch, var(--text) 3%, transparent)`, drop the `tr.unpublished` inset bar (status says it). `.status::before` 8 px dot by kind; `.status-note` 13 px with `padding-inline-start: 16px`. `.icon-btn` 36 × 36, `border: 1px solid var(--line-field)`, radius 8 px. `.row-menu`: `position-area: block-end span-inline-start; position-try-fallbacks: flip-block, flip-inline; margin: 4px 0 0; width: 248px`, surface, border `--line-field`, radius 12 px, shadow; items 40 px high; `.row-menu .danger` `--error`; separator 1 px `--line`. `.list-toolbar` flex wrap space-between; `.list-tabs` segmented (container `--surface` + border `--line`, radius 12 px, active `--accent-light`); `.list-search` 280 px with icon inset. `.selection-bar`: `display: none`; `main:has(input[name="selected[]"]:checked) .selection-bar { display: flex }`, `position: sticky; bottom: 24px`, centered, `--accent-light` background, radius 14 px; `main { counter-reset: selected }`, `input[name="selected[]"]:checked { counter-increment: selected }`, `.selection-number::before { content: counter(selected) }`. `.visually-hidden` clip pattern. Every rule here is emitted by the pages list in Task 4, which lands in the same PR.
- [ ] **Step 6: Commit** — `feat(admin): shared list partials and styles`

### Task 4: Pilot – pages list

**Files:**
- Modify: `system/views/admin/pages/list.php` (rewrite of the normal and trash branches)
- Modify: `system/src/Admin/Modules/Pages.php:40-65` (status filter + counts)
- Modify: `tests/Site/JobsFleetFacts/ValidityTest.php:83`, `tests/Site/AgentAddons/DraftCommentsTest.php:162` (markup changed), `tests/Site/FormsHygiene/ContentHygieneTest.php:114` (only if the bulk markup assertion changes; `form="bulk"` must stay)
- Test: `tests/Site/PagesNews/PagesFlowTest.php` (new assertions)

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: GET `status` values for pages: `published`, `hidden`, `changes` (`build_draft IS NOT NULL`), `trash`; the view gets `$counts = ['all' => int, 'published' => int, 'hidden' => int, 'changes' => int, 'trash' => int]` from one portable `SUM(CASE WHEN … THEN 1 ELSE 0 END)` query.

Mapping (board "Main"): head count `t('%d pages')`, secondary = the existing JSON import form (button style), quiet link Translations when languages exist, primary New page. Main cell: home icon for the home page, tree indent for `level` (keep the inline `padding-inline-start`), title → builder when `build` or `build_draft` is set, else edit; sub-line = `/address` link to the site (draft preview for hidden pages) with the external icon; tag "Text" when the page has no build. Columns: Status (live/hidden; note "Changes not published" when `build_draft` is set; notes for `publish_at`, `valid_until`, `review_by` and the comments link replace the badges), Menu (check icon with visually hidden "In the navigation", empty otherwise), Changed (`format_ago(updated_at)`). Actions: pencil = same target as the title; menu = Settings & SEO (edit), View on the site, New subpage, Duplicate (POST), separator, Move to trash (POST, existing confirm) — on the home page a note `t('The home page cannot be deleted.')` instead. Selection: `admin/list/selection` with Publish (primary), Hide, Move to trash (danger) — values `visible`, `hide`, `trash`, plus the language select as `extra` when site languages exist. Trash tab: same table without checkboxes; quick action Restore (icon `restore`, existing `restore` form) and menu item Delete permanently (danger, existing confirm); columns Name, In trash since.

- [ ] **Step 1: Write the failing site test** in `PagesFlowTest`: `testListShowsStatusTabsAndRowMenu` — create one hidden page and one with a draft build; GET `admin.php?module=pages` asserts `class="list-tabs"`, `Hidden <span class="list-tab-count">1`, `popovertarget="row-menu-<public_id>"`, `class="status status-hidden"`, `Changes not published`; GET `…&status=changes` lists only the draft page.
- [ ] **Step 2: Run, expect FAIL** — `dc vendor/bin/phpunit --testsuite site --filter PagesFlowTest`.
- [ ] **Step 3: Implement** controller filter/counts and the view.
- [ ] **Step 4: Run, expect PASS**; update the three listed site tests to the new markup and run `dc vendor/bin/phpunit --testsuite site --filter 'PagesFlowTest|ValidityTest|DraftCommentsTest|ContentHygieneTest'`.
- [ ] **Step 5: Browser check** (preview tools, dev stack): pages list light and dark, open the last row's menu (stays on screen), Move to trash from the menu (confirm dialog appears, page lands in trash), select two rows (bar appears with "2 selected", Reset clears it and the header box), 375 px width. Run `dc composer test:browser` (`tools/test-browser.mjs:52-53` needs an `a[href*="action=edit&id="]`; the menu's Settings link provides it — fix the walker if it clicks hidden elements).
- [ ] **Step 6: Translations + english check + commit** — `feat(admin): new pages list` — open PR 1.

### Task 5: News and simple lists

**Files:** `system/views/admin/news/list.php`, `news/links.php`, `categories/list.php`, `tags/list.php`, `notebook/list.php`, `newsletters/list.php`, `requests/list.php`; `system/src/Admin/Modules/News.php` (tab counts); tests `tests/Site/PagesNews/NewsTrashTest.php` (+ new assertions in an existing news site test).

Mapping:
| View | Main cell (title → sub) | Other columns | Status | Visible actions → menu |
|---|---|---|---|---|
| news/list (board News) | title → category · #tags | author (initials + name), date (`publish_at` future = planned "from …") | live / planned / draft / review; notes: broken links (error), review by, visible until | pencil → Preview, Duplicate, Social posts, —, Move to trash |
| news/links | link URL → where (news title) | problem, found (`format_ago`) | error | pencil (edit the news item) → Ignore |
| categories/list (board Categories) | name → `/news/category/slug` | news count (link to filtered news) | — | pencil → —, Delete; New category stays the head's primary button (the mock-up's inline form would need a name-only save path in `Categories::actionSave`: not now); head has a breadcrumb-style quiet link back to News |
| tags/list | tag → topic intro yes/no | news count | — | pencil → Merge with another tag, —, Delete |
| notebook/list | note → topic | author, changed (`format_ago`) | — | pencil → —, Delete |
| newsletters/list | subject → recipients | sent / failed | draft / live (sent) / error (failed > 0) | pencil → —, Delete |
| requests/list | request → author | written, last change (`format_ago`) | map existing status to live/hidden/draft | pencil only |

News tabs: All, Published, Scheduled, Drafts, Awaiting publication, Trash (counts from one query); category filter select stays as `filters`. News rows switch to `selected[]` + `form="bulk"`; the selection bar offers Publish (primary), Back to draft, Move to trash (danger) and the category select as `extra`; "Delete selected" moves into the trash tab's bar (keep its confirm text, `NewsTrashTest` asserts it).

- [ ] Step 1: failing site assertions (news list has `class="list-tabs"`, a scheduled item shows `status-planned`, trash tab bar shows "Delete selected").
- [ ] Step 2: run, FAIL. Step 3: implement. Step 4: `dc vendor/bin/phpunit --testsuite site --filter 'News|Categor|Tag|Notebook|Newsletter|Request'` PASS. Step 5: browser check of news list. Step 6: translations, english check, commit `feat(admin): news and simple lists in the new list design`, PR 2 (base PR 1).

### Task 6: Builder content lists

**Files:** `system/views/admin/collections/list.php`, `collections/items.php`, `components/list.php`, `popups/list.php`, `parts/list.php`, `blueprints/list.php`, `pages/translations.php`; `system/src/Admin/Modules/Collections.php:249` (bug); `system/src/Admin/Modules/Components.php` (unused filter); tests `tests/Site/McpBuilder/ComponentsTest.php:72-73`, `tests/Site/FormsLook/PopupsTest.php`, `tests/Site/CollectionsA/DocumentLibraryTest.php:69`.

- [ ] **Step 1: Failing test for the bulk bug first** — new `tests/Site/CollectionsA/CollectionItemsBulkTest.php::testBulkHideUsesPublicIds`: two items, POST `module=collections&action=bulk_items` with `selected[]` = the first item's public id and `bulk=hide` → that item hidden, the other unchanged; a public id that starts with digits must not touch an item whose integer id equals that number.
- [ ] Step 2: run, FAIL (`intval` of the UUID). Step 3: resolve each id with `Db::byPublicId('collection_items', …)` restricted to the collection; run, PASS; commit `fix(collections): bulk item actions use public ids`.

Mapping:
| View | Main cell | Other columns | Status | Actions |
|---|---|---|---|---|
| collections/list | collection name → item pages yes/no | items count | — | pencil (items) → Fields and settings, Detail template |
| collections/items (board Items) | thumbnail (first image field, 52 px, else image icon) + name → field summary (first two short fields) | order (keep the number column until drag-and-drop exists), downloads (keep `td.number download` markup) | live / hidden; notes visible until, review by, notice archive | pencil → Duplicate, —, Move to trash; selection bar Show/Hide/Move to trash |
| components/list (board Components) | layers icon + name → property chips `<code>{{key}}</code>` or "no properties" | "Used on": `t('%d places', n)` / "nowhere" (warning colour) with the first places as sub-line; a `<button popovertarget>` opens a popover listing all places | live; note "Changes not published" | pencil (builder) → Name and properties, —, Delete (existing usage confirm). Tabs: All, Changes not published, Unused |
| popups/list (board Popups) | name → type · `#popup-slug`; notes under it | "When and where" (trigger → place · frequency), "Effect" (conversions + rate, a 4 px bar, views · closes) — no time window in the label (counters are all-time) | switch (existing `toggle` action) in the first column; unpublished: dashed placeholder with `title` | pencil (builder) → Settings, Reset counters (existing `reset` action and its confirm), —, Delete |
| parts/list | part → language | — | live / draft | pencil (builder) → —, Delete |
| blueprints/list | name → description | — | — | no pencil (nothing to edit); menu Apply, Download, Send to Claude, —, Remove |
| pages/translations | name → language versions | changed (`format_ago`) | — | pencil (settings) |

- [ ] Step 4: `admin/list/switch` partial (interface in Task 3) with `ListPartialsTest::testSwitchReflectsState` (`on => false` → `aria-checked="false"`, `role="switch"`, CSRF field) and its CSS (`.switch` 42 × 24 track, `--ok` on, `--line-field` off, 18 px knob, focus ring). Failing site assertions for components (`Unused` tab count, `aria-label` of the places button), popups (`role="switch"`), items (`popovertarget`). Step 5: implement; keep `ComponentsTest`'s `1×`-equivalent assertion meaningful (update it to the new "1 place" text) and its `data-confirm` text unchanged. Step 6: `dc vendor/bin/phpunit --testsuite site --filter 'Collection|Component|Popup|Part|Blueprint|Translation'` PASS. Step 7: browser check, translations, english check, commit `feat(admin): builder content lists in the new list design`, PR 3 (base PR 2).

### Task 7: Inbox, people and bookings

**Files:** `system/views/admin/enquiries/list.php`, `subscribers/list.php`, `members/list.php`, `users/list.php`, `roles/list.php`, `bookings/list.php`, `bookings/services.php`, `bookings/staff.php`; `system/src/Admin/Modules/Enquiries.php` (tab counts), `Bookings.php` (group by day in the controller); tests `tests/Site/CollectionsB/EnquiryTriageTest.php`, `tests/Site/Connections/LeadsStatisticsFormsTest.php`, members and booking site tests.

Mapping:
| View | Main cell | Other columns | Status | Actions |
|---|---|---|---|---|
| enquiries/list (board Enquiries) | unread dot (`status = 0`, `--signal`, visually hidden "Unread") + name (700 when unread) → e-mail | message: topic tag (+ attachment icon) + one-line preview; form; received (`format_ago`) | tabs Open (status 0/1), Resolved (2), All | quick ✓ "Mark as resolved" (posts the existing bulk action with one `selected[]`, `bulk=vyridit`) → Open, Personal data request, —, Delete; selection bar Mark as resolved (primary), Delete (danger, existing confirm) |
| subscribers/list | e-mail → service | subscribed (`format_ago`), page | live confirmed / hidden awaiting confirmation / error | menu only: —, Remove |
| members/list | name → e-mail | groups (tags), last sign-in | off when not confirmed | pencil → —, Remove |
| users/list (board Users) | initials avatar + name ("(you)") → e-mail | role (tag), sign-in (shield icon + Passkey / Code / off — off in warning colour), news items, last sign-in (`format_ago`) | inactive rows at 60 % opacity | pencil → Reactivate (when inactive), —, Delete |
| roles/list | role → sections | users count | — | pencil → —, Delete |
| bookings/list (board Bookings) | rows grouped by day: one `<tbody>` per day with a `<tr class="list-group"><th colspan>Today · Thu, 22 October</th></tr>` | time (strike-through when cancelled), service, person (initials), customer → e-mail | live confirmed, hidden requested ("held until …"), off cancelled | quick filled ✓ Confirm only for requests → menu Open, Decline, Cancel |
| bookings/services | service → duration · buffer · price | offered by | — | pencil → —, Delete |
| bookings/staff | person → e-mail for notifications | services | — | pencil → —, Remove |

- [ ] Step 1: failing assertions (enquiries: unread row has `class="unread"`, quick resolve form posts `bulk` = `vyridit`; bookings: `class="list-group"`; users: `status-off` for an inactive user). Step 2: FAIL. Step 3: implement (`list-group` CSS lands here). Step 4: `dc vendor/bin/phpunit --testsuite site --filter 'Enquir|Subscriber|Member|User|Role|Booking|Leads'` PASS. Step 5: browser check, translations, english check, commit `feat(admin): inbox, people and booking lists in the new list design`, PR 4 (base PR 3).

### Task 8: Tools and system lists

**Files:** `system/views/admin/redirects/list.php`, `connectors/list.php`, `settings/webhooks.php`, `settings/backups.php`, `schedules/list.php`, `addons/list.php`, `audit/list.php`, `fleet/list.php`, `facts/list.php`, `experiments/list.php`, `changelog/sessions.php`; tests `tests/Site/LinksConnectors/LinksTest.php:38,70`, `tests/Site/SiteMoveSecurity/CollectionItemPagesTest.php:148`, `tests/Site/JobsFleetFacts/FleetTest.php:118`, `FactsTest.php:150`, `tests/Site/MediaMenuMail/WebhooksTest.php:99`, `tests/Site/AgentAddons/UndoClaudeSessionTest.php:59`.

Mapping:
| View | Change |
|---|---|
| redirects/list (board Redirects) | the 404 table becomes `admin/list/attention` ("%d addresses were not found (404)", meta "last 60 days", aside = "Ignore all" form): each row old URL, hits, suggestion → target with score, filled "Redirect" (existing Create redirect form), icon "Ignore" (existing form); the "Redirect" manual link moves into the row as the suggestion-less fallback. The automatic-redirects setting becomes a checkbox in the head (`secondary`). Main list: old address (monospace) + tag "automatic" → arrow → target; hits; last (`format_ago`); tabs All / By hand / Automatic; pencil → —, Delete |
| addons/list | name → version; switch (Switch on/off); menu —, Delete its data (danger) |
| connectors/list, settings/webhooks | delivery logs: status dot (live ok / error failed) + main cell event → address; quick "Send again" for failed rows; keep `name="id" value=` markup for WebhooksTest |
| settings/backups | file → size; created (`format_ago`); pencil-less: quick Download icon → menu Restore (danger, existing confirm), Delete (danger) |
| schedules/list | schedule → when; next due, last run (`format_ago`); status from the last run |
| audit/list, fleet/list, facts/list, experiments/list, changelog/sessions | main cell + status dot from their existing state column; existing per-row actions (Undo the session …) move into the menu; `badge-error` in fleet becomes `status-error` (update FleetTest) |

- [ ] Step 1: `admin/list/attention` partial (interface in Task 3) with `ListPartialsTest::testAttentionRendersTitleAndRows` (heading id referenced by `aria-labelledby`, rows HTML kept raw, aside rendered) and its CSS (bordered box, warning dot before the title, rows 60 px). Failing site assertions (redirects: `class="attention"` with the suggestion and score text that `LinksTest` already expects; addons: `role="switch"`). Step 2: FAIL. Step 3: implement. Step 4: `dc vendor/bin/phpunit --testsuite site --filter 'Links|Redirect|Fleet|Facts|Webhook|Undo|Addon|Connector|Backup|Schedule|Audit|Experiment'` PASS. Step 5: browser check, translations, english check, commit `feat(admin): tool lists in the new list design`, PR 5 (base PR 4).

### Task 9: Clean-up and data tables

**Files:** `system/views/admin/bulk_actions.php` (delete when no view renders it), `image/admin.css` and `image/editor.css` (remove rules nothing emits any more: `tr.unpublished` inset bar, `table.listing button.navigation`, `form.center.small-text` filter bar if unused, unused `.badge-*`), the remaining `class="listing"` views that are data tables (stats, dashboard, settings/health/console/company/mail, transfer/*, media/cleanup, fleet/detail, fleet/kit, facts/claims, experiments/show, account, hours_proposed, wizard/done, collections/form, collections/item, components/form, appearance/list, changelog/list, schedules/history), `docs/screenshots/admin-*.png`.

- [ ] Step 1: `grep -rn "bulk_actions\|navigation danger\|badge-published\|tr class=\"unpublished\"" system/views/admin` — every remaining hit is either converted or a data table that keeps it on purpose; delete dead partials and CSS.
- [ ] Step 2: Walk every data-table screen in the browser (light, dark, 375 px) and fix only what the global restyle broke (numbers stay right-aligned via `td.number`).
- [ ] Step 3: `dc php tools/check-english.php`, `dc php tools/missing-translations.php de`, `dc php tools/missing-translations.php cs`, `dc composer test`, `dc composer test:browser` — all pass.
- [ ] Step 4: Refresh screenshots with `tools/screenshots.sh` (fix `tools/screenshots.mjs:38`: the login field is `username`, not `user`).
- [ ] Step 5: Commit `chore(admin): remove the old list styles and partials`, PR 6 (base PR 5).
