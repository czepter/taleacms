# Add-ons for Talea – the extension API

Talea 3.0 opens up to code from other developers. This guide is for developers who write an add-on. It is also for site owners who want to know what an add-on can do.

## Trust first

An add-on is PHP that runs with the same rights as Talea, just like a WordPress plug-in.

- Talea never uploads add-ons from the administration and never downloads them from the internet. Whoever manages the hosting copies an add-on into `extensions/<slug>/`.
- An administrator switches the add-on on in **Add-ons** and confirms they trust its code.
- If an add-on throws an error while it loads, Talea switches it off at once and shows the error in Add-ons.
- `'addons' => false` in `config.php` stops every add-on from loading, as a safe mode.
- Add-ons never run in the public demo.

## Official add-ons

Some add-ons are made by the Talea project and ship inside the release, in `extensions/`, next to the folders you copy in yourself. They are **off by default**: switch them on in Add-ons. Updates of Talea replace the official add-ons (`Talea\Extension\Registry::BUNDLED`) and never touch any other folder in `extensions/`. Today there is one: **Domain watch** (`extensions/domain_watch/`), the daily check of the mail DNS records, the certificate and the domain registration. It is the pilot of API 2 and a good example of a real add-on.

## The folder

```
extensions/hello/
  extension.json   the manifest
  Extension.php    the class named in the manifest
  install.sql      optional (API 1): your tables
  migrations/      optional (API 2): NNNN-name.sql, your tables and their changes
  public/          optional: images, styles and scripts the browser loads
```

The folder name is the add-on's **slug**: lowercase letters, digits and `_`, at most 31 characters.

Since 3.3.2 the web server serves only `extensions/<slug>/public/` to visitors (as `/extensions/<slug>/public/…`), and never a PHP file from it. Everything else in the folder – your PHP, `extension.json`, `install.sql` – cannot be requested from the web. A site whose `.htaccess` was customised gets the new rule in `.htaccess.talea-nova` after the update; System status reminds the administrator to carry it over.

### extension.json

```json
{
  "name": "Hello",
  "version": "1.0.0",
  "description": "What it does, in one sentence.",
  "author": "You",
  "url": "https://example.com",
  "class": "Vendor\\Hello\\Extension",
  "entry": "Extension.php",
  "requires": { "talea": ">=3.0", "api": 1 }
}
```

`requires.api` is the API version the add-on was written for. Talea loads add-ons written for 1 or 2 (`Talea\Extension\Api::SUPPORTED`; the current version is `Api::VERSION`, 2). `requires.talea` accepts `3.0`, `>=3.0`, `>=3.0 <4.0` or `^3`.

An API 2 manifest may also have:

| Key | Meaning |
|---|---|
| `capabilities` | What the add-on does, from `early_request` (runs before every public request and may refuse it), `tables` (its own database tables), `outgoing_requests` (requests to other servers) and `mail` (sends e-mail). The Add-ons screen shows this list before an administrator switches the add-on on. |
| `table_prefix` | The prefix of the add-on's tables: `ext_<table_prefix>_<name>`. Default: the slug. Two add-ons cannot share one. |

**Capabilities are disclosure, not enforcement.** PHP cannot sandbox code: an add-on runs with the rights of Talea, whatever it declares. The list tells the administrator what the author says the add-on does. Talea does hold an add-on to it where it can: `earlyRequest()` and `httpGet()` refuse to work unless `early_request` or `outgoing_requests` is declared, and an add-on with tables that does not declare `tables` is not loaded.

### install.sql (API 1)

When the add-on is switched on, `install.sql` runs. It may only contain `CREATE TABLE IF NOT EXISTS tl_ext_<slug>_<name> (…)` statements. Talea replaces `tl_` with the site's own table prefix. API 1 has no migrations: if you change your tables in a later version, keep the old columns working.

### migrations/ (API 2)

