# Spec: settings and multisite UX

Status: draft for review. Design: the "Settings und Multisite UX" canvas (Design artifact, 11 boards, German mock-up copy; the UI text
below is English because the product text is English and translated through `t()`).

## 1. Problem

The same job is done in several places, each in its own way:

- **Host names**: three places (Settings, Sites › edit, installer/environment).
- **Import**: four places (WordPress import, Talea archive, file on the server, new site from a file).
- **Visibility**: four places (site status, search engine indexing, redirect to the primary host, maintenance).
- Settings has about 20 tabs without groups.
- Agency fields ("Created and maintained by") are always shown, although most sites have no agency.

Goal: one place per job, the structure follows the deployment, and a setting appears only where it can be used.

## 2. Principles

1. **One place per job.** Every job has one page; other places link to it, they never repeat the form.
2. **Every setting has a level**: *website*, *owner* or *installation*. A page shows only the levels that exist in the deployment.
3. **No scope labels where there is nothing to choose.** A single website shows no "Only this website" tags. They appear with several websites.
4. **No new storage model for the first phases.** Phases 1–3 move forms and links; they add no table. New storage is named per phase.
5. Rules from `CLAUDE.md` stay: English text through `t()`, `data-confirm`, no inline scripts, tokens and layers, Phinx for any schema change, MCP parity (`ParityTest`).

## 3. Deployments

| Deployment | Flags | Levels that exist |
| --- | --- | --- |
| One website | none | website (owner and installation are the same person) |
| Several websites, one owner | `TALEA_MULTISITE` | website, installation |
| Agency mode | `TALEA_MULTISITE` + `TALEA_OWNERS` | website, owner, installation |
| Connected installations | extension `fleet` | adds the console area, never reaches into a site |

Roles in agency mode: *operator* (administrator of owner 1), *owner's staff* (limited by `user_sites`). Helper: one function
`Deployment::level()` (new, `Core\Deployment`) returns `single | multisite | agency`; views and modules ask it, never the two flags directly.

## 4. Information architecture

Settings navigation is grouped (replaces the flat tab row):

- **This website**: Basics, Address and visibility, Company, Cookies, SEO, Messages, Enquiries, Import and export.
- **News and communication**: Mail, Newsletter, Webhooks, Reports.
- **Data**: Backups, Redirects.
- **System**: Extensions, AI assistant, Connected installations (fleet only), System status.
- **Installation** (several websites): Websites, Owners (agency mode, operator), Limits.

The overview page shows the groups as tiles. With several websites the sidebar has a **site switcher** (grouped by owner in agency mode); the
list of websites is one page with health columns.

## 5. Pages

### 5.1 Address and visibility (phase 1)

One page for host names and everything that decides who can see the site:

- **Status** as radio cards: live, paused (maintenance page), hidden.
- **Search engines**: one switch (`robots`/noindex).
- **Host names** table: host, role (primary, alias), verification state, actions; the row of the current host says "You are using this host name". Add form below.
  Verification text record and the "check now" action sit in the row, not on another page.
- **Redirect to the primary host**: one switch.

Old places keep only a link to this page. The host table markup uses `.cell-text` / `.cell-title` / `.cell-sub` (already fixed in `sites/edit.php`);
`.tab-wrap + .form` gets a top margin (`image/admin.css`).

### 5.2 Import and export (phase 2)

- **One drop zone.** Talea detects the kind from the file: Talea archive (`content.json` in a zip), WordPress export (WXR), or a file waiting on the server (path chips). No question "which kind?".
- The detected kind is shown ("WordPress export, 18 MB") with a button to choose another file; nothing starts before the button.
- The existing refusals stay (`SiteImport::refusal()`, WXR with DOCTYPE, SVG, non-public hosts). Detection reads only the first bytes and the zip directory; it never parses a full file.
- **Export** is a card on the same page; **danger** (delete all content) is a separate card with `data-confirm`.
- New website: start choice (Empty, Import from a file, Copy of a website); with a file, the **file is its own card**, "Details" (name, owner) is another.

### 5.3 Websites (phase 3, several websites)

