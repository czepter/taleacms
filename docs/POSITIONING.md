# Positioning

Working notes for the project page (`docs/site/`) and the listings in open-source directories. Method: the website playbook
(competitor research, strategy canvas, ERRC grid, a guiding sentence with four tests, one fact list, then copy). Research of
2026-10-30. Everything marked "estimate" is a judgement, not a measurement.

## Goal and reader

Goal: the polish and completeness of a hosted website builder, on your own server.

Reader of the project page: the person who **installs** Talea (a self-hoster, a freelancer, an agency, the technical friend of a
small business) and then **hands it over** to someone who is not technical. Directories bring this reader. The business owner is
the second reader: the page has to convince the installer that the owner will manage alone.

## Competitors

Self-hosted (hero line verbatim, fetched 2026-10-30):

| Vendor | Hero promise | Main CTA | Self-host setup |
|---|---|---|---|
| WordPress | "Meet WordPress" | Get started | PHP, database, web server; "ask your host" |
| Ghost | "Turn your audience into a business." | Try free for 14 days (managed) | Ubuntu, 1 GB RAM, Ghost CLI; Docker in preview |
| Microweber | "Drag and Drop Website Builder and CMS with E-commerce" | – | PHP, Composer, npm build |
| Concrete CMS | "websites that Deliver" | Try it now (hosted plans) | git clone and Composer |
| Publii | "Create secure, independent websites with a privacy-first static CMS" | Download | desktop app, static output |
| Grav | "A CMS for humans & agents" | Download Grav 2 | PHP, zip or Composer; has an MCP server |
| Joomla | "The Flexible Platform Empowering Website Creators" | Get Joomla 6 | – |
| Kirby | "Kirby is the CMS that adapts to you" | Try now | from €99 per site |
| Statamic | "Build sites you're proud of, with a CMS you actually like." | Get Pro | Laravel; Pro $349 per site |
| Silex | "no lock-in, no subscription, no tracking" | – | Docker or Node, static output |

The alternatives people choose instead: the large hosted builders ("Start for free. No credit card required." on three of
them, 2000+ templates, AI in the headline) and a freelancer who builds on WordPress.

What people say when they look for this (Hacker News; Reddit could not be fetched):

