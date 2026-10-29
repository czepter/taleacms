# Error tracking with Sentry or GlitchTip (plan)

Status: plan (October 2026), nothing built yet.

## Goal

An operator who runs one or many Talea sites sees their errors in one place: an uncaught exception, a fatal error, a failing
background job or an element that could not be rendered arrives in a Sentry or GlitchTip project with a stack trace, the release
and enough context to reproduce it. Without configuration nothing changes: the site writes `storage/log/errors.log` as today and
**sends nothing anywhere**. Visitors' data never leaves the site through this channel.

GlitchTip speaks the Sentry protocol (DSN, envelope endpoint, `X-Sentry-Auth`), so one client serves both; the plan is tested
against GlitchTip (self-hostable, the natural fit for a self-hosted product) and keeps to the subset Sentry accepts too.

## Where we are

- `App::installErrorHandler()` turns every PHP warning into an `ErrorException` and writes uncaught throwables as one line to
  `storage/log/errors.log` (class, message, file, line – no trace); System status counts the lines of the last 24 h
  (`Core\Health`), the Health tab shows the tail and can empty the file, the fleet heartbeat carries the count.
- Fatal errors (out of memory, a timeout, a parse error in an add-on) do not reach the exception handler: no
  `register_shutdown_function` catches them, they end only in the PHP/server log.
- About 70 `catch (\Throwable)` blocks swallow errors on purpose (a broken element is skipped, a job records `last_error` in
  `jobs`, mail and webhooks retry). Some log with `error_log()`, most leave no trace an operator would find.
- The CLI paths (`system/docker.php cron`, `bin/migrate`) print the message to STDERR and exit 1.

## Decisions