- The list gets **health columns**: addresses (open ones flagged), reachable (%), last backup (flag when older than 2 days), status. Source: `Core\SiteHealth`.
- Row menu: Open, Address and visibility, Import and export, Archive, Move to trash. "Move" exists only in agency mode for operators.
- Agency mode adds: owner column and filter, search, limit tiles (`TALEA_MAX_SITES`, `TALEA_MAX_OWNERS`), switcher grouped by owner, "Owners" in the navigation.
- A notice above the list when addresses wait for verification.

### 5.4 Connected installations (phase 4, fleet extension)

System › Connected installations, only with extension `fleet` on and only for the operator: design kit card (version, publish a new version) and a table
of installations (websites, reachable from outside, last report with an overdue flag, design kit version). Unchanged contract: the console never reaches into a site.
Open question: one row per installation or per website of a multisite installation. Default here: per installation.

### 5.5 "Created and maintained by" (phase 5)

The settings `agency_name`, `agency_url`, `agency_email`, `agency_phone`, `agency_logo` (today in Settings › General, per website).

Visibility, evaluated by `Agency::visible(user)` (new):

1. **One website / several websites**: hidden by default. Shown when
   - the switch **Maintained by an agency** (new setting `agency_enabled`, flag, default off) is on, or
   - at least one user has a role with the flag **Agency role** (new column `roles.is_agency`, Phinx migration; role form gets a checkbox).
   With an agency role the switch shows "on" and is disabled with the text "On automatically because a user has the Agency role".
2. **Agency mode**: always shown. The operator sets one default for the installation (owner-1 level setting); every website takes it. Owner's staff see it **read-only**. A website may not override it.
3. Audit check (`Audit.php`, "Your contact is not set") runs only when the card is visible.
4. Consumers (sign-in screen, admin footer `views/admin/agency.php`, `ReportPage`, `MonthlyReport`) read the settings only when `Agency::visible()` is true; hidden = they print nothing, no empty block.
5. Hidden means hidden in the UI only: values stay stored, so switching on again restores them.
6. MCP: `update_settings` accepts the `agency_*` keys only when the card is visible for the connection's user (same rule, a refusal says why).

With several websites the card has the link "Take over from another website" (copies the five values, no live link). In agency mode the default is stored on the owner level, not copied.

### 5.6 Visibility by deployment (the rule behind all pages)

Matrix (Yes / Read only / Needs switch or role / Needs extension / No):

| Setting | One website | Several websites | Agency: staff | Agency: operator |
| --- | --- | --- | --- | --- |
| Company | Yes | Yes | Yes | Yes |
| Address and visibility | Yes | Yes | Yes | Yes |
| Import and export | Yes | Yes | Yes | Yes |
| Move a website | No | No | No | Yes |
| Access per website (roles) | No | Yes | Yes | Yes |
| Created and maintained by | Needs switch or role | Needs switch or role | Read only | Yes |
| Websites | No | Yes | assigned only | Yes |
| Owners and limits | No | No | No | Yes |
| Backups and system status | Yes | Yes | No | Yes |
| Connected installations | Needs extension | Needs extension | No | Needs extension |

The matrix lives in one table in code (`Core\Deployment::VISIBILITY`: key → minimum level and role), read by the navigation and by the page guard. A direct URL to a
hidden page answers 404, not 403. A test iterates the table for every deployment.

## 6. Phases

1. **Address and visibility** page, links from the old places, `Deployment::level()`, grouped navigation shell.
2. **Import and export** page with the detecting drop zone; new-website flow.
3. **Websites** list: health columns, row menu, agency additions; site switcher.
4. **Connected installations** page.
5. **Agency fields**: `agency_enabled`, `roles.is_agency`, `Agency::visible()`, the owner-level default.

Each phase is its own pull request, stacked on the previous one.

## 7. Tests

- Unit: `Deployment::level()` for the flag combinations; `Agency::visible()` for every row of 5.5; the visibility table for every deployment.
- Integration: detection of a Talea archive, a WXR and a wrong file; host table rendering.
- Site: the Settings pages answer 404 for hidden entries per deployment; the agency card appears with the switch and with the role.
- `ParityTest`: new admin actions get an MCP tool or an "admin: reason" entry. `tools/contracts/*.json` regenerated.
- Browser walk (`composer test:browser`) extended for the site switcher and the drop zone.

## 8. Not in scope

Per-setting overrides across websites, a settings search, a second agency per website, uploading code or add-ons from the admin.
