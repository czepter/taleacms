# Hard fork: the manual stage of the data-model rename (temporary, deleted when the rename is done)

## What happened
`tools/hard-fork-schema.php` renamed the database (tables, columns, key names) to English (see `tools/rename/hard-fork-map.php`:
`tables`, per-table `columns`). `tools/hard-fork-rename.php` then renamed what it could decide by syntax: `{table}` placeholders,
`Db::insert/update/delete` tables and their keys, SQL strings, and every string equal to a Czech word that has one English name.
**Rows returned by `Db` now carry the ENGLISH column names** (`$row['title']`, `$row['slug']`, `$row['news_id']`), but code that reads a
row with a key the tool could not decide still uses the Czech key (`$row['popis']`, `$row['ids']`) and fails with
"Undefined array key". Your worklist is that list.

## Your job
Your worklist (file, line, the word and why it was not decided) is in the file named in your task. For each listed place decide what the
word is and fix it:
1. **A column of a database row** (a key of an array returned by `$db->one/all/pairs/value`, a key written by `insert/update`, a
   `name="..."` of a form that edits such a row, a `$request->post('...')` that feeds it): rename to the English name from the map for THE TABLE
   THE ROW COMES FROM (`tools/rename/hard-fork-map.php` → `columns[<czech table>][<czech column>]`; the same Czech word can have different
   English names in different tables - that is why it was left to you: `ids` is `page_id` for pages, `tag_id` for tags, `folder_id` for media
   folders; `idp` is `redirect_id`/`log_id`/`mail_id`/`enquiry_id`/`item_id`; `datum` is `published_at` for news and `created_at` mostly …).
   Trace the row to its query (follow the variable, the function arguments, the view that receives it). Do it consistently: a form field
   name in a view and the `$request->post()` that reads it must change together (both are in your files or you say so).
2. **Builder vocabulary** (a node, element type, content field, style property of a stored build or a library section: `obsah`, `znacka`,
   `typ` inside a build, `nadpis`, `mezera` …) or an MCP translation table: LEAVE it Czech - another phase flips it.
3. **Anything else** (a config key, a view model key that is not a row, a settings name): leave it unless it is obviously a database row key;
   say in your report what you left.
The words the tool did NOT list are already decided; do not rename them back or again. Never touch comments, dictionaries
(`system/jazyky`) or `tools/rename/*`. Do not run `tools/hard-fork-*.php`.

## How to check
- `php -l` every file you change.
- `php tools/unit-tests.php` (about 930 checks, seconds): your files' checks must pass; other areas' failures are theirs (others are fixing them in
  parallel - the failures list shrinks while you work).
- Site tests need a booted installer + front end: `KALETA_TEST_DB_PORT=3307 KALETA_TEST_DB_PASSWORD= vendor/bin/phpunit --cache-directory=<unique tmp dir> tests/Site/<Area>/<Class>.php`
  (a MySQL 8.4 listens on 127.0.0.1:3307; each class makes its own database). Which site classes cover your files: grep the class files for the
  admin module / route you changed. Run only the classes related to your files, once or twice; the whole suite is run by the lead afterwards.
- A server-side error shows up as HTTP 500 and in `storage/log/chyby.log` of the test site; the harness prints the installer's error message.

## Rules
Do not commit. Do not edit files outside your worklist except where a row key in ANOTHER file demonstrably needs the same change for your
fix to work (then make the same minimal change there and mention it). Keep every behavior; no refactoring. English names only from the map.
When done reply with: files changed, number of occurrences fixed vs left (and why), anything cross-area another batch must know.
