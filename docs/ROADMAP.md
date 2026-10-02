# Kaleta roadmap

What is planned next. Dates are not promised; releases ship when they are tested.

## 1.2 – languages for the EU and beyond (released 25 September 2026)

- Around forty site languages; visitor texts translated into Czech, English, German, French, Spanish, Italian, Polish and
  Slovak, other languages fall back to English with dates in their own format.
- System addresses in the site language (`/news`, `/search`), old addresses redirect permanently.
- Site language chosen in the installer; imprint (Impressum) template and company fields.
- Language switcher and light / dark / device-based look with a switcher for visitors.
- Admin: distinct menu icons, chart tooltips, GitHub Sponsors link in the footer.
- 1.2.1: related content on item pages (a Collection list filtered by the shown item's field, without the item itself).
- 1.2.2: MCP accepts objects and arrays sent as JSON text (item values and builds were dropped or refused); breadcrumbs
  mark only the current page.
- 1.2.3: MCP reports unknown parameters and never resets the menu on unreadable items; Escape closes submenus.
- 1.2.4: tidy submenus and a centred mega menu panel; Escape works on every page with a submenu.
- 1.3.1: the mobile menu scrolls when it is taller than the screen; an automatic pop-up waits until the visitor closes the menu.
- 1.3.2: collections in several languages – a translated item keeps its address, a detail template per language, breadcrumbs to the translated section page; builder canvases without site pop-ups.
- 1.3.3: translating with Claude – a page translation starts as a copy of the original build, texts only for translation, the header and footer of a new language start as a copy.
- 1.3.4: a language is offered to visitors (switcher, hreflang, sitemap) only once its home page is published; MCP reads "true"/"false" sent as text correctly and reports collection item keys the collection does not have.
- 1.3.5: reading a site part over MCP creates nothing, the page list shows language addresses, and the template header loads the logo on language versions.
- 1.3.6 (security release): {{field}} values in Custom HTML are escaped, MCP tools check the user's sections, fixes from an admin review (admin language, news addresses, pop-up settings, spacing).
- 1.3.7: Language switcher element (for example in the footer), first visit in the browser's language, language filters in admin lists.

## 1.3 – pop-ups, newsletter services and a clearer Site appearance (released 26 September 2026)

### Pop-up builder

Today a pop-up is an element inside one page. 1.3 turns pop-ups into site-wide pieces built in the builder, like the
header and footer:

- **Types:** centred modal, slide-in from a corner, top or bottom bar, full screen.
- **Triggers:** after N seconds, after scrolling N %, exit intent, click on a link or button (`#popup-name`), inactivity,
  after N page views in a visit.
- **Where it shows:** all pages, selected pages, collection item pages or news, a language version, device
  (desktop / phone), date range, visitors coming from a campaign (`utm_*`) or a referring site.
- **How often:** once per visit, once per N days, until closed, never again after a form in it was sent – remembered
  in the visitor's browser, no cookies.
- **Library:** ready-made pop-ups – newsletter sign-up, lead magnet with a form, announcement bar, discount, event.
- **Accessibility:** focus kept inside, Esc closes, reduced motion respected, no pop-up covers the cookie bar.
- **Results:** views, closes and conversions (form sent) per pop-up, counted cookie-free like the site statistics.
- Claude can create and change pop-ups over MCP like other site parts.

### Site appearance, clearer

- One set of words everywhere: a **starter site** is content plus a style (chosen at installation), a **style** is a
  ready set of colours, fonts and corner radius, a **theme** is only the legacy custom PHP theme.
- Tabs instead of one long form: Style, Colours and readability, Fonts and sizes, Shapes, Dark mode, Brand, Import
  and export (W3C design tokens), with the live preview kept.
- No more styles are added on their own – a custom look is made in the design system or by Claude from the brand;
  a new style comes only together with a new starter site.

### Newsletter

Two steps: the first in 1.3, the second in 1.5.

1. **Subscribers sent to the mailing service the site already uses**. After the double opt-in the address goes to
   Brevo, MailerLite, Mailchimp, Ecomail or SmartEmailing (API key and list in the admin), or to any service through the
   existing webhook (Make, Zapier). Unsubscribing in Kaleta removes the address there too. Deliverability, bounces and
   spam rules stay with the specialist service.
2. **1.5 – a minimal built-in mailing for small lists** – “send the latest news to subscribers”, see 1.5 below.

## 1.4 – English identifiers in the code base (1.4.0 and 1.4.1 released 28 September 2026)

The code moves from Czech names to English so contributors can read it. Nothing changes for sites: stored data, build
JSON, CSS hooks of the public site, public templates, MCP tools and old admin links keep working. Terms and the list of
what stays are in [docs/glossary.md](glossary.md).

Done in 1.4.0:

1. Preparation: the glossary, `tools/rename.php` (renames by PHP tokens, refuses name collisions), old class names as
   aliases, `tools/test-update.sh` (every change is tested as an update from the previous release) and a browser test.
2. Tools and tests.
3. PHP classes, functions, constants and variables (`Kaleta\Builder`, `Admin\Modules`…); release packages carry the
   previous release's class files for the update request.
4. Admin and installer templates, admin and site scripts (`tools/rename-js.mjs`).
5. Admin URLs `admin.php?module=pages&action=edit`, old URLs redirected, permissions migrated.

Done in 1.4.1:

6. Settings keys in English (migration 0026; old keys still accepted until 2.0).
7. UI source texts in English, Czech moved to a dictionary like the other languages; code comments in English.

Next, in 1.4.x:

8. Admin CSS classes and `data-*` attributes.

## Direction after 1.4

Decided by the owner on 28 September 2026:

- **Claude over MCP is the main way to work with a site.** Most of what the admin does – create, edit and delete – has to
  work over MCP too. Enquiries stay readable over MCP.
- **Themeless:** the look of a site comes only from the design system, shared classes, components and site parts. No
  PHP themes and no custom layouts.
- **Newsletters follow the design system** instead of being built in the builder.
- **Safe redesigns:** site-wide look changes get drafts, like pages.

Order: first make the MCP path complete, then make look changes safe, then make the site portable, then make it
findable, then remove what is left of the old ways. Each step uses the previous one.

## 1.5 – newsletter mailing (released 28 September 2026)

- **One newsletter template, styled by the design system** – colours, fonts, logo and corner radius come from the site's
  tokens; no e-mail builder. The newsletter has a subject, an intro text, the latest (or chosen) news items, a button and
  the company footer. One fixed renderer writes table-based HTML with inline styles and a plain-text part.
- Preview, test e-mail to yourself, send now or scheduled. Sending in batches through the mail queue, only through an
  SMTP relay set in Settings (Brevo, Amazon SES, Mailgun…); without a relay the feature stays off.
- Background jobs run on visits, so a low-traffic site would stall a send: sending is refused unless the cron (`/ulohy`)
  ran recently, and Health shows its last run.
- One-click unsubscribe (`List-Unsubscribe`, RFC 8058), no open tracking; a send log with the date and the count only.
- Claude: `draft_newsletter` and `send_test_newsletter`; the real send only on an explicit request and with the publish
  permission, like publishing.

## 1.6 – Claude can do most of what the admin does (released 28 September 2026)

1. **Delete and restore over MCP:** news, collection items, collections, categories, pop-ups, components, saved
   sections and media; restore a page, news item or collection item from the trash; mark an enquiry handled or delete it.
   Collection items get a trash like pages. Destructive tools run only on an explicit request.
2. **Components and saved sections:** `list_components`, `save_component`; sections saved by people in the admin show
   up in `builder_schema` and `insert_section`. `update_category`.
3. **Enquiries stay available to Claude:** every read is written to the change log, and the /claude page, the FAQ, the
   guide and the privacy policy template say so.
4. **Context for Claude:** `site_info` returns the extensions, the languages (with their published state) and the cron
   state; every tool carries MCP annotations (read-only, destructive, idempotent), so clients know what to confirm.
5. **English build vocabulary over MCP:** build JSON keys and element types are English at the MCP boundary (`type`,
   `content`, `style`, `children`, `form`…). Input accepts both the English and the Czech form, output is English.
   Stored builds do not change.
6. **Parity guard:** `tools/test.sh` drives MCP by the English names, and a unit test fails when an admin write action
   has neither an MCP tool nor an explicit “admin only” entry (users, roles, keys, updates and backups stay admin only).
7. **Themeless:** custom PHP layouts are removed – no site uses one. Front templates are no longer overridable, the
   layout choice and `site_info.sablona` go away. Health warns about a custom layout folder that is still there.

## 1.7 – safe redesigns (released 28 September 2026)

1. The design system, shared classes, components, the menu and header or footer variants get a **draft**, with publish,
   discard and the last 20 versions, like page builds.
2. **One signed preview of the whole site** with the draft look and all page drafts.
3. MCP look tools write to the draft by default; `publish_look` and `discard_look`. Site appearance in the admin gets the
   same Publish button.
4. The change log shows look changes before and after; the previous look is restored with one click.
5. **Ready-made templates of site parts:** four headers, four footers and plain or extended wrappers – structure only,
   the look comes from the design system; they go into the part's draft (admin and `apply_part_template`).

## 1.8 – own and move your site (released 28 September 2026)

1. **Import of a Kaleta export:** “Start from an export” in the installer and Transfer → Import on an empty site; builds
   go through the same sanitising as any build, users and secrets are never carried. It serves host moves and agency
   starter kits at once.
2. **Backups include media:** an incremental media copy to the same FTPS or S3 target, a daily database backup when
   content changed, and one restore guide for both.
3. Page export carries its classes and components.
4. Signed webhooks (HMAC header) and a delivery log with retries.
5. The public REST API is marked deprecated: MCP is the way to integrate, webhooks carry events out, and the site export
   is the way out.

## 1.9 – findable: SEO and collections as landing pages (released 28 September 2026)

1. **Collection items as full pages:** SEO title, description, noindex, share image, versions and scheduled visibility.
2. **Site audit** in the admin and over MCP (`site_audit`): broken links across pages, builds, the menu and items, missing
   descriptions, duplicate titles, hidden pages in the menu, alt texts, the most frequent 404s.
3. Structured data per collection: Service, Person, Product/Offer, Event or FAQ.
4. The privacy policy template is generated from the enabled features – still a template with a disclaimer.
5. **Deprecation report in Health:** old class names, settings keys or helpers in custom code, pages using the old
   per-page pop-up element – a full release to react before 2.0.

## 2.0 – one clear system (released 29 September 2026)

Not new features – everything that exists is one English, builder-based system.

1. **Compatibility layers removed:** old class names, old admin URLs, old settings keys and old template helpers. The MCP
   `update_settings` keeps accepting old keys, so no connection breaks.
2. **One pop-up system:** per-page pop-up elements move to site pop-ups with a click trigger, then the element goes.
3. **The public REST API is removed** (deprecated in 1.8).
4. The hidden Czech MCP tool names stay – connections never break.

## Direction after 2.0

Decided on 29 September 2026 after an evaluation of the product and the market: Kaleta does what it set out to do, but
nobody outside kaletacms.com uses it yet, and an MCP server alone no longer sets a CMS apart. The next releases earn trust
first, then make "Claude runs your site, safely" the product, then prove business value, then serve agencies. About one
minor release a month; the [release policy](RELEASE-POLICY.md) says what stays compatible.

## 2.1 – dependable (released 29 September 2026)

1. **Release and support policy** ([RELEASE-POLICY.md](RELEASE-POLICY.md)): monthly minor releases, one-step updates from
   any version, at least two minor releases and six months between deprecation and removal, removals only in a major.
2. **Recorded public contracts** (`tools/contracts`): MCP tools and parameters, design tokens and builder elements; the
   tests fail when any of them is removed or changed.
3. **English design token names** (`--ka-color-primary`, `--ka-space-m`…) next to the stored ones.
4. **Output budget in CI** (`tools/test-lighthouse.sh`): every starter site scores 99–100 in Lighthouse. Pages without
   their own description get one from their first longer paragraph; lazy images use `sizes="auto"`.
5. **MCP layer rebuilt** on one catalog (`Mcp\Catalog`) with one method per tool; PHPStan in CI.
6. **Docker image** (`ghcr.io/phprs-cms/kaleta`) and `compose.yaml`.
7. The daily check of the update channel and the project website runs again; documentation caught up.

## 2.2 – Claude, in charge and safe (released 29 September 2026)

1. **What a connection may do:** everything the user may, drafts only, or read only – chosen on the consent screen and
   for personal tokens, never beyond the user's role. Existing connections keep full access. A connection limited to
   drafts or reading sees only the tools it may use, and publishing through it is refused before anything is saved.
2. **The change log tells a person's change from Claude's** and names the connection; Claude reads it with `list_changes`.
3. **Claude on by default** for new installations, "Connect Claude" in First steps, OAuth for sites in a subfolder
   (`/.well-known/openid-configuration` under the site).
4. **Instructions for Claude** from the site owner, given to every connection, and MCP resources and prompts (build a
   page, audit and fix, translate a page, write news, weekly review).
5. **Settings over MCP:** extensions, language versions, the cookie bar, SEO switches, analytics codes and head code.
6. The MCP protocol version is negotiated (2025-06-18, 2025-03-26, 2024-11-05).

## 2.3 – leads (released 29 September 2026)

1. **Where leads came from:** the first page of the visit, its campaign and the referring site go with every enquiry and
   newsletter sign-up – remembered for the browser tab only, with the visitor's consent to marketing (a setting, off by
   default).
