# Site tests

End-to-end tests of whole installed sites over HTTP. They replace the shell walk `tools/test.sh` (HF-19). One test class installs its own
site (a copy of the code in a temp folder, its own database, its own PHP server, the web installer, a signed-in administrator, an MCP
token), so classes share nothing and run in parallel.

```bash
vendor/bin/phpunit --testsuite site                       # all, one after another
vendor/bin/paratest --testsuite site --processes 6        # all, six classes at a time
vendor/bin/phpunit --testsuite site --filter PublicSiteTest
```

MySQL: `TALEA_TEST_DB_*` (defaults to the dev stack's `db-test`, `127.0.0.1:33061` root/root). Without MySQL the tests are skipped.

## Writing a test

```php
final class SomethingTest extends SiteTestCase
{
    // optional: protected static function siteOptions(): array { return ['web' => 'business', 'extensions' => [...], 'login' => false]; }

    public function testThePageWorks(): void
    {
        $this->assertPage('/services', 200, 'Services');
        $this->site()->exec("UPDATE tl_pages SET visible = 1 WHERE slug = 'x'");
        $this->site()->clearPageCache();
    }
}
```

- **One class = one site = one theme.** The tests of a class run top to bottom and may build on what earlier tests did (use
  `#[Depends('testX')]` where the order matters). Do not depend on another class: if the old section relied on state from an earlier
  section (a page, a category, a token), create it in the class (a helper at the top, or the first test).
- Put the class in `tests/Site/<Area>/…Test.php`, namespace `Talea\Tests\Site\<Area>`, `#[Group('site')]`. Group by the theme of the old section.
- Keep every old check: one `check`/`expect`/`echo "  ok"` becomes at least one assertion with a message that says what it proves.
  Czech names of tables, columns, routes and texts stay as they are in the old script (the rename phases change them later with the code).
- No `sleep`, no fixed ports, no files outside `$this->site()->workDir()`; helper servers via `$this->site()->startPhp()`.

## Harness (`tests/Site/Support`)

| Need | Use |
|---|---|
| the site | `$this->site()`: `->base` (URL), `->root`, `->path('x')`, `->workDir('n')`, `->password`, `->mcpToken`, `->port('name')`, `->freePort()` |
| browser as the signed-in administrator | `$this->site()->admin()` (`Http`: `->get($path)`, `->post($path, $fields)`, `->upload($path, $fields, ['file' => $file])`) |
| a visitor / another person | `$this->site()->client('name')` (own cookie jar), `->signIn($client, $user, $password)` |
| response | `Response`: `->status`, `->body`, `->redirect`, `->headers['name']`, `->contains()`, `->matches()`, `->json()`, `->text()`, `->csrf()`, `->field('name')` |
| old `check "x" 200 /path "text"` | `$this->assertPage('/path', 200, 'text' or [..], as: $client, message: 'x')` (default browser = signed-in admin, like the old script after login) |
| `curl … -X POST … -d "_csrf=$TOKEN"` | `$this->adminPost('/admin.php?module=…&action=…', ['field' => 'v'], formPage: '/admin.php?module=…')` (fetches a fresh token from `formPage`) or `$http->post($path, ['_csrf' => $http->get($page)->csrf(), …])` |
| `csrf` / `$TOKEN` | `$this->site()->csrf($client, '/page')` |
| `mcp tool '{json}'` | `$this->site()->mcp('tool', [..])` (decoded JSON-RPC), `->mcpResult('tool', [..])` (the tool's text, decoded when JSON), `->mcp('tool', $args, token: $other)` |
| `sq "SELECT …"` (mysql client) | `->value($sql, $params)` (first column), `->rows($sql, $params)`, `->exec($sql, $params)` |
| `REPLACE INTO tl_settings …` | `->setting('name', 'value')`, `->settingValue('name')` |
| `rm -f $WORK/web/storage/cache/pages/*.html` | `->clearPageCache()` |
| `curl "$B/ulohy?token=testtoken123"` | `->runTasks()` (background jobs, web-cron way) |
| `php -r 'require system/bootstrap.php; …'` in the site | `->php('code')` (cwd = the site, bootstrap loaded; returns the output) |
| `php -S` fake servers (SMTP, S3, channel, service …) | `->startPhp($dir, $router, ['ENV' => 'v'], $port)` (waits until it answers; stopped after the class). The repo's fakes are in `dirname(__DIR__, 3) . '/tools'` (`fake-smtp.php`, `fake-services.php`, …) |
| files in the site | `$this->site()->path('media/x.jpg')`, `file_put_contents`, `is_file` |
| expect equal | `$this->assertSame($expected, $actual, 'what it proves')` (the old `expect "x" "$got" "want"` — note: bash compared strings, cast numbers with `(string)`) |

`siteOptions()`: `web` (starter site: `business`|`crafts`|`consulting`), `extensions` (installer checkboxes), `prefix`, `siteName`,
`login` (`false` = start anonymous: login pages, lockout, 2FA).

Needs to add something shared to `Support/`? Do not edit it from a parallel conversion: put the helper in your own class or a trait next
to it, and note it in your report.

## Where the old checks went

The tests were ported from the former shell suite section by section (HF-19): one area directory per theme, one class per section or
chain of sections. `PublicSiteTest` is the reference conversion. Shared helper traits live next to the classes that use them; the ones
used by several areas (`mcpText`, `publishLook`, author session, fake-service logs) are candidates for `Support/`.
