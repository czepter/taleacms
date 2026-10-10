# PostgreSQL support (HF-14)

Status: the product's SQL is portable: `php tools/sql-dialect-scan.php` finds no MySQL-only line outside `Core\Dialect\MySql`, and `tests/Unit/NoMysqlOnlySqlTest.php`
keeps it at zero. The same code runs on MySQL 8 and PostgreSQL 18; the installer, the whole site suite, the integration and the unit suites run on both
(`composer test:pg` for the integration suite, `TALEA_TEST_DB_DRIVER=pgsql vendor/bin/paratest --testsuite site` for the sites). Section 5 lists what the conversion
decided and found; section 6 what is left.

## 1. What the scan found

`php tools/sql-dialect-scan.php` scans `system/src`, `system/views`, `bin`, `tools` line by line (comment lines skipped). Counts are
matching lines, not statements. Run with `--summary`, `--category=<key>` (file list), `--json`.

| Category (key) | Lines | Files | Conversion |
|---|---:|---:|---|
| `ON DUPLICATE KEY UPDATE` (upsert) | 23 | 17 | `Db::upsert()` |
| `INSERT IGNORE` (insert_ignore) | 10 | 10 | `Db::insertIgnore()` |
| `REPLACE INTO` | 0 | 0 | none left |
| `JSON_*` SQL functions (json) | 7 | 5 | `Dialect::jsonExtract()` |
| `INTERVAL` arithmetic (interval) | 55 | 34 | `Dialect::interval()` |
| `CURDATE`, `UNIX_TIMESTAMP`, `DATE_FORMAT` … (date_functions) | 20 | 9 | `Dialect::unixTime()`, `CURRENT_DATE`, or PHP |
| `NOW()` (now, portable, not counted) | 119 | 50 | works on both: the session time zone is set on both engines |
| `GROUP_CONCAT` | 1 | 1 | `Dialect::groupConcat()` |
| `GET_LOCK` / `RELEASE_LOCK` (locks) | 2 | 1 | `Db::lock()` / `Db::unlock()` (Scheduler) |
| `MATCH … AGAINST` / `FULLTEXT` | 3 | 3 | `Dialect::fulltextMatch()` |
| backtick quoting | 8 | 4 | covered by the rewrite in `Db::sql()`; replace when touching the line |
| `LIMIT x, y` | 0 | 0 | none left (`Dialect::limit()` for new code) |
| `UPDATE`/`DELETE` with `LIMIT` (limit_write) | 3 | 3 | select keys first, then `IN (…)` |
| multi-table `UPDATE`/`DELETE … JOIN` | 2 | 2 | `UPDATE … FROM` / subquery |
| `SHOW …` | 4 | 3 | `information_schema`/`pg_catalog` behind a `Db` method |
| `information_schema` with `DATABASE()` | 2 | 2 | `Db::tableExists()` |
| `IF()` / `IFNULL()` | 17 | 11 | `CASE WHEN` / `COALESCE` (both engines) |
| `FIND_IN_SET`, `FIELD`, `LOCATE`, `REGEXP` … | 3 | 2 | per case |
| `DATABASE()`, `LAST_INSERT_ID()`, `FOUND_ROWS()` | 4 | 4 | `Db::databaseName()`; `Db::insert()` already returns the key |
| `CONCAT()` (NULL semantics) | 15 | 5 | review each: `COALESCE` the arguments |
| `RAND()` | 0 | 0 | none left |
| `AUTO_INCREMENT`, `ENGINE=`, `ON UPDATE CURRENT_TIMESTAMP` | 10 | 2 | DDL strings in `SearchData` / a stats view, look at them |
| `TRUNCATE`, `ALTER TABLE`, `FOREIGN_KEY_CHECKS` … | 5 | 2 | per case |
| boolean column compared or summed as a number | 140 | 56 | the largest category, see below |
| `LIKE` / `COLLATE` (accent and case assumptions) | 33 | 27 | see section 3 |
| `mysqldump`, `MYSQL_ATTR`, `mysqli` | 2 | 1 | `Backup` needs a `pg_dump` counterpart |
| **Total (portable not counted)** | **369** | | |