2. **Statistics that answer "what brings enquiries":** pages, campaigns, first pages and sites with leads and conversion
   rates, devices and campaigns of visits, pop-up conversions – the same report for Claude (`get_stats`).
3. **Forms:** several ticked options, a hidden value. A webhook per form was left out: form fields are edited by editors,
   and webhook addresses stay with administrators; the site webhook sends the form's id for routing.
4. **Embed element** for Calendly, Google Calendar and Forms, Microsoft Forms, Tally, Typeform, Airtable, Spotify and
   SoundCloud, loaded after a click; **code in the head of one page** for administrators.
5. **Accessibility in the site audit** (European Accessibility Act): contrast, link texts, alt texts, tables, frames and
   the accessibility statement; the cookie bar respects **Global Privacy Control**.

## 2.4 – for agencies (released 29 September 2026)

1. **The admin in German** – every screen and the builder; each user picks the language in My account. Polish and
   French follow once there is demand.
2. **Handing a site to a client:** ready-made roles (Client, Writer, Enquiries only), the agency's name, contact and logo
   on the sign-in screen and at the foot of the admin, and a **Before handing over** check in the site audit (also for
   Claude: `site_audit`, `kind: handover`).
3. **A guide link on every screen:** each part of the admin and the builder opens its article in the guide on
   kaletacms.com, in the admin language.