| Question | Decision | Why |
|---|---|---|
| SDK or own client | **Own client** `Core\ErrorReport` (one class, ~300 lines): parse the DSN, build an envelope, POST it with curl. No `sentry/sentry` | the SDK pulls in a dozen packages (Guzzle/PSR-HTTP, Symfony options resolver…) into application code that is framework-free by principle; we need one event type and no integrations |
| Protocol | envelope endpoint `POST {scheme}://{host}/api/{project}/envelope/`, item type `event`, header `X-Sentry-Auth: Sentry sentry_version=7, sentry_key=…, sentry_client=talea/<version>` | supported by Sentry and GlitchTip; the old `/store/` endpoint is deprecated |
| Configuration | **operator only**: `TALEA_ERROR_DSN` (+ `_FILE`), `TALEA_ERROR_ENVIRONMENT` (default `production`), `TALEA_ERROR_SAMPLE_RATE` (0–1, default 1); for `config.php` installs the keys `error_dsn`, `error_environment`, `error_sample_rate`. **Not a setting**, not in the admin, not over MCP | the DSN decides where stack traces go; a setting writable by an MCP connection or a stolen admin session would be a way to exfiltrate them. Same level as `TALEA_SECRET_KEY` |
| What is captured | uncaught throwables, fatal errors (shutdown function, `E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_PARSE`), failing background jobs (`Scheduler::runOne`), elements that fail to render (`Builder\Renderer`), and the `error_log()` calls that report a real fault (moved to `ErrorReport::capture()` one by one) | what an operator must act on; expected failures (a mail server that is down, a webhook receiver answering 500, a refused import URL) stay out: they have their own retries and screens |
| Levels | `fatal` (shutdown), `error` (uncaught, job), `warning` (a swallowed fault such as a skipped element) | lets a project alert on `error`+ only |
| When it is sent | after the response: the error page is flushed first (`fastcgi_finish_request()` where available), then one POST with connect timeout 1 s, total 2 s. A failed send writes the envelope to `storage/log/outbox/` (at most 100 files); the cron loop retries them and drops what is older than 24 h | an error page never waits on the tracker; the database may be the thing that failed, so the spool is files, not a table |
| Flood protection | the same fingerprint (class + file + line) is sent at most once per 60 s per container (a counter file in `storage/cache/errors/`); the suppressed count goes into the next event as `extra.suppressed` | a crawler hitting a broken page must not burn the project's quota or the site's CPU |
| Personal data | **off and not switchable**: no IP, no cookies, no request body, no session, no user name or e-mail. The URL is sent without the query string except the allow-listed English keys (`page`, `module`, `action`, `build`, `status`…); headers only `User-Agent` and `Accept-Language`. Messages are scrubbed: e-mail addresses, UUIDs of members, bearer tokens, `password=`/`token=`/`key=` pairs, and values in PDO messages become `[filtered]`. The signed-in person is sent only as role (`administrator`/`editor`/`author`/`member`/`anonymous`) | GDPR: the tracker is a processor; the less it gets, the less there is to document. The role is what reproduces most admin bugs |
| Stack trace | frames from `getTrace()` with paths relative to the install root (`system/src/Core/Db.php`), `in_app` for `system/` and `extensions/`, false for `vendor/`; five context lines from the file; **no arguments** (the client never reads `args`; `docker/php.ini` also sets `zend.exception_ignore_args=On`, new) | readable traces without leaking values; the source is public anyway, `config.php` never appears in a trace context because it holds no code that throws |
| Tags | `release` = `talea@<TALEA_VERSION>`, `environment`, `server_name` = host of `site_url`, tags `area` (`front`/`admin`/`mcp`/`cron`/`cli`/`install`), `db_driver`, `php`, `language`, `route` (the route pattern from `Front\Router`, not the address), `module` (admin), `job` (cron), `addon` (the slug when the top in-app frame is in `extensions/<slug>/`) | group and filter by what an operator decides on: which release, which engine, which add-on |
| Fingerprint | default grouping of the tracker; for jobs `['job', name, exception class]` | a job that fails every 5 minutes is one issue, not 288 |
| Browser errors | phase 2, **admin and builder only**: `image/errors.js` listens to `error` / `unhandledrejection`, posts a trimmed report (message, stack, file, line, the builder module) to `POST admin.php?module=errors` (same origin, CSRF token), the server forwards it through the same client. The public site sends nothing | the admin CSP stays `script-src 'self'` and `connect-src 'self'`; the DSN never reaches a browser; visitors are not tracked and the cookie bar has nothing new to declare |
| Performance tracing | out of scope for now (phase 3 at most: one sampled transaction per request with the total duration and the number of queries) | Talea already measures web vitals itself (`Core\WebVitals`); traces add cost and data for little gain on small sites |
| Cron monitor | phase 3: `TALEA_HEARTBEAT_URL` – the background loop GETs it after each successful run (GlitchTip heartbeat monitors, Sentry cron check-ins via their URL form, Uptime Kuma push) | "jobs stopped" is the error that never throws |
| Where it shows in Talea | System status gets a row "Error reporting": off / on, to `<host>`, last sent, waiting in the outbox; the fleet heartbeat gets `error_reporting: true/false`; the errors.log line gets the event id (`… [event 3f2a…]`) so the log and the tracker can be matched | the operator sees that it works without opening the tracker |
| Record of processing | when a DSN is set, `Privacy::processingRecord()` adds "Error reports: technical error details without personal data are sent to `<host>`" | the owner's Art. 30 record stays truthful |
| errors.log | stays, gets the full trace (`(string) $e`) instead of one line, rotated at 5 MB (one `.1` file) | works without a tracker; today the trace is lost unless debug is on |

## Pieces to build

### Phase 1 – server errors (the core)

1. `Core\ErrorReport`
   - `configure(array $config)` from `Config::env('ERROR_DSN')` / `config.php`; `enabled()`; DSN parsing (`https://<key>@<host>/<project>`,
     optional path prefix for GlitchTip behind a sub-path) with a clear error on a malformed DSN (logged once, never thrown).
   - `capture(\Throwable $e, string $level = 'error', array $context = []): ?string` – builds the event, applies sampling, flood
     protection and scrubbing, queues it for sending after the response, returns the event id.
   - `message(string $text, string $level, array $context)` for the `error_log()` replacements without an exception.
   - `flush()` – sends what was queued (called from a shutdown function after `fastcgi_finish_request()`); `retryOutbox()` for cron.
   - `scrub(string): string` and `frames(\Throwable): array` as public static functions so the unit tests reach them.