The issue's figure of 135 `JSON_*` counted PHP's `JSON_UNESCAPED_*` constants; only 7 lines are SQL. The `boolean_literal` category was not
in the issue's list: `WHERE visible = 1`, `blocked = 0`, `SUM(visible)` work on MySQL (booleans are `TINYINT(1)`) and fail on PostgreSQL
(`operator does not exist: boolean = integer`). Binding `0`/`1` as a parameter works on both. Conversion: `= TRUE` / `= FALSE`, which MySQL
also accepts. Also not found by a text scan, to be fixed while converting:

- PostgreSQL returns boolean columns to PHP as `bool`, MySQL as `int`: `=== 1` / `=== '1'` on a fetched flag breaks, `(int)` and `(bool)` casts do not.
- `ORDER BY` on nullable columns: NULLs sort first in MySQL and last in PostgreSQL (`NULLS FIRST/LAST` where the order is visible).
- `UPDATE` row counts: MySQL counts changed rows, PostgreSQL matched rows (`rowCount()` used as "was changed").
- `GROUP BY` that relies on functional dependence of non-key columns.
- Unsigned columns do not exist in PostgreSQL (the Phinx adapter maps them to signed): `SMALLINT UNSIGNED` columns (image sizes, counts) top out at 32767.
- Index names are schema-wide in PostgreSQL (`uq_users_username`), per table in MySQL: two prefixes in one schema collide. One site per database or schema.
- Case-sensitive identifiers: everything is lowercase and `{table}` quotes it, so nothing to do.

## 2. Design

### Dialect layer (`system/src/Core/Dialect/`)

`Db::dialect()` returns `Dialect\MySql` or `Dialect\Postgres` (abstract `Dialect`, no framework, chosen from the DSN). Methods generate SQL
(placeholders `?`, tables as `{table}`, expanded by `Db::sql()` with prefix and quoting per engine). Identifiers and JSON paths are validated, values are never inlined.

| Need | Dialect method | Db method |
|---|---|---|
| upsert | `upsert($table, $columns, $keys, $update)` | `Db::upsert($table, $data, $keys, $update = null)` |
| insert ignoring a duplicate | `insertIgnore()` | `Db::insertIgnore()` (returns whether a row was added) |
| JSON text at a path | `jsonExtract($column, '$.a.b[0]')` | |
| time | `now()`, `interval(5, 'day')`, `unixTime($expr)` | |
| lock | `lock($pdo, $name, $timeout)` (MySQL `GET_LOCK`, PostgreSQL `pg_try_advisory_lock` of a hashed key, polled until the timeout) | `Db::lock()` / `unlock()` |
| full-text | `fulltextMatch($columns)`, `fulltextQuery($words)`, `fulltextIndex()` | |
| accent- and case-insensitive LIKE | `likeInsensitive($column)` | |
| `GROUP_CONCAT` | `groupConcat($expr, $separator, $orderBy, $distinct)` | |
| `LIMIT n OFFSET m` | `limit($n, $m)` | |
| identifiers | `quote($name)` | |
| catalogue | `tableExistsSql()`, `currentDatabaseSql()` | `Db::tableExists()`, `Db::databaseName()` |
| last insert id | `returning($key)` | `Db::insert()` appends `RETURNING` (PostgreSQL has no `LAST_INSERT_ID`); tables without an identity column return 0, as MySQL does |
| connection | `dsn()`, `defaultPort()`, `phinx()`, `setTimeZone()` | `Db::fromConfig(['driver' => …])` |

Unit tests compare the generated SQL (`tests/Unit/DialectMySqlTest.php`, `DialectPostgresTest.php`); `tests/Integration/DialectTest.php`
executes every helper on the engine the suite runs against.

`Db::sql()` also turns MySQL backticks into double quotes for PostgreSQL, so old SQL with backticks runs unchanged while it waits for conversion.

### Connection

`TALEA_DB_DRIVER` = `mysql` (default) or `pgsql`; `config.php` carries `'driver'` (a missing key means MySQL, so existing sites keep working).
Port default per driver (3306, 5432); a socket is the PostgreSQL socket directory. The installer has a database-type select (the port may be empty).
`Migrator::phinxConfig()` picks the Phinx adapter and charset/collation from the dialect (`phinx.php` and `bin/migrate` use it). Migrations are
serialised by `Db::lock()` on both engines. Dependencies: `ext-pdo_mysql` moved from `require` to `suggest`; the image installs both.