4. Moved to Later: visitor dictionaries keyed by English text – an internal change with no visible effect, better done
   on its own than next to a new admin language.
- 2.4.1: the guide gains Media, Statistics, Privacy and cookies, and Sending e-mail, and those screens link to them;
  SMTP errors in the admin language, clearer hints for the agency logo, bulk delete in Media and Google Analytics.

## 2.5 – easy to start (released 29 September 2026)

Kaleta does what it set out to do, but few people have tried it. 2.5 makes the first install short wherever it happens.

1. **The installer in German**, next to English and Czech.
2. **No database typing on platforms:** Docker, Coolify and similar platforms set the database (`KALETA_DB_*`); the
   installer asks only for the site and the administrator.
3. **Installation without the browser:** `php install.php` for scripts;
   the container installs itself on the first start when the address and the administrator's password are set.
4. **A Coolify template** (`docker/coolify.yaml`), tested in CI like `compose.yaml`.
5. **Straight to Claude:** the last installer screen shows the site's Claude address and a first prompt to try.

## 2.6 – try it, move in, measure (released 1 October 2026)

1. **Import from any site by its address:** the pages of a site on any platform (Wix, Webnode, Jimdo, Squarespace, Joomla,
   Drupal, WordPress without an export…) become builder pages with their images, and the old addresses redirect.
