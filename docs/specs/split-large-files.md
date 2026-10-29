# Splitting the three largest files

Status: decided 2026-10-29. Issues: #232 (tests first), #230 (`Build`), #228 (public router), #229 (builder modules).

Three files carry a disproportionate share of the product and are hard to edit safely, for people and for AI agents:

| File | Lines | What it is |
|---|---|---|
| `system/src/Front/Kernel.php` | 1716 | every public address: routing, pages, news, collections, machine endpoints, the page frame |
| `image/builder.js` | 2216 | the whole visual editor in one closure |
| `system/src/Builder/Build.php` | 1077 | the layout validator, the code filter, the renderer and the element schema |

The goal is files of roughly 200–700 lines, each with one job a reader can name, and a check that keeps them there.

## Order: one stack of PRs

Each PR is based on the one below it and is implemented by an agent once the one below is green.

1. this spec;
2. **tests first** (#232): the missing site and builder tests, and the file-size check;
3. **`Builder\Build`** (#230);
4. **public router** (#228);
5. **builder as ES modules** (#229).

## Rules for every part

- **Move code, don't rewrite it.** Method bodies move as they are; only `$this->x` / `self::x` references are rewired. A cleanup spotted on the
  way is its own commit after the move, so the move stays reviewable with `git diff --color-moved`. The one deliberate behaviour change
  (section 3, add-on routes) is its own commit with its own test.
- **Callers follow the code.** When a public static method moves, its callers are updated in the same PR; no forwarding stubs, except
  `Build::render()` (section 2).
- **Docs follow the code.** `CLAUDE.md`, `docs/EXTENSIONS.md` and specs that name a moved method are updated in the same PR.
- **Done means:** the unit suite and PHPStan (no new baseline entries) pass locally, `php tools/contracts.php` reports no unexpected change,
  `php tools/check-english.php` and `php tools/sql-dialect-scan.php` are clean; the site and browser suites run once in CI on the top of the
  stack.

## 1. Tests first

The moves are only as safe as the tests that cover the moved code. Before any move:

- **Public addresses nobody requests today:** the IndexNow key file (`/<indexnow_key>.txt`), `POST /consent`, `POST /fleet/unpair`,
  `/status.json` (with and without the health token), and maintenance mode (setting `maintenance`): a visitor gets 503 with `Retry-After`, while
  `/mcp`, `/health` and a signed-in user still get through.
- **Order:** `/tasks` still answers while a Talea import runs (`/health` is already tested); a page cannot take a system address.
- **Builder, untested today** (`tools/test-browser.mjs`): changing a Style-panel value, dragging an element from the Add panel onto the canvas,
  double-click text editing on the canvas, the "Paste from another Talea site" dialog, Ctrl+D (duplicate) and Delete.
- **File-size check** `tests/Unit/FileSizeTest.php`: every file in `system/src/**/*.php` and `image/**/*.js` stays under 900 lines. Files above
  that today are listed with their current line count as their ceiling (they may shrink, never grow); a split lowers or removes their entry.
  The dictionaries (`system/languages`, `image/languages`) are data and are not checked.

## 2. `Builder\Build`: validator stays, three parts move out

`Build` is the one validator and the one renderer (`CLAUDE.md`). That stays true of its public face; the code behind three of its jobs moves.

| New class | Takes from `Build` |
|---|---|
| `Builder\CodeFilter` | `code`, `codePass`, `cssMatches`, `cssViews`, `cssPlain`, `codeNode` (the filter of the Code element's HTML and CSS) |
| `Builder\Renderer` | `render` (body), `html`, `renderChildren`, `renderElement`, `meetsConditions`, `customAttributes`, `customAttributesLast`, `fillItem`, `css` |
| `Builder\ElementSchema` | `schema`, `overview`, `fieldDefinitions`, `fieldLine`, `buildSchema`, `disabledTypes` (what MCP, the editor and the contracts read) |

`Build` keeps `ELEMENTS`, `className`, `newId`, `fresh`, the whole sanitizer (`sanitize`, `sanitizeChildren`, `sanitizeConditions`,
`conditionsBypassCache`, `sanitizeContent`, `inline`, `link`), `elementsOfType`, JSON in and out, `asText`, `fromText`, `compact`, `texts`, and
`render()` as a one-line call into `Renderer`, so "one validator, one renderer, both on `Build`" stays a true sentence. About 470 lines.

The sanitizer is deliberately not split: "never store a build without `Build::sanitize()`" keeps MCP, the editor and the importers safe, and
keeping it whole in one file keeps that rule easy to check. `ElementSchema` (not `Schema`) avoids confusion with `CollectionSchema` and
`StructuredData`, which are about schema.org. `CLAUDE.md`'s right-to-left section names `Build::css`; it becomes `Renderer::css`.
`php tools/contracts.php` must produce the same `tools/contracts/builder-elements.json`.

## 3. Public router: `Front\Kernel` becomes a router and four collaborators

Today `Kernel::handle()` is a 320-line chain of `if ($path === …)` checks whose order is behaviour. It becomes a route table.

### The router

`Front\Router`, a small class of our own (no package; the product stays framework-free):

- a route is a method (`GET`, `POST` or both), a pattern with placeholders (`/news/{slug}`, `/_booking/cancel/{token}`; each placeholder has a
  regex, default `[a-z0-9-]+`), an optional `when` condition (a setting or an extension switched on: `fn (): bool`) and a handler
  `[$object, 'method']` that gets the placeholders as arguments and returns a `Response`, or `null` to let the next route try;
- routes are tried **in the order they are listed, the first match wins**: the list in `Kernel` reads top to bottom in today's order, so the
  move can be checked line by line against the old chain;
- `Router::routes()` returns the table as data (method, pattern, condition name, handler name) for the contract and for reserved addresses.

Scope: the public site only. The admin keeps its `/admin/<module>/<action>/<id>` convention; `/mcp` and OAuth are single routes in the table.

### Before routing

The checks that run before any route stay a fixed list of guard methods in `Kernel`, called in today's order: the add-on early hook, the
language/system redirects, the demo guard (`/mcp`, OAuth), the import guard (503 except `/health`, `/tasks`), OAuth, maintenance, the old
WordPress `?p=` / `?lang=` lookup, staged media, the page cache. No middleware pipeline.

### The collaborators

| New class | Takes from `Kernel` |
|---|---|
| `Front\PageFrame` | `page`, `siteParts`, `popups`, `menu`, `menuPages`, `breadcrumbs`, `languages`, `editInPlace`, `localizeSystemLinks`, `previewBar`, `context`, and the `gated` flag that `page()` reads |
| `Front\Endpoints` | the addresses that answer without the page frame: manifest, favicon, share images, robots, sitemap, feeds, security.txt, llms.txt, the IndexNow key, consent, popup, fleet, vitals, conversion, experiment, download, subscribe, bookings, health, tasks, screen, status.json |
| `Front\NewsPages` | `showNewsList`, `category`, `tag`, `newsItem`, `rss`, `search`, `markdownVersion`, `listVariables` |
| `Front\CollectionPages` | `showCollectionItem`, `compareProducts`, `calendarFile`, `testimonialPage` |

`Kernel` keeps the constructor, the guards, the route table, home and pages, the previews (`previewSection`, `previewComponent`,
`previewPopup`, `startSitePreview`, `wantsDraft`, `canSeeDraft`), the member gate (`gate`, `gatedPage`, `memberArea`), the not-found path
(`redirectRule`, `notFound`, `followRule`, `notFoundPage`), `maintenance`, `slashRedirect` and `descriptionFrom`. The constructor builds one
`PageFrame` and passes it to `NewsPages` and `CollectionPages`, which answer through `$frame->page(…)`.

### Add-on routes: core addresses always win (behaviour change, own commit)

Today an add-on route (`Api::route()`, `Registry::runRoute`) runs after part of the chain, so an add-on prefix can take `/health`, `/tasks`,
`/form`, `/screen/…`, a page slug or a collection's address. From now on:

- the add-on routes run after every core route of the table and before pages and collections;
- `Api::route()` refuses a prefix that is the first segment of a core route or a reserved slug, the same way it refuses a prefix another add-on
  already owns today (no new extension API version);
- `Modules\Pages::RESERVED_SLUGS` is computed from the route table, which also reserves the missing `fleet`, `experiment` and `oauth`;
- `docs/EXTENSIONS.md` and `tools/contracts/extension-api.json` say which prefixes are taken.

### The contract

`php tools/contracts.php` writes `tools/contracts/routes.json` (method, pattern, condition, handler per route, in order), so any change to the
public addresses shows up as a diff in review.

## 4. Builder as ES modules

All builder scripts become native ES modules (`<script type="module">`): no build step, explicit `import`/`export` instead of a shared global.
`script-src 'self'` allows them.

### Layout

`image/builder/`, plain `.js` files (no `.mjs`, which would need MIME and cache rules in three servers):

| Module | Takes |
|---|---|
| `main.js` | the entry: imports the parts, builds the panels, first draw, start-up listeners; sets `window.taleaBuilder` for the browser test only |
| `core.js` | state, helpers, tree, changes/history/saving, selection and tree edits, keys, and the helpers every part uses (`setState`, `askName`, `redraw`, `redrawPanels`, `on`/`emit`) |
| `canvas.js` | canvas (iframe swap, preview sizing), dragging on the canvas, clipboard |
| `panels.js` | top bar (with the tour) and left panel (Add, Structure) |
| `inspector.js` | right panel: properties, advanced (tag, classes, anchor), shared class editing, style editing |
| `overlay.js`, `handles.js`, `keys.js`, `compose.js`, `structured.js` | today's `builder-overlay.js` … `builder-structured.js`, importing from `core.js` instead of reading `window.taleaBuilder` |

`main.js` is separate from `core.js` so that the parts can import `core.js` without importing each other in a circle. The editor CSS
(`builder.css`) does not move.

### Rules

- `window.taleaBuilder` stays only as a documented handle for `tools/test-browser.mjs` (34 uses); no module reads it.
- **Cache:** imports carry no `?v=` key, and JS is cached for 30 days to a year, so a new `main.js` could meet stale cached parts. `image/builder/*`
  is therefore served with `Cache-Control: no-cache` (revalidated with ETag) in `docker/Caddyfile`, `system/nginx.example.conf` and `.htaccess`.
  `builder.php` loads only `main.js?v=<version>`.
- The lists that name builder scripts follow the new paths: `tests/Unit/ScriptsTest.php` (glob `image/**/*.js`), `tests/Unit/TranslationsTest.php`,
  `tools/check-english.php`, `tools/missing-translations.php`.
- Every other admin script stays a classic script.

## Not in scope

- Other files over 900 lines (`Core\Booking`, `Core\WpImport`, `Core\SiteImport` and others the check lists): they get a ceiling in the file-size
  check and their own issue when they need one.
- Renaming public URLs, MCP tools or settings.
- Routing the admin through the router.