### Schema

Phinx DSL stays the only way to define tables. What differs is in `Core\MigrationSupport` (SQL only runs on PostgreSQL):

- `public_id`: `CHAR(36)` with the `RANDOM_BYTES` default on MySQL, native `uuid` with `gen_random_uuid()` on PostgreSQL (`MigrationSupport::publicId()`).
- FULLTEXT indexes: a GIN index over `to_tsvector('simple', …)` of the same columns (`MigrationSupport::create()`); the query uses the same expression (`fulltextMatch()`).
- `TEXT` with `TEXT_MEDIUM` limits become plain `text`; unsigned integers become signed; `TINYINT(1)` booleans become `boolean`; comments are kept.
- Migration `…120008_ignore_case_in_lookup_columns` creates the collation and applies it (section 3). It does nothing on MySQL.

## 3. Collation decision

MySQL compares every string column with `utf8mb4_0900_ai_ci` (accent- and case-insensitive); PostgreSQL has no equivalent default.

Options: (a) `citext` columns plus `unaccent()`; (b) ICU nondeterministic collations per column.

**Chosen: (b) for the few lookup columns, nothing for the rest.** One collation `talea_ci` (`provider = icu, locale = 'und-u-ks-level1', deterministic = false`: primary
strength, ignores case and accents, exactly what `ai_ci` does), applied to: `users.username`, `users.email`, `role.name`, `subscribers.email`, the `slug` columns (categories, news,
tags, pages, popups, collections, collection items) and `redirects.from_path`. Everything else stays deterministic, as the code never relied on case-insensitive equality there.

Why not `citext`: it only folds case (accents still differ, unlike MySQL), needs `CREATE EXTENSION` (a privilege shared hosting often does not give), and `unaccent()` is not immutable,
so it cannot be used in an index without a wrapper function. ICU collations need no extension and give MySQL's behaviour. PostgreSQL 18 supports `LIKE` on nondeterministic
collations (earlier versions do not), which is one reason the minimum is 18. Unique indexes and lookups use the collation as it is.

Search does not depend on the collation: `Core\Search::normalize()` already writes `search_text` in lowercase without accents, and the full-text index is on that column. The
title `LIKE` next to it uses `Dialect::likeInsensitive()` (`COLLATE talea_ci` on PostgreSQL). So "accents and case match between the two databases" holds by
construction for search, and by the collation for lookups. `to_tsvector('simple')` has no stemming, like MySQL's FULLTEXT without a language.

Limit: a nondeterministic collation cannot back `ILIKE`, pattern-matching operators with `text_pattern_ops` indexes or `=` in hash joins; `LIKE 'abc%'` on those columns does not use a btree index.
The lookup columns are short and few; revisit if a profile asks.

## 4. How the conversion was done (and the rules that keep it)

The scan numbers in section 1 are the starting point (369 lines); today every category is zero except the ones marked portable (`NOW()`, `INTERVAL`, `CONCAT`).