- someone asking for alternatives to WordPress and the hosted builders, "preferably self-hosted" ([HN](https://news.ycombinator.com/item?id=19426441), no answers)
- a "Client Friendly" CMS where "the client can add/remove content without coding" (same thread)
- "most clients don't end up making their own changes with it anyway" ([HN](https://news.ycombinator.com/item?id=12408954))
- "Business owners have no idea how often they get hacked." (about plugins, [HN](https://news.ycombinator.com/item?id=26836901))
- hosted builders: no way "to export your data and leave" (same thread)
- static sites: "The forms thing in particular is one thing I see come up a lot." ([HN](https://news.ycombinator.com/item?id=39216391))
- "Why do you want to self-host?" ([HN](https://news.ycombinator.com/item?id=41347836))

### Phrase list (used by three or more; may appear, never as the main statement)

free · start for free, no credit card · open source · your · community · plugins, extensions, add-ons · AI · no lock-in,
independent · simple, modern, flexible, powerful · CMS · counts of templates, sites and downloads

### Gaps (asked, answered by no competitor homepage)

1. Can a non-technical client edit it alone, without code?
2. Are forms, bookings and a newsletter built in, or do I bolt on plugins?
3. Who keeps it updated and safe?
4. Is there a finished design to start from? (only hosted builders show one)
5. Can I export everything and leave?
6. What does it cost in total, server included?

Shared weaknesses: plugin maintenance and hacks (WordPress), lock-in and a monthly fee (hosted), no friendly editor and no forms
(static and developer tools). Shared excess: template and extension counts, scale numbers, "free/open source" as the only
argument, developer vocabulary, "AI" in the headline.

## Strategy canvas (estimate)

| Factor | Self-hosted CMS | Hosted builder | Talea target |
|---|---|---|---|
| Finished designs to start from | low | high | high |
| Owner edits on the page without help | low | high | high |
| Forms, bookings, newsletter without plugins | low | medium | high |
| Size of the plugin/extension ecosystem | high | medium | low |
| Number of templates | medium | high | low |
| Upkeep for the person who installed it | high | none | low |
| Setup effort | high | none | medium |
| Export everything and leave | medium | low | high |
| Ongoing cost | low | medium | low |
| Handing a site over to a client | low | medium | high |
| AI assistant can do the work | low | medium | high |
| Online checkout and payments | medium | high | none |

The Talea curve rises where the self-hosted tools are weak and the hosted builders strong (designs, editing, built-in tools),
and where the hosted builders are weak (export, cost, handover). It stays low on the ecosystem and the template count on
purpose, and has no checkout.

## ERRC grid

| | |
|---|---|
| **Eliminate** | plugin hunting as a premise; "start for free, no credit card"; scale numbers; AI in the headline; PHP, Docker and database names above the fold |
| **Reduce** | template count as an argument (14 finished looks instead of thousands); feature lists as the main reason; "free" and "open source" as the main claim; "no lock-in" as a slogan (keep it as a fact) |
| **Raise** | editing on the page by someone who is not technical; forms, bookings and newsletter built in; export of everything; low upkeep (one image, no plugins) |
| **Create** | the handover: set up once, then the owner runs the site; try a look on your own pages before choosing it; per-connection choice whether an AI may publish, undo of a whole AI session |

## Guiding sentence

> **You set Talea up once. After that, the person who owns the business runs the website.**

The four tests:

1. *Could a competitor use it unchanged?* WordPress and the developer CMSs cannot credibly (their readers complain that clients
   do not edit). Hosted builders have no "set up once", there is nothing to set up. Passes.
2. *Is there proof on the site?* On-page editing, finished looks, built-in bookings, forms and newsletter, roles, preview links
   with comments, updates by image. Passes; screenshots of the owner editing are the proof the page must show.
3. *Does something concrete follow?* The handover: roles and per-section rights, a look that changes the whole site, no plugins
   to patch, one container to update. Passes.
4. *Would someone leave another tool for it?* The freelancer whose WordPress clients never edit, the self-hoster asked for a hosted
   builder on their own server. Passes for the installer; for the owner only with the installer.

Dropped: "Finished this afternoon" (speed is not the reason to choose a self-hosted tool, and the usability test that would prove
it has not run). "The all-in-one website builder you host yourself" (Microweber could say it unchanged).

## Facts (every page, card, FAQ and listing uses these values)

| Fact | Value | Source |
|---|---|---|
| Version | 4.1.0 | `system/bootstrap.php` |
| First release | 2026-09-24 (v1.0.0) | git tag |
| Fork base | Kaleta 3.7.0, October 2026; later Kaleta changes rebuilt through 3.11.0 | `NOTICE`, `docs/DECISIONS.md` |
| Came with Kaleta | page builder, design system, bookings, forms, newsletter, collections, MCP, backups, passkeys | tree at the fork commit |
| Added in Talea (what a user notices) | 14 looks tried on your own pages, about 28 fonts, dragging spacing and columns on the page, one form on several pages, setup assistant and Ask box with your own key (drafts only), a search-engine check per page, members-only content, A/B tests, several sites per installation, updates by a new image | README "What Talea changes", admin modules added after the fork |
| Licence | GPL-2.0-or-later | `LICENSE` |
| Finished looks | 14 | `system/looks/` |
| Ready-made sections | 40 | README |
| Builder elements | 49 | `Build::ELEMENTS` |
| Industry blueprints | 20 | `system/blueprints/` |
| Self-hosted font families | about 28 | `docs/FONTS.md` |
| Site languages | 45, right to left included | `Language::AVAILABLE` |
| Admin languages | English, German, Czech | `system/languages/admin-*` |
| Databases | MySQL 8, MariaDB 11.4+, PostgreSQL 18, SQLite | README |
| Install | one container image (amd64, arm64), signed; `docker compose up` | `docker-compose.yaml`, release workflow |
| Updates | a new image; migrations run before the site starts; no in-app updater | README |
| MCP tools | 173 | `tools/contracts/mcp-tools.json` |
| AI publishing | per connection: drafts only or may publish | `Auth::draftsOnly` |
| Undo of an AI session | 30 days | `AgentJournal::JOURNAL_DAYS` |
| Versions per page | 20 | `Publisher` |
| Trash | 30 days | CLAUDE.md |
| Backups | scheduled, to FTPS or S3 | `Core\RemoteBackup`, `Scheduler` |
| Lighthouse | 99 to 100 in all four categories, 14 looks × 5 pages, one run on a placeholder site | `docs/LAUNCH.md` |
| Usability test (30 minutes) | not run | `docs/LAUNCH.md` |
| Selling | product catalogue with variants and an enquiry basket; no checkout, no payments | `Builder\Products` |
| Members | sign-in by e-mail link, groups; no paid memberships | `Extensions::CATALOG` |
| Several locations | one installation, one site per location with its own domain, pages, hours, bookings and people; shared look (look family), media and people; enquiries and bookings listed across sites; needs `TALEA_MULTISITE=1` | `docs/specs/multisite.md`, `Admin\Modules\Sites::inboxSites` |

## Copy draft (project page)

Language rules: whole sentences, no dashes as a stylistic device, no "not X but Y", no punchline fragments, no superlatives, no
urgency. One main button target on the page (installation); everything else is a text link.

**Hero**
- Label: Website builder for your own server
- H1: The website builder you set up once and hand to the people who run the business.
- Intro: Talea comes with finished designs, editing right on the page and bookings, forms and a newsletter built in. It runs on
  your server, so the site and its data stay with the business.
- Button: Install Talea · text link: See the looks

**Looks** (carried by images)
- H2: Start from a finished design
- Each of the 14 looks sets colours, fonts, header and footer. You try a look on your own pages before you apply it, and changing
  the brand colour later restyles the whole site.

**Editing** (the reader's worry as the heading)
- H2: Will they really edit it on their own?
- They click a headline on the page and type over it, and drag a photo in from the desktop. Phone and tablet are one click away.
  The design lives in shared styles, so a new text leaves the layout alone. You decide who may publish and who only writes drafts.

**Built in** (cards, short)
- H2: What the business needs is already there
- Appointments, enquiry forms, a newsletter, members-only pages, visitor numbers counted without cookies, search-engine data from
  the company details and versions in 45 languages. There are no plugins to choose or to patch.

**Upkeep** (for the installer)
- H2: Who keeps it running?
- An update is a new container image, and database changes apply before the site starts. Code cannot be uploaded through the
  admin, and backups go to FTPS or S3 on a schedule. One install can run many sites, each with its own people and rights.

**AI**
- H2: When an AI assistant helps
- Claude and other assistants connect over MCP and can do what the editor can, with the same checks. Per connection you choose
  whether the assistant may publish or only writes drafts with a preview link. A whole session can be undone for 30 days.

**Owning** (comparison, Talea facts only)

| Renting a website | Running Talea |
|---|---|
| A monthly fee for as long as the site exists | Free software; you pay for the server |
| The content lives on the provider's servers | The content lives on yours |
| You export what the provider allows | You export everything in an open format |

**Questions** (from real threads)
- *Can my client edit it without calling me?* Yes, on the page itself. You give them exactly the rights they need.
- *Do I need plugins for a contact form or bookings?* No, both are built in, together with a newsletter.
- *Who keeps it updated?* Whoever installed it pulls a new image. There are no plugins with updates of their own.
- *Can I take everything with me?* Yes, as an open archive that another Talea, or your own code, can read.
- *Can I sell online with it?* You can show products with prices and variants and collect order enquiries in a basket. Checkout
  and payments are not built in.
- *Why self-host at all?* The business keeps its site, its customer data and its costs in its own hands.

**Setup** (command block plus a folded "For the technical folks" list)

## Listings

- **awesome-selfhosted**: a person must submit it (machine- or LLM-written contributions are refused). The first release must be
  more than four months old: from **2027-01-24**. Category "Content Management Systems (CMS)". Description under 250 characters,
  sentence case, without "open-source", "free" or "self-hosted". Draft:
  `Website builder for small businesses with finished designs, on-page editing, bookings, forms and newsletters, made to hand over to non-technical owners.`
- **AlternativeTo**: tag "Website Builder", platform "Self-Hosted", listed as an alternative to the large hosted builders.
- **OpenAlternative**: collection "Self-hosted", tag for hosted-builder alternatives.
- Repository: homepage URL and topics (`website-builder`, `cms`, `self-hosted`, `small-business`, `page-builder`, `mcp`, `php`, `docker`).
