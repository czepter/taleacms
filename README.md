<p><picture><source media="(prefers-color-scheme: dark)" srcset="image/talea-logo-dark.svg"><img src="image/talea-logo.svg" alt="Talea" height="48"></picture></p>

# Talea

**A self-hosted website builder for small businesses and freelancers**: pages, news,
a visual page builder whose output reads like hand-written HTML, forms and enquiries, an AI assistant, Claude over MCP,
and a WordPress importer. You own the site, the data and the server.

![The Talea builder: the canvas is the real page, elements on the left, properties on the right](docs/screenshots/admin-builder.png)

## Features

- **Page builder** – the canvas is the real page. Drag elements and ready-made sections into place, style them separately
  for desktop, tablet, mobile and hover. Drafts save continuously and go live only when you publish; older versions can be restored.
- **Library of 40 ready-made sections** (heroes, services, pricing, testimonials, team, gallery, contact with a form…) and
  **three starter sites** at installation. Texts follow the page language.
- **Design system** – one-click styles, colours with a readability (WCAG) check, dark mode, fonts, fluid sizes and spacing –
  all tokens, so changing a colour restyles the whole site.
- **Site parts** – header, footer and wrappers for the news item, news list and 404 page in the builder, including
  variants for selected pages (a landing page without navigation).
- **Pop-ups** – windows, slide-in panels and bars built in the builder, with triggers (time, scrolling, exit intent,
  idle time, page count), rules by page, language, period, device and campaign, and cookie-free views and conversions.
- **Components** – reusable blocks with properties; editing a component updates it everywhere.
- **Collections** – custom content types (testimonials, team, products, branches…) with their own fields, listed in the
  builder with `{{field}}` tags, filters, sorting and pagination, and item pages with a builder template.
- **Forms and enquiries** – an enquiry form without CAPTCHA or cookies, enquiries in the admin, email notifications,
  CSV export and automatic deletion of personal data.
- **Newsletter** – double opt-in sign-up; send your latest news in an e-mail styled by the design system (through SMTP),
  or pass confirmed subscribers to Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing or any service via a webhook.
- **Company details** – address, company ID, opening hours and map once in Settings; the site shows them and search
  engines get LocalBusiness structured data.
- **AI** – the assistant drafts a new section from a description, rewrites element text, suggests headlines, SEO
  descriptions, proofreading and translation (Claude, OpenAI, Google Gemini or Mistral). Claude can also build the site
  over **MCP**: it turns HTML into builder content and edits site parts, collections and the design – always as a draft to approve.
- **News** (blog), sites in around forty languages, SEO and llms.txt, cookie-free analytics, redirects, backups and signed updates,
  **WordPress import** (straight into the builder, too).

## Principles

- **No technical debt:** plain PHP 8.5+, no framework, Composer or build step; no third-party plugins.
- **Clean output:** one builder element = one HTML tag, CSS only for what the page uses, in cascade layers (`@layer`);
  JavaScript only where it is really needed. Tests enforce it.
- **Web 2026:** fluid type and spacing, container queries, OKLCH colours (`color-mix`), the Popover API, view transitions.
- **AI as a first-class user:** whatever works in the editor works over MCP – same schema, validation and permissions.
- **Privacy and accessibility by default:** no third-party scripts or fonts, colour contrast checks.
- **FTP installation**, signed updates.

## Quick start (Docker)

```bash
# set TALEA_DB_PASSWORD and TALEA_SITE_URL in docker-compose.yaml (or in your environment)
docker compose up -d --build   # then open http://localhost:8080: the installer asks for the site and the administrator
```

The stack is Talea on FrankenPHP plus MySQL 8.4; configuration comes from environment variables, updates are a new image
(the entrypoint applies pending migrations). All variables and volumes: [docker/README.md](docker/README.md).
Other ways to run it (hosting, reverse proxy, backups): [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Development

```bash
export DEV_UID=$(id -u) DEV_GID=$(id -g)
docker compose -f docker-compose-dev.yaml up -d --build   # code mounted from the checkout, mail catcher, Adminer
composer test                                             # PHPUnit: unit, integration against MySQL, whole installed sites
```

`php tools/unit-tests.php` runs the fast checks, `php tools/contracts.php` shows changes of the public contracts (MCP tools,
design tokens, builder elements), static analysis is PHPStan. How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md);
architecture notes for contributors and agents: [CLAUDE.md](CLAUDE.md).

## Documentation

- [User manual](docs/manual.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Releasing](docs/RELEASING.md) and [release policy](docs/RELEASE-POLICY.md)
- [Writing add-ons](docs/EXTENSIONS.md)
- [Where Talea differs from its origin](docs/DECISIONS.md)

## Licence

GNU GPL version 2 or later; the licence text is in [`LICENSE`](LICENSE).

Talea is a fork of Kaleta by Miroslav Kaleta and contributors. It diverges deliberately and merges nothing from upstream;
see [`NOTICE`](NOTICE) for the attribution.