| Category | Now |
|---|---|
| `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE` | `Db::insertIgnore()`, `Db::insertIgnoreSelect()`, `Db::upsert($table, $data, $keys, $update)`; `$update` takes column names (the inserted value) or `column => expression` with `{old.col}` (existing row) and `{new.col}` (inserted value): `['views' => '{old.views} + 1']`. PostgreSQL needs the old row qualified (an unqualified `visits = visits + EXCLUDED.visits` is ambiguous), so the dialect writes `{table}.col`. Values that were `NOW()`/`CURDATE()` in the statement are PHP `date()` values now (PHP and the session time zone agree, see below). |
| booleans (`visible = 1`, `SUM(visible)`) | `= TRUE` / `= FALSE` (MySQL takes both); `SUM(CASE WHEN … THEN 1 ELSE 0 END)`. **Fetched booleans:** PostgreSQL returns `bool`, MySQL `int`. `Core\PgStatement` (set as `PDO::ATTR_STATEMENT_CLASS` on PostgreSQL only) turns every fetched `bool` into 1/0 for `fetch`, `fetchAll` and `fetchColumn`, so `=== 1`, `(int)` and arithmetic behave as before. Chosen over `ATTR_STRINGIFY_FETCHES` (stringifies everything) and a per-column cast layer (needs the column types of every query): it is 40 lines, active on one engine, and also covers `$pdo->query()`. `fetchColumn` is implemented over `fetch(FETCH_NUM)`, so a false value is `0`, not "no row". Inserting `0/1` literals into boolean columns is the one thing that cannot be normalised: write `TRUE`/`FALSE` or bind a parameter. |
| time | `CURDATE()` -> `CURRENT_DATE`. `INTERVAL 5 DAY` and `INTERVAL ? HOUR` are valid MySQL and are **rewritten for PostgreSQL by `Postgres::rewrite()`** (the same shim that turns backticks into double quotes), so the 55 call sites stay as written; `Dialect::interval()` remains for SQL that is built by code. The connection sets the session time zone to PHP's offset on both engines (`Db::pdo()`, `App::applyTimezone()` -> `Dialect::setTimeZone()`); PHP writes dates with `date()`, queries compare with `NOW()`; `published_at <= NOW()` was verified on a site whose zone differs from the server's (Europe/London, +01:00). |
| `IF`, `IFNULL`, `FIELD`, `GROUP_CONCAT`, `UPDATE … LIMIT`, multi-table `DELETE` | `CASE WHEN`, `COALESCE`, `Dialect::listPosition()` (a portable `CASE x WHEN ? THEN 0 …`), `Dialect::groupConcat()`, `WHERE id IN (subselect)`. |
| JSON | `Dialect::jsonExtract()` (`Builder\Collections`, `Front\Kernel`, `Core\Documents`, `Core\Calendar`, `Fleet`). `Collections::periodCondition()` takes the dialect as its first argument. A JSON `null` is the text `'null'` on MySQL and SQL NULL on PostgreSQL: callers treat both as empty. |
| full text | `Core\Search::words()` returns the normalised words, `Dialect::fulltextMatch()` + `fulltextQuery()` build the predicate (`MATCH … AGAINST` / `to_tsvector('simple', …) @@ to_tsquery(…)`), `NewsRepository::search()` ORs it with `likeInsensitive('c.title')`. |
| `LIKE` on user-facing text | `Dialect::likeInsensitive()` in every search box of the administration and MCP (news, pages, redirects, subscribers, enquiries, media, change log, collections, notebook). Internal `LIKE` (builder JSON, slug prefixes, event types) stays deterministic on purpose. |
| locks | `Db::lock()` / `unlock()` in `Core\Scheduler` (name includes `Db::databaseName()` and the prefix) and `Core\Migrator`. |
| catalogue | `Db::columns()`, `Db::primaryKey()`, `Db::uniqueKeys()` (memoised; `AgentJournal` follows `INSERT … ON DUPLICATE` and `ON CONFLICT` by them), `Db::tablesSize()`, `Db::tableExists()`, `Db::databaseName()`. |
| emptying and numbering | `Db::emptyTables()` (MySQL: foreign key checks off around `DELETE`; PostgreSQL: one `TRUNCATE … CASCADE`) used by `SiteImport`; `Db::syncSequences()` moves a PostgreSQL identity past rows that were inserted with their own numbers (site import, backup restore); MySQL does it by itself. |
| backup and restore | `Core\Backup` writes a plain SQL file with the dialect's help (`Dialect::dump*()`, `restoreBegin/End()`): MySQL as before (`DROP`/`CREATE` from `SHOW CREATE TABLE`, rows); PostgreSQL: one `TRUNCATE` of all tables, then the rows of every table with a column list, tables in foreign-key order, booleans as `TRUE`/`FALSE`, strings with line breaks or backslashes as `E'…'` so a statement still ends at the end of a line. The structure of a PostgreSQL backup is not in the file: it comes from the migrations (restore into a site at the same migration level). The restore runs in one transaction on PostgreSQL (a failed restore changes nothing). The file says `-- engine: pgsql|mysql`; a file of the other engine is refused (move content between engines with the export). `bin/migrate --backup` and the updater use the same code. |
| `char(n)` columns | PostgreSQL pads `char(2)` with blanks (`'  '` instead of `''`): the `language`, `color`, `reset_token_hash` and `secret_hash` columns are `varchar` now (the other `char` columns always hold a full-length value). |
| `ORDER BY` of nullable columns | `col IS NULL DESC, col` (NULLs first) or `col IS NULL, col DESC` (last) where the visible order depends on it (`AgentSchedules`, `GoogleBusiness`); no `NULLS FIRST` (MySQL has no such syntax). |
| installer | a refused PostgreSQL login arrives as SQLSTATE 08006 with the reason in the text: `Installer::connectionError()` reads the text before it treats 08xxx as "server not reachable". |

