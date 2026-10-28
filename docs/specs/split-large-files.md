# Splitting the three largest files

Status: proposed (issues #228 Kernel, #229 builder.js, #230 Build). Three files carry a disproportionate share of the product and are hard to edit safely, for people and for AI agents:

| File | Lines | What it is |
|---|---|---|
| `system/src/Front/Kernel.php` | 1716 | every public address: routing, pages, news, collections, machine endpoints, the page frame |
| `image/builder.js` | 2216 | the whole visual editor in one closure |
| `system/src/Builder/Build.php` | 1077 | the layout validator, the code filter, the renderer and the element schema |

The goal is files of roughly 200–700 lines, each with one job a reader can name. This is a move, not a rewrite: **no behaviour change, no new
abstraction** (no interfaces, no router table, no event framework, no build step). Each part is one PR; the three are independent.

## Rules for every part

- **Move code, don't rewrite it.** Method bodies move as they are; only `$this->x` / `self::x` references are rewired. A cleanup spotted on the
  way is its own commit after the move (or a follow-up issue), so the move diff stays reviewable with `git diff --color-moved`.
- **Callers follow the code.** When a public static method moves, its callers are updated in the same PR; no forwarding stubs, except the two
  entry points named in the project rules (`Build::sanitize()`, `Build::render()`), which stay where `CLAUDE.md` and
  `docs/specs/visual-compose.md` say they are.
- **Docs follow the code.** `CLAUDE.md` and specs that name a moved method are updated in the same PR.
- **Done means:** `composer test` green (unit, integration, site), `composer test:browser` green for the builder part, `vendor/bin/phpstan`
  without new baseline entries, `php tools/contracts.php` unchanged output, `php tools/check-english.php` and `php tools/sql-dialect-scan.php`
  clean.

## 1. `Front\Kernel` → four collaborators

`Kernel::handle()` is a 320-line chain of `if ($path === …)` checks whose **order is behaviour**: the early add-on hook, redirects, the demo
and import guards, OAuth, maintenance, the WordPress `?p=` lookup, staged media and the page cache all run before routing; pages are looked up
before the two-segment collection pattern; add-on routes run before `/form`. The split keeps that order exactly and moves the bodies out.

| New class | Takes from `Kernel` | Lines (approx.) |
|---|---|---|
| `Front\Endpoints` | the addresses that answer without the page frame: `/manifest.webmanifest`, `/favicon.ico`, `/og/<hash>.png`, `/robots.txt`, `/sitemap.xml`, `/feed.json`, `/.well-known/security.txt`, `/llms.txt`, the IndexNow key file, `/consent`, `/popup`, `/fleet/*`, `/vitals`, `/conversion`, `/experiment`, `/download/<token>`, `/subscribe`, `/_booking/*`, `/health`, `/tasks`, `/screen/<id>`, `/status.json` (`handle()` blocks ~84–272) | 250 |
| `Front\NewsPages` | `showNewsList`, `category`, `tag`, `newsItem`, `rss`, `search`, `markdownVersion`, `listVariables` | 230 |
| `Front\CollectionPages` | `showCollectionItem`, `compareProducts`, `calendarFile`, `testimonialPage` | 230 |
| `Front\PageFrame` | `page`, `siteParts`, `popups`, `menu`, `menuPages`, `breadcrumbs`, `languages`, `editInPlace`, `localizeSystemLinks`, `previewBar`, `context`, and the `gated` flag that `page()` reads | 450 |

`Kernel` keeps the constructor, `handle()` as the ordered dispatch, `home`/`showPage`, the previews (`previewSection`, `previewComponent`,
`previewPopup`, `startSitePreview`, `wantsDraft`, `canSeeDraft`), the member gate (`gate`, `gatedPage`, `memberArea`), the not-found path
(`redirectRule`, `notFound`, `followRule`, `notFoundPage`), `maintenance`, `slashRedirect` and `descriptionFrom`: about 650 lines.

Wiring, plainly: the constructor builds one `PageFrame` (it needs `App`, the `View`, the `NewsRepository` and whether a site preview runs) and
passes it to `NewsPages` and `CollectionPages`, which return `Response`s through `$frame->page(…)`. `Endpoints` gets only `App` and has one
public method, `answer(string $path): ?Response` (null = not mine). `handle()` then reads as a list:

```php
if (($answer = $this->endpoints->answer($path)) !== null) { return $answer; }
```

at the point where the first of those blocks stands today; the blocks that sit between endpoint checks (`/mcp`, member area, add-on routes,
`/form`) stay in `handle()` in their places, so the order does not change. If keeping the order forces `Endpoints` into two calls (before and
after the add-on routes), make it two methods with names that say which group they answer, not a flag.

Guards: `tests/Site/PublicSiteTest.php` and the site suites cover the addresses; add a unit test only for something the move had to touch
beyond rewiring.

## 2. `image/builder.js` → the editor core and five parts

The pattern already exists: `builder-overlay.js`, `builder-handles.js`, `builder-keys.js` and `builder-compose.js` are separate `defer` scripts
that reach the editor only through `window.taleaBuilder`. The split extends that to the editor's own panels. The file's `/* ---------- … */`
section markers are the cut lines.

| File | Takes (section of today's `builder.js`) | Lines (approx.) |
|---|---|---|
| `builder.js` (core) | helpers, tree, changes/history/saving, selection and tree edits, keys, start; owns `state` and exposes `window.taleaBuilder` | 650 |
| `builder-canvas.js` | canvas (iframe swap, preview sizing) and dragging on the canvas | 280 |
| `builder-clipboard.js` | clipboard (also between pages and sites) | 110 |
| `builder-bar.js` | top bar (breakpoints, zoom, versions, publish, tour) | 320 |
| `builder-left.js` | left panel: Add and Structure | 250 |
| `builder-inspector.js` | right panel: properties, advanced (tag, classes, anchor), editing a shared class | 500 |
| `builder-style.js` | style editing of an element and a class | 180 |

How the parts meet:

- `builder.js` runs first (script order in `system/views/admin/pages/builder.php`) and sets `window.taleaBuilder` at the end of its own run, as
  today. Each part reads `const B = window.taleaBuilder` and adds what other parts call to it (`B.redrawLeft = …`), the way the compose scripts
  already do. One object, no second global.
- The start block (`createBar()`, building the panels, the first `redraw()`, `refreshPreview()`) moves into a `DOMContentLoaded` listener in
  `builder.js`. `defer` scripts all run before `DOMContentLoaded`, so every part has registered by then; no load-order flag is needed.
- The first step of the PR is a list of every cross-section call (which section calls which function of another). Each one becomes either a
  `B.` call or, where several parts react to the same thing, the existing `on()`/`emit()` pair (`emit('redraw')`). Nothing new beyond that.
- Shared helpers that today sit inside a panel's section but are used everywhere move to the core, not to that panel: `setState` (top bar),
  `askName`, `redraw` and `redrawPanels` (right panel).
- Closure state that more than one part writes (`preview`, `left`, `right`, `frame2`, `rail`) moves onto `B` or into `state`; read-only
  constants (`D`, `T`, `TYPY`, `STYLE`, `BP`, `ICONS`) are already on `B` or go there.
- `builder-overlay.js` and the other compose scripts keep working unchanged: the names they use on `window.taleaBuilder` keep their meaning.

Admin CSP stays `script-src 'self'`; every new file is listed in `builder.php` with the `?v=` cache key. `tests/Unit/ScriptsTest.php` must stay
green (every `[data-…]` a part looks for is still rendered). `composer test:browser` walks the builder and is the main guard here.

## 3. `Builder\Build` → validator stays, three parts move out

`Build` is the one validator and the one renderer (`CLAUDE.md`). That stays true of its public face; the code behind three of its jobs moves.

| New class | Takes from `Build` | Callers to update | Lines (approx.) |
|---|---|---|---|
| `Builder\CodeFilter` | `code`, `codePass`, `cssMatches`, `cssViews`, `cssPlain`, `codeNode` (the filter of the Code element's HTML and CSS) | 3 files | 140 |
| `Builder\Renderer` | `html`, `renderChildren`, `renderElement`, `meetsConditions`, `customAttributes`, `customAttributesLast`, `fillItem`, `css` | ~10 files | 240 |
| `Builder\ElementSchema` | `schema`, `overview`, `fieldDefinitions`, `fieldLine`, `buildSchema`, `disabledTypes` (what MCP, the editor and the contracts read) | ~17 files | 230 |

`Build` keeps `ELEMENTS`, `className`, `newId`, `fresh`, the whole sanitizer (`sanitize`, `sanitizeChildren`, `sanitizeConditions`,
`conditionsBypassCache`, `sanitizeContent`, `inline`, `link`), `elementsOfType`, JSON in and out, `asText`, `fromText`, `compact`, `texts`, and
`render()` as a one-line call into `Renderer` so that "one renderer `Build::render()`" stays a true sentence. About 470 lines.

The sanitizer is deliberately **not** split: "never store a build without `Build::sanitize()`" is the rule that keeps MCP, the editor and the
importers safe, and keeping it whole in one file keeps that rule easy to check. `ElementSchema` (not `Schema`) avoids confusion with
`CollectionSchema` and `StructuredData`, which are about schema.org.

`CLAUDE.md` mentions `Build::css` in the right-to-left section; it becomes `Renderer::css`. `php tools/contracts.php` must produce the same
`tools/contracts/builder-elements.json`.

## Not in scope

- Other files over 1,000 lines (`Core\Booking`, `Core\WpImport`, `Core\SiteImport`, `image/admin.css`, the dictionaries). The dictionaries are
  data and stay whole; the others get their own issue if they keep growing.
- Renaming public URLs, MCP tools or settings. Nothing outside PHP and JS identifiers changes.
