# Search plan for the project site

Goal: be found by people who look for a website system for a small business in 2027, and answer them honestly. Decided on
2026-10-31: answer "best CMS 2027" with a guide instead of claiming to be the best; rival products are named on comparison
pages only, with facts from their own pages and the date they were read; the site stays at `https://czepter.github.io/taleacms/`
as static pages in `docs/site/`. Research with sources: `docs/SEO-RESEARCH.md`. Facts about Talea: `docs/POSITIONING.md`.

## What the research found (2026-10-31)

- Every top result for "best CMS" and "best website builder for small business" is a 2025/2026 listicle by an affiliate or a
  vendor. None is dated 2027, none is written for someone who installs a site and hands it over.
- "Wix/Squarespace alternative self-hosted" results answer "use WordPress" and say little about how to leave. Squarespace exports
  a WordPress-format XML without store, portfolio and album pages, video, audio, drafts and styles; Wix sites run on Wix's servers only.
- "Website for multiple locations" is answered by SEO agencies, not by software. "Self-hosted booking website" has thin results.

## Pages

| Address | Target search | Angle |
|---|---|---|
| `/` | self-hosted website builder | the handover, several locations (exists) |
| `/best-cms-small-business-2027/` | best CMS 2027, best CMS for small business 2027, best self-hosted CMS | how to choose: questions, a table of the options with what each needs, where Talea fits and where it does not; links to the pages below |
| `/wordpress-alternative/` | WordPress alternative self-hosted / for small business | built in versus plugins, who updates what, when to stay on WordPress |
| `/squarespace-wix-alternative/` | Squarespace alternative self-hosted, Wix alternative self-hosted | what you can take with you, how Talea's website import brings the pages over, monthly fee versus a server |
| `/multi-location-website/` | website for multiple locations, multi-location business website | one installation, one look, a site per location, one list for bookings |
| `/self-hosted-booking-website/` | self-hosted booking website small business | bookings with confirmation and cancel link on your own server; no payments |

Every page: one H1 that matches the search, a short answer in the first paragraph, a dated "Last checked" line, a comparison
table where it helps, a FAQ from real searches, sources for every rival fact, one main button (install), links to the other pages.

## Technical

- Shared `site.css`; every page has its own title, description, canonical, Open Graph tags and JSON-LD (`Article` or `WebPage`,
  `BreadcrumbList`, `FAQPage` matching the questions shown).
- `sitemap.xml` with every page and its last change, `robots.txt` pointing to it, a `404.html`.
- No third-party scripts or fonts; images with width and height; the pages stay fast on a phone.

## Rules for the text

The language rules of `docs/POSITIONING.md` apply (whole sentences, no superlatives, no urgency). On comparison pages: name a
rival only with a fact from its own site, link the source and give the date; say what the rival does better; say what Talea
lacks (checkout and payments, an installer with an updater on shared hosting, a large plugin ecosystem).

## Upkeep

Re-check rival facts and prices every six months and update the date on each page. Submit the sitemap in the search console of the
site owner. Track positions for the target searches; review after three and after six months.