### Tests

- `TestSql` (tests/Site/Support) translates the MySQL SQL the site tests write (`Site::value/rows/exec`) for PostgreSQL: GROUP_CONCAT, IF, IFNULL, JSON_EXTRACT/UNQUOTE/`->>`, CONCAT and `SUM` of booleans or predicates, REPLACE INTO and ON DUPLICATE KEY (the unique key is asked of the database), `visible = 1`, `0/1` literals under boolean columns in `INSERT … VALUES`, FIND_IN_SET, SHA2, UNIX_TIMESTAMP, LEFT, DAYOFWEEK, TIME, `INSERT IGNORE`. It is a mini-translator for what the tests use, not a general one; what it cannot translate is written portable in the test or branches on `TestDatabase::isPostgres()` with a comment.
- The test connection of the harness uses `PgStatement` too, so booleans read 1/0 on both engines. The template database name includes the engine (the marker file in the temp folder is shared by both servers).
- `tests/Site/PagesNews/SearchParityTest` asks the site search and the administration search box the same words (accents, case, prefixes, a word inside a title) and expects the same results on both engines; `tests/Site/SiteMoveSecurity/BackupRoundTripTest` writes and restores a backup with values that look like SQL.
- `tests/Integration/DialectTest` executes every helper (upsert with expressions, insertIgnoreSelect, catalogue, listPosition, booleans, emptyTables + syncSequences) on the engine the suite runs against.

## 5. Differences between the engines that the conversion met

- Booleans: `boolean = integer` is an error in PostgreSQL; fetched as `bool`. `SUM(boolean)` and `MAX(boolean)` do not exist; `COALESCE(boolean, 0)` does not type-check.
- `ON CONFLICT … DO UPDATE` needs the conflict target (the unique key) and an unambiguous column reference on the right side; MySQL's `VALUES(col)` is `EXCLUDED.col`.
- `string_agg(DISTINCT x, ',' ORDER BY y)` wants `y` to be `x`. `GROUP_CONCAT` has no PostgreSQL function of that name.
- `UPDATE` row counts (changed vs matched): every `rowCount()` of the product sits behind a `WHERE` that makes a matched row a changed row (checked one by one).
- `CONCAT(…)` skips NULL on PostgreSQL and returns NULL on MySQL: none of the product's uses has a nullable argument where it matters.
- A failed statement aborts a PostgreSQL transaction (MySQL carries on): code that catches an exception inside `Db::transaction()` and continues would break; none was found by the suites.
- Double-quoted text is an identifier in PostgreSQL (`CONCAT(a, ":", b)`): fixed in `Core\Report`.
- `char(n)` padding (above); NULL ordering (above); unsigned columns do not exist; index names are schema-wide; `SUM(int)` is an integer on PostgreSQL and a decimal string on MySQL: tests that compare to a string cast.
- InnoDB's full-text index has a stopword list and a 3-letter minimum, PostgreSQL's `simple` configuration has neither: a search for a stopword ("with") finds rows on PostgreSQL and nothing on MySQL. Search already ignores words under three letters; the stopwords are the only visible difference.

## 6. What is left

- Documentation: README, docs/DEPLOYMENT.md and the installer screenshots (step 9 of the original plan).
- `Backup` on PostgreSQL is data only: restoring into a database at another migration level fails on the first insert (an error, the transaction rolls back). A `pg_dump`-based backup is not planned: the application must not need the client tools on shared hosting.
- Add-on SQL (`{pk}` is portable, the rest of an add-on's migration is the author's) and `Dialect::interval()` users are the only places that depend on the author using portable SQL; `docs/EXTENSIONS.md` says so.