2. **A public demo** of the admin at demo.kaletacms.com, reset every hour.
3. **Google Tag Manager** in one field, with Consent Mode and ready-made conversion events for campaigns.
4. **An optional CAPTCHA** for forms: hCaptcha, Google reCAPTCHA v3 or Cloudflare Turnstile, on top of the built-in
   protection.
5. **Installed by upload:** upload the files, create a database, open install.php. The Docker image, the Coolify
   template, the command-line installer and the database from environment variables (2.1 – 2.5) are removed.

## Direction after 2.6

Decided on 2 October 2026: the owner approved 102 items of a feature map – what business sites use on other platforms and
what Kaleta could become. They are ordered so that each release stands on the ones before: first moving sites (2.7),
then a site that runs itself (2.8), many sites as one (2.9), the business as data (2.10), content types that keep
themselves current (2.11), leads and forms (2.12), Google and CRM connections (2.13), upkeep and EU duties (2.14), Claude as
the site's operator (2.15), shared design and blocks across sites (2.16), and in 3.0 an extension API, appointment
booking and structured importers.

## 2.7 – move the sites (released 2 October 2026)

1. **Migration through two connections:** the `migrate_site` prompt – Claude reads the old site (for example through its
   Breakdance or WordPress connection) and rebuilds it here as drafts.
