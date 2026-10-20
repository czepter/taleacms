# Decisions of the hard fork

Talea is a hard fork of [Kaleta](https://kaletacms.com) by Miroslav Kaleta ([@phprscms](https://github.com/phprscms)) and
contributors ([phprs-cms/kaletacms](https://github.com/phprs-cms/kaletacms)). It branched off at Kaleta 3.7.0 in October 2026
and no longer follows the Kaleta roadmap (`ROADMAP.md` is Kaleta's history, not a plan). This file records where Talea differs
and why; a short overview is in the README ("What Talea changes").
Goal: a self-hosted Squarespace alternative for small businesses and freelancers, without e-commerce.

## Starting point

- Base: Kaleta 3.7.0, with its full history (commits by Miroslav Kaleta and KaletaCMS) kept in this repository.
- The hard-fork issues (HF-…, #5–#20) changed the foundation before any new feature: English names in code, tables, routes and
  stored values; UUID `public_id` at every boundary; Phinx migrations instead of `schema.sql`; PostgreSQL 18 next to MySQL 8;
  PHPUnit test suites; a container image with a signed release feed instead of the in-app updater; the Talea name.
- Upstream changes: nothing is merged. Each Kaleta release after 3.7.0 is read (`gh api repos/phprs-cms/kaletacms/compare/…`),
  what fits is filed as an issue and re-implemented in Talea's style. Ported through Kaleta 3.11.0 (issues #40–#58). Skipped on
  purpose: the hardening of the update manifest (the updater is off) and Kaleta's support link.

## Product

- Self-install software plus an agency tool (fleet console). Docker-first, one install per site. Not a hosted SaaS.
- Market: small businesses and freelancers, English-first, EU-hosted.
- GPL v2 or later, with a NOTICE crediting Kaleta. Kaleta stays the reference for security fixes: they are ported first.
- Revenue: everything free for now; decided again when an add-on marketplace comes back (after the dedicated update mechanism, #17).

## Order

**All work in this file starts only after the hard-fork issues #5–#20 are closed.** No new feature work before, or in between.

1. Delete whistleblowing.
2. Compose phase A (M1–M4).
3. Looks gallery, fonts, gallery layouts.
4. Add-on API v2; domain watch and firewall as bundled add-ons; notice board hidden by default.
5. AI flow, A/B tests, member login.
6. Compose phase B.
7. Client galleries add-on and the demo-images pack.
8. Launch bar check for 1.0.

## Reversed from Kaleta

| Old decision | New decision |
|---|---|
| No style presets | A gallery of 12–15 looks rebuilt closely from Squarespace, WordPress default themes and Pixieset (see Looks), switchable on a live site. |
| No foreign fonts | About 30 open-source families, mirrored and self-hosted (Latin and Latin-extended WOFF2, variable where available, licence file with each). The no-CDN rule stays. |
| No chat inside the admin; no AI key on the site | First-run wizard and an "Ask" box in the builder, using the owner's own key, drafts only. MCP stays for power users. |
| Memberships not planned | Member login: invited or open sign-up (setting), e-mail link only, several groups, gating of pages, collection items and news; gated content never cached, indexed or searchable. No payments. |
| Forms and sliders edited through property panels | Visual composing on the canvas, see `specs/visual-compose.md`. |
| Not planned: A/B tests | A/B tests with an Experiments section (see below). |

## A/B tests

- Variants of a page, section or element; goals: form sent, button click, booking, page reached.
- No storage by default (random variant per page view); sticky only after the visitor accepts the cookie bar.
- The system does the analysis; a person clicks "Promote winner", or opts in per experiment to automatic promotion with
  fixed, visible guardrails. Every promotion goes to the change log and can be undone.

## Looks

- Rebuilt closely from the source layouts: grid, spacing, proportions, type scale, colour mood, section order.
- Never copied: their code, text, photos, icons, logos, brand names or proprietary fonts. The WordPress default themes are GPL
  and may be used directly as a reference.
- A look is design tokens plus builder sections; it ships inside the release. Placeholders in the repository, plus an optional
  demo-images pack (CC0 or generated). Every look passes the contrast check and the Lighthouse budget.

## Add-ons and removals

- Whistleblowing: removed (it blocks the firewall change; delete it first).
- Add-on API v2 is additive (v1 keeps working): early-request hook that can refuse, health rows, hand-over findings, own
  tables and uninstall. Official add-ons are bundled in the release under `extensions/`, off by default.
- Domain watch: first bundled add-on (pilot for v2).
- Firewall: the core keeps the IP helper and basic rate limits; blocklists, country and network blocks and probe detection
  become an add-on.
- Notice board: stays in core as a collection preset, off by default, shown for the municipality blueprint only.
- Third-party add-ons stay "copy a folder"; the site never downloads code.
- Contracts and the deprecation policy are dropped until 4.0 (contract files are regenerated in the same commit as a change).

## Stays out

- **Multisite in one installation.** One install per site (Docker); many sites through the fleet console.
- **Add-on marketplace, for now.**
- E-commerce, payments, gift cards, donations.
- **Blog comments and podcast feed.**
- E-mail builder, campaign automation, real-time co-editing, GraphQL / headless API.
- PHP themes and custom layouts (the look comes from design tokens, classes and site parts).

## Launch bar for 1.0

1. A non-technical person goes from install to a published five-page site in 30 minutes, without CSS (tested with three people).
2. Four compose tasks (space between sections, 2:1:1 columns, image half-width, button in the right column) work by mouse,
   keyboard and touch.
3. Lighthouse 99–100 on every look.
4. Bookings, forms, newsletter and A/B tests work out of the box.

Everything else waits for 1.1.

## Deferred

- Client galleries (private link or member login, favourites, download) as a bundled add-on after launch.
- Cyrillic and Greek font subsets, on request.
