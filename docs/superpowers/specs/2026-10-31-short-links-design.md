# Short links — design

## Intent

A site owner prints a flyer, a poster or a business card and needs an address that fits on it and can be typed from paper:
`example.com/flyer` or `example.com/s/k7f2qm` instead of
`example.com/offers/spring-sale-2027?utm_source=flyer&utm_medium=print&utm_campaign=spring`. A QR code next to it does the same for
phones. Two things make this more than a redirect:

1. **The printed address is a promise.** Paper cannot be updated, so the short link must keep working when the content behind it
   changes: the page is renamed, the offer moves to another page, the campaign ends and the link should now lead somewhere else.
   The owner can **retarget** a short link at any time; the short part never changes.
2. **Campaign parameters stay off the paper.** UTM fields are stored on the short link and appended when it is followed, so the
   site's existing campaign statistics (`stats_campaigns`, `Front\Stats`) see "flyer / print / spring" without anyone typing it.

## Goals

- Short links to a **piece of content** (page, news item, collection item) or to a **free URL** (an address on the site or an external
  `https://` address).
- Two kinds of short part, both allowed on every link:
  - **named**, chosen by the owner, without prefix: `/flyer`;
  - **random**, generated, under the reserved prefix `s`: `/s/k7f2qm`.
- **Retarget** at any time (admin and MCP); the short part is fixed once created (deleting and recreating is the way to change it).
- A content target follows the content: a renamed slug, a moved collection item or a language prefix never breaks the link, because
  the target is stored as the content's row, not its path.