2. **Migration report:** every old address checked against the new site before the domain is switched – missing pages,
   pages not published yet, redirect chains, lost descriptions, forms and images – plus the checks before handing over.
   In Import and export and as `migration_report`.
3. **Old form entries** into Enquiries (`import_enquiries`).
4. **SEO plugin data** (SmartCrawl, Yoast, Rank Math) and **custom post types and fields** in the WordPress import.
5. **Builder:** a transparent header that turns solid on scroll, icons in menus, a mega menu with columns, more display
   conditions (language, URL parameter), copy and paste between sites, more scroll animations, motion while scrolling and
   hover effects.

## 2.8 – a site that runs itself (released 2 October 2026)

1. **Background jobs:** one list of what the site does by itself, run by cron or by visits, one run at a time, with the
   last run, the last success and the error of each job in System status. The installer shows the cron line.
2. **Events:** one record of what happened (enquiries, publishing, backups, updates, failed mail and webhooks, 404
   spikes, failing jobs, blocks) – read by the alert e-mails and by Claude (`list_events`, `get_health`).
3. **Alert e-mails** for warnings and errors, at most one an hour.
4. **Updates that undo themselves:** after an update the site checks itself; a server error puts the previous files back.
5. **Firewall:** blocked addresses, networks and countries, a request limit, and a day's block for probing other systems.
6. **Domain and mail watch:** SPF, DMARC and DKIM with the record to add, certificate and domain expiry – daily.
7. **Security hygiene:** unused accounts and Claude connections, connections without an expiry; suspended by a setting.
8. **Speed:** real-user Core Web Vitals in Statistics without cookies, an audit of pages that got slower, preloaded
   custom fonts.

## Not planned

- A second e-mail renderer or an e-mail builder; campaign features (segments, automations, A/B tests, open tracking).
- Multisite in one installation, e-commerce, memberships, a visitor-facing AI chat, a headless or GraphQL API, real-time
  co-editing.
- More style presets, approval workflows, PHP themes.
- Reversed on 2 October 2026 (see Direction after 2.6): appointment booking, form logic (multi-step forms), structured
  importers and an extension API are now planned.

## Later

- Visitor dictionaries keyed by English text, like the admin ones (moved from 2.4).
- Legal text templates with a clear disclaimer (privacy policy, terms, cookie policy) per country.
- Right-to-left languages.
