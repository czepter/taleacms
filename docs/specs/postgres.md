# PostgreSQL support (HF-14)

Status: foundation done (dialect layer, connection, installer, migrations, test infrastructure). The ~370 MySQL-only SQL lines are
not converted yet; the site suite does not pass on PostgreSQL until they are. Tools: `php tools/sql-dialect-scan.php` (inventory),
`composer test:pg` (integration suite on PostgreSQL).

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

## 4. Conversion order for the next steps

Each step: convert one category with the helpers, run `composer test:pg` and the site suite on both engines, then lower the counts in the scan.

1. **Installer path** (so a PostgreSQL install completes and the template build of the site suite works): `INSERT IGNORE` (10), upsert (23), `DATABASE()` and `information_schema`, `SHOW`.
   Without these the installer stops at the first default-data insert (`INSERT IGNORE INTO tl_classes`).
2. **Booleans** (140 lines, the biggest): `= 1`/`= 0` to `TRUE`/`FALSE`, `SUM(flag)` to a count, `bool` vs `int` on fetched flags.
3. **Time** (interval 55, date functions 20): `Dialect::interval()`, `unixTime()`, `CURRENT_DATE`; then check the time zone behaviour with a site suite run.
4. **`IF`/`IFNULL`/`CONCAT`/`FIND_IN_SET`/`GROUP_CONCAT`/`LIMIT` in writes/multi-table writes** (about 45 lines): plain rewrites, no helper needed for most.
5. **JSON (7) and full-text (3)**: `jsonExtract`, `fulltextMatch` + `fulltextQuery` in `Search::query()`/`NewsRepository`, `Joomla` import.
6. **Locks** (Scheduler) and the **sessions of long jobs**.
7. **Backup/restore and catalogue code** (`Backup`, `AgentJournal`, `SiteImport`, `Health`): `pg_dump`/`psql` or a PHP dump, `SHOW CREATE TABLE` replacements.
8. **Tests**: raw MySQL SQL in site tests (`Site::exec` calls with `ON DUPLICATE KEY`, `SHOW`, backticks), then `composer test:pg` for the site suite in CI, then the scan as a guard
   (fail when a category count grows).
9. Documentation: README, docs/DEPLOYMENT.md, the installer screens in the screenshots.