An API 2 add-on keeps its tables in `migrations/NNNN-name.sql` (four digits, in order). Switching the add-on on runs every migration that has not run yet and remembers the number of the last one (`addons_migrated.<slug>`). A newer version of the add-on, copied over the old one, runs its new migrations when the add-on is switched off and on again (Add-ons says when some are waiting). Rules:

- Write SQL that runs on MySQL and on PostgreSQL: `INTEGER`, `BIGINT`, `VARCHAR(n)`, `TEXT`, `BOOLEAN`, `TIMESTAMP`, `DEFAULT CURRENT_TIMESTAMP`. Write `{pk}` for the auto-numbered primary key column (`id {pk}`); Talea turns it into the type of the engine.
- Each statement is a `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, `INSERT INTO`, `UPDATE` or `DELETE FROM` on a table written `{ext_<table_prefix>_<name>}`, or a `CREATE INDEX` whose name starts with `ext_<table_prefix>_` on such a table. Talea expands the braces to the table with the site's prefix. Anything else is refused.
- A failing migration stops the switch-on and nothing after it runs.
- If your table has a `public_id` column, use the public id (not the number) in every address or MCP result, as Talea does.

### Uninstall

A switched-off add-on has an **Uninstall** button in Add-ons, where the administrator chooses to **keep** or **delete** its data. Keep: nothing is removed, a later switch-on finds its tables and settings again. Delete: Talea drops the tables its migrations (and `install.sql`) create, deletes its settings (`ext.<slug>.*`) and its job records. Before that `onUninstall()` of `LifecycleInterface` runs, for anything else you stored. The folder in `extensions/` stays: whoever manages the hosting removes the code.

## The class

```php
final class Extension implements \Talea\Extension\ExtensionInterface
{
    public function register(\Talea\Extension\Api $api): void
    {
        // hand callables to $api; do not write anything here
    }
}
```

Talea calls `register()` once per request. It must be fast.

## The API (version 1)

Everything in this table works in version 2 as it did in version 1.

| Method | What it does |
|---|---|
| `$api->on($eventType, fn ($type, $data))` | Runs after Talea records an event, e.g. `enquiry.received`, `build.published` or `backup.failed`. The full list of event types is in Settings → System status. |
| `$api->filter('head' \| 'footer' \| 'page.html', fn (string): string)` | Adds to `<head>` or before `</body>`, or changes the whole HTML of a public page. |
| `$api->token($name, fn (array $attributes): string)` | `{{ext.<slug>.<name> key="value"}}` in texts and builds. Values go in plain double quotes. Tokens are filled only in what editors write, never in what a visitor sends, such as a search query. You return HTML, and you escape it yourself, attributes included. |
| `$api->adminPage($name, $title, fn (Request $request): string)` | Adds a page under Add-ons, for administrators only. Talea checks the CSRF token of a POST before your callable runs; include `$api->app()->session->csrfField()` in your forms. |
| `$api->mcpTool($name, $description, $schema, $access, fn (array $args), $requires = '')` | A tool for Claude named `ext_<slug>_<name>`. The `$access` value (`read`, `draft`, `write` or `destructive`) decides which connections may call the tool. Write tools are kept in the change log, follow the site's guardrails for Claude, and can be undone with the session. `$requires` (3.3.2) is who may call it: `author`, `editor` or `admin` (the lowest role), or the ident of an admin section the user must have (`pages`, `news`, `enquiries`…). Without it, read and draft tools are open to every user, write tools need an editor and destructive tools an administrator. |
| `$api->job($name, $seconds, $label, fn (): string, $runner = 'any')` | A background job, at most every `$seconds` (at least 60). `$runner` (API 2): `any` = by cron and by visits, `cron` = only when cron calls `/tasks`, for heavy work that must not slow a visit. |
| `$api->get($key, $default)` / `$api->set($key, $value)` | Your own settings, stored separately for each add-on. |
| `$api->app()` | The site. Use it only for what the API does not cover yet; internal classes can change in any release. |

Who may call an add-on tool when `$requires` is left out:

| `$access` | Default `$requires` | Who that is |
|---|---|---|
| `read` | `author` | Every user with a Claude connection, news authors included |
| `draft` | `author` | Every user with a Claude connection, news authors included |
| `write` | `editor` | Editors and administrators |
| `destructive` | `admin` | Administrators only |

A read or draft tool that returns more than an author may see in the admin, such as enquiries, customers or settings, should set `$requires` to the matching section (`enquiries`) or role (`editor`, `admin`).

Rules for failures:
- A filter, listener or token that throws is skipped.
- An add-on that throws in `register()` is switched off.

## What API 2 adds

API 2 is strictly additive: add-ons written for API 1 run unchanged, and an API 1 add-on that calls one of these methods gets an error (the manifest decides, `requires.api`).

| Method | What it does |
|---|---|
| `$api->earlyRequest(fn (Request): ?Response)` | A hook at the very start of every public request: before routing, sessions and the page cache (never the administration). Return `null` to let the request go on, or a `Response` to answer it, for example a refusal. Needs the capability `early_request`. **Fail-open:** a hook that throws, or takes longer than 250 ms, is switched off, the error appears in Add-ons, the event `addon.failed` is recorded, and the request continues. Talea cannot interrupt a function that never returns, so keep the hook to memory, files and database lookups, never a request to another server. |
| `$api->healthRows(fn (App): array)` | Rows for System status (and `get_health`): lists of `['group', 'name', 'status' => ok\|warning\|error, 'info']`. They show while the add-on is on and are gone when it is off. |
| `$api->handoverFindings(fn (App): array)` | Findings for "Before handing over" (`site_audit` kind `handover`): lists of `['key', 'message', 'edit' => 'admin.php?module=…']`. The key reaches Claude as `<slug>.<key>`. |
| `$api->eventType($type, $description, $alert = false)` | An event type `<slug>.<name>`, recorded with `Events::record()` like Talea's own and listed in `list_events`. With `$alert`, an event of this type with severity `warning` is worth an alert e-mail (errors always are). |
| `$api->settings($schema, $title, $extra = null)` | Declared settings: `name => ['label', 'type', 'default', 'help']`; types `text`, `lines`, `flag`, `number:min:max`, `choice:a\|b`, `email`, `url`. They are stored as `ext.<slug>.<name>`, `$api->set()` refuses an invalid value, and an administration page `settings` (Add-ons → `$title`) shows the form. `$extra` (fn (Request): string) adds HTML below the form and may handle its own POSTs. |
| `$api->httpGet($url, $timeout = 5, $maxBytes = 262144)` | One GET to another server through the pinned-address path Talea uses itself: the host must resolve to public addresses only and the connection is pinned to the checked one. Redirects are not followed – read `location` and ask again. Needs the capability `outgoing_requests`. |

The method `$api->job()` takes the runner option, and `LifecycleInterface` (`onEnable()`, `onUninstall()`) is an optional second interface of the extension class. Registries read by Talea: `Core\Health` asks `Registry::healthRows()`, `Core\Audit` asks `Registry::handoverFindings()`, `Core\Events::types()` and `Core\Alerts::warnings()` add the event types, and `Core\Scheduler` runs `cron` jobs only from cron.

## The contract

`tools/contracts/extension-api.json` records the API version and the versions Talea loads, the methods with their parameters (`v2_methods` are the ones added by version 2), the filters, the tool access levels, the job runners and the capabilities. The contract tests compare it on every build: things may be added, never removed.

These names keep working through every 3.x release. Breaking them needs Talea 4.0.

Everything else in Talea is internal and may change in any release: classes, tables, columns, views and CSS classes.

## An example

`docs/examples/extensions/hello/` uses every part of API 1. Copy it to `extensions/hello/` and switch it on in Add-ons to try it. It stays as it is: it is the proof that version 1 keeps working.

`docs/examples/extensions/guard/` uses the parts API 2 adds: an early request hook, a table with migrations, a System status row, a hand-over finding, an event type with an alert, declared settings and a cron-only job. `extensions/domain_watch/` is a real add-on built on API 2.