2. `App::installErrorHandler()`: call `ErrorReport::capture()` in the exception handler (before the error page), add the shutdown
   function for fatal errors (`error_get_last()`, level `fatal`, reserved 64 KB memory freed first so an out-of-memory error can
   still be reported), write the full trace and the event id to errors.log, rotate it.
3. Context: `App` knows the area (front, admin, mcp, cli); `Front\Router` stores the matched pattern, the admin kernel the module,
   `Auth` the role – each sets one field on `ErrorReport` (`ErrorReport::tag('route', …)`), no globals beyond that.
4. Swallowed faults: `Scheduler::runOne()` (level `error`, tag `job`, fingerprint), `Builder\Renderer` (level `warning`, the element
   type, never its content), `PageFrame` site parts / pop-ups, `SeoScore`, `ImportRecheck`, `Connectors` – each `error_log()` that
   reports a fault becomes `ErrorReport::capture()`, which still writes errors.log.
5. CLI: `system/docker.php` and `bin/migrate` capture before printing and exiting; `php system/docker.php error-test` sends a test
   event and prints the event id or the reason it failed (the operator's first step after setting the DSN).
6. Cron: `ErrorReport::retryOutbox()` at the start of the job loop.
7. System status row, heartbeat flag, errors.log line format, processing-record line (texts through `t()`, translations with
   `tools/add-translations.py`).
8. Docs: `docker/README.md` (variables), `docs/DEPLOYMENT.md` ("Error tracking": GlitchTip in the compose file next to the site,
   or a hosted project; what is sent, what is not), `config.sample.php` (commented keys).

### Phase 2 – admin and builder in the browser

9. `image/errors.js` (loaded by `admin.php` and `builder.php` only when `ErrorReport::enabled()`, so a site without a DSN emits no
   script – "what nothing emits has no script"), throttled to 10 reports per page view; listed in `tests/Unit/ScriptsTest.php`.
10. `Modules\Errors` (POST only, CSRF, signed-in staff, size limit 16 KB, rate limit through `Antispam::tally`) forwards to
    `ErrorReport::message()` with area `admin`/`builder` and the browser's stack as an exception value; the parity map in
    `tests/Unit/Mcp/ParityTest.php` gets "admin: browser error reports".

### Phase 3 – optional

11. `TALEA_HEARTBEAT_URL` after each cron run.
12. A sampled transaction per request (`TALEA_ERROR_TRACES_RATE`, default 0) if phase 1 shows the need.

## Tests

- Unit (`tests/Unit/Core/ErrorReportTest.php`): DSN parsing (Sentry, GlitchTip, sub-path, malformed), envelope shape against a
  recorded fixture, scrubbing (e-mail, token pairs, PDO values, query strings), frame paths and `in_app`, the add-on tag, sampling 0
  and 1, flood protection, the outbox limit.
- A fake tracker `tools/fake/sentry.php` (like `tools/fake/ai.php`) that stores received envelopes; site test
  (`tests/Site/ErrorReportTest.php`): a page that throws (through a test-only add-on) answers 500 with the error page **and** the
  fake receives one event with the release, the route tag, no cookie and no IP; a failing job sends one grouped event; with the
  fake down the envelope lands in the outbox and the next cron run delivers it; without a DSN nothing is sent and no script is emitted.
- Manual check against a real GlitchTip (the `glitchtip` service in `docker-compose-dev.yaml`, profile `glitchtip`, prebuilt image)
  and a free Sentry project before the release.
- `php tools/check-english.php`, `php tools/sql-dialect-scan.php` (no SQL is added), `php tools/contracts.php` (no MCP change).

## Out of scope

Sending errors from the public site's visitors' browsers, any switch that sends personal data, user feedback dialogs, session
replay, a Sentry SDK dependency, configuring the DSN from the admin or MCP, a Talea-hosted error collector.

## Open questions

- Should the fleet console (`Fleet\Console`) be able to hand its DSN to paired sites? It would save setting one variable per site,
  but it moves a configuration channel into the pairing – leaning no until someone asks.
- Do add-ons get `Api::reportError(\Throwable)`? Their uncaught errors are already tagged `addon`; an explicit call only matters
  for faults they swallow. Decide when the first add-on asks (contract change in `tools/contracts/extension-api.json`).