- UTM fields per short link (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`), appended on follow.
- A hit counter and the time of the last hit per short link.
- A QR code (SVG, from the existing `Core\Qr`) for every short link, downloadable from the admin.
- Created from a central admin list, and reachable from the edit screens of pages, news and collection items ("Short link" opens the
  list with that content preselected as the target).
- Everything also through MCP (project rule: whatever the admin can do).

## Non-goals

- No click analytics beyond the counter (no per-day chart, no referrers, no geo). Campaign visits are already counted by the existing
  statistics through the UTM fields.
- No custom domains for short links (`go.example.com`), no link expiry dates, no password-protected links, no A/B splits.
- No short links for media files or categories/tags in this version (a free URL covers them).
- No public API beyond MCP.

## Anti-goals (failure even if it works)

- **A printed link that breaks.** Any rule that lets a page, a collection, an add-on prefix or a later short link take over a
  short link's address, or that silently drops a short link, defeats the feature.
- **A browser that remembers the old target.** A 301 is cached by browsers indefinitely; after a retarget, returning visitors would
  still land on the old page. Short links answer **302** with `Cache-Control: no-store`.
- **An open redirect.** A short link's target is set only by signed-in people with the right or by an authenticated MCP connection;
  nothing from the request picks the target.
- **Printed codes that are hard to read.** Random codes avoid characters that are confused on paper (`0/o`, `1/l/i`).

## Behaviour

### Addresses

- Named short part: lower-case `[a-z0-9-]`, 2–60 characters, starts with a letter or digit, one path segment (no `/`).
- Random short part: `s/` + 6 characters from `23456789abcdefghjkmnpqrstuvwxyz` (31^6 ≈ 887 million), regenerated on the rare collision.
- The prefix `s` becomes a reserved address (a core route `/s/{code}`, so `Kernel::reservedSlugs()` picks it up automatically and no
  page, collection or add-on can take it). `tools/contracts/routes.json` records the new routes.
- Short links answer only on the default-language address (`/flyer`, not `/de/flyer`); a printed link has no language.
- Matching is case-insensitive in effect: the request path is lower-cased before lookup (people type `/Flyer` from paper).

### Collisions (both directions)

- Creating a **named** short link is refused when the name is a reserved address, the first segment of an add-on route, the slug of a
  page (any language, trash included — `Slug::taken` semantics), the slug of a collection, the `from_path` of an exact redirect, or
  another short link.
- Creating a **page** or **collection** whose slug equals a named short link must not take it: `Slug::taken()` for `pages` (and the
  collection slug check) also answers true for an existing named short link, so `makeUnique` moves the new page to `flyer-2` the same
  way it does for a page clash. MCP and admin refusals name the short link.
- Other address owners (design call): a name equal to any code in `Language::AVAILABLE` (`Language::TAG` shape) is refused, so a
  language switched on later never swallows it; the custom news slug setting (`Routes::setNewsSlug`) refuses a value that is a named
  short link, and a named short link refuses the current news slug; the short-link routes sit **before** `addOnRoute`, and a name
  equal to an installed add-on's prefix is refused.
- Named short links keep reserving their addresses while the extension is off (design call): switching it back on must never find a
  page sitting on a printed address.

### Following a short link

- Resolution runs as a route placed before `pageAt` (so before pages), and `/s/{code}` as its own route; GET and HEAD only, other
  methods fall through. One indexed lookup by `path`.
- Target URL:
  - content target: the content's current public address (as the site prints it, with its language prefix);
  - URL target: a site path (`offers/spring`) through `$app->url()`, or the stored `https://` address as is.
- UTM fields are merged into the target's query string; a parameter already in a URL target's query is kept and the stored field
  overrides it (the short link is the more specific statement).
- Answer: 302, `Cache-Control: no-store`, `X-Robots-Tag: noindex`. Never stored in `Front\Cache`.
- Counted: `hits + 1`, `last_hit_at = now` (same as redirects today).
- **Unreachable content target** (trashed, hidden/draft, or the row is gone): the visitor goes to the home page
  with a 302 instead of a 404 — the flyer is out there; a landing on the site beats an error. The admin list marks such links
  ("Target not public") and so does `site_audit` (a new kind `short_link`), so the owner retargets them.
- **Gated content** (member groups) is reachable (design call): the link goes to the page's own address, where `Kernel::gate()` shows
  the sign-in. Not flagged.
- **Collection items** (design call): a hidden item whose collection has `hidden_redirect` goes to the item's own address, so the
  collection's rule applies (not flagged); an item without an address (collection `detail` off) is not offered by the picker and is
  refused by MCP; if `detail` is switched off later, the link counts as unreachable (home page, flagged).
- When the short-links extension is off, the routes answer 404 (data stays).

### Retargeting

- Admin edit form and MCP `save_short_link` change the target (content or URL) and the UTM fields; the short part is read-only after
  creation.
- A change log row (`change_log`, like other admin writes) records who retargeted what, from where to where.

## Data model

New table `tl_short_links` (Phinx migration, table DSL; site-scoped, i.e. not an owner table in multisite):

| column | type | notes |
|---|---|---|
| `short_link_id` | int, identity | internal key |
| `public_id` | UUID v4 | `Db::PUBLIC_ID_TABLES` |
| `path` | varchar(70), unique, lookup collation | `flyer` or `s/k7f2qm` |
| `target_type` | varchar(10) | `page` \| `news` \| `item` \| `url` |
| `target_id` | int, null | `page_id` / `news_id` / `item_id` by type |
| `target_url` | varchar(2000), null | for `url`: a site path or `https://…` |
| `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` | varchar(80), default '' | same 80-char cap as `Forms::campaign` |
| `label` | varchar(120), default '' | the owner's note ("Spring flyer 2027") |
| `hits` | int unsigned, default 0 | |
| `last_hit_at` | datetime, null | |
| `created_at` | datetime | |

Deleting content does not delete its short links (the link falls back to the home page and is flagged).

## Extension

A new built-in extension `short_links` in `Extensions::CATALOG` ("Short links": short addresses and QR codes for print, with campaign
parameters), **on by default**. Module and routes are tied to it.

## Admin

- Module `Modules\ShortLinks` (group "Search engines", next to Redirects; editors and administrators — it is content work, not system
  configuration; per-section permission like other modules).
- List: short address (with copy button), label, target (title of the content or the URL), UTM summary, hits, last hit, a warning
  badge when the target is not public; row actions edit, QR (SVG download `qr-<path>.svg`), delete (`data-confirm`).
- Create/edit form: short part — radio "random" (default) or "name" with a text field; target — content picker (search over pages,
  news, collection items by title, the same picker the builder's link field uses if one fits) or URL; the five UTM fields (source,
  medium and campaign visible; term and content under `<details>`); label.
- Edit screens of pages, news and collection items: a "Short link" link to the module with `?target=page:<public_id>` (or `news:`,
  `item:`) that opens the create form with the target filled, and lists that content's existing short links.
- No inline scripts; copy button and picker behaviour in `image/admin.js` via `data-` attributes. All UI text through `t()` with the
  eight translations.

## MCP

- `list_short_links` (read): all short links with `id`, `url` (full short address), `path`, target (`{type, id}` with the content's
  public id, or `{type: "url", url}`), UTM fields, label, hits, `last_hit_at`, `target_public` (bool).
- `save_short_link` (write): create (no `id`; `name` optional — absent = random) or update (`id`: target, UTM, label). Returns the
  short link as listed plus `qr_svg`.
- `delete_short_link` (destructive).
- Permission (design call): the same right as the admin module (editors and administrators). A **drafts-only** connection
  (`Auth::draftsOnly()`) may list but not create, retarget or delete: a short link has no draft state and a retarget is live at once.
- Contract `tools/contracts/mcp-tools.json`, `Mcp\Catalog` access classes, `Mcp\PublicIds` mapping for target ids,
  `ParityTest::$parity` entries for every admin action.

## Export, import, multisite

- `SiteExport` writes `short_links` with content targets as public ids; `SiteImport` maps them back (and refuses invalid rows through
  the same validator the admin uses). Add to `SiteImport::TABLES` and the export readme text.
- Multisite: an ordinary site table (per-site prefix); nothing owner-wide. `OwnerClone`/transfer copy it with the other site tables.

## Tests

- Unit: random code alphabet/length, name validation, UTM merge into a URL with and without an existing query.
- Site test (HTTP): named and random link 302 to a page with UTM in the `Location`; retarget changes `Location`; renaming the page's
  slug keeps the link working; trashed target → home page; `/s/` and a named link not shadowed by a later page with the same slug
  (page gets `-2`); POST falls through; extension off → 404; hits counted.
- MCP parity entries and contract regenerated (`php tools/contracts.php`).
- `php tools/check-english.php` and `php tools/sql-dialect-scan.php` clean; site suite also on PostgreSQL and SQLite.

## Left to the builder

- Exact admin layout, wording of messages (English source, translated with `tools/add-translations.py`).
- Whether the content picker reuses an existing admin picker or is a simple grouped `<select>` with a search field.
- Where the content-to-address resolution lives (reuse an existing resolver if one exists, e.g. what `Mcp\BuildTargets` or the
  sitemap uses; otherwise a small `Core\ShortLinks::targetUrl()`).
