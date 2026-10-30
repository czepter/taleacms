# SQLite support

Status: a site runs on one SQLite file with no database server: `TALEA_DB_DRIVER=sqlite` (Docker) or "SQLite" in the installer. The product's SQL
is the same portable SQL that runs on MySQL 8 and PostgreSQL 18 (docs/specs/postgres.md); the unit, integration and site suites run on it
(`TALEA_TEST_DB_DRIVER=sqlite`, section 5). It is meant for a small site, a demo, a trial or a laptop: one container, one writer at a time.
A busy site, several replicas or a shared database server take MySQL or PostgreSQL.

## 1. Running it

| Where | How |
|---|---|
| Docker | `TALEA_DB_DRIVER=sqlite`; the file is `TALEA_DB_NAME` (default `storage/database/talea.sqlite`, relative to `/app`). With `sqlite` no other `TALEA_DB_*` is needed, and the driver alone switches env mode on. The storage volume holds the database, so back it up with media and extensions. |
| Coolify | compose file `/docker-compose.coolify-sqlite.yaml` (one service, no database container). |
| Classic hosting | the installer's database type "SQLite (a file, no server)": server, user and password stay empty (CSS hides them); the database name is a file name in `storage/database/` (letters, digits, `-`, `_`, ending in `.sqlite`, `.sqlite3` or `.db`, `.sqlite` is added otherwise; empty = `talea.sqlite`). `config.php` gets `['driver' => 'sqlite', 'name' => 'storage/database/…', 'prefix' => …]`. |

`storage/` is denied by `.htaccess` and the Caddyfile, so the file is never downloadable; the installer accepts no path and no `.php` name for the
same reason. Requirements: PHP's `pdo_sqlite` (built into the official PHP images and the Talea image) and SQLite 3.38 or newer (JSON functions built
in, `ON CONFLICT … DO UPDATE`, `RETURNING`, table-valued pragmas). The image's Debian bookworm has 3.40.1; the code does not use anything newer
(`concat()`, `ORDER BY` inside an aggregate and `unistr()` are 3.44+ and are PHP functions here, below).

## 2. Design

`Core\Dialect\Sqlite` is the third dialect (`Dialect::forDriver('sqlite')`, DSN `sqlite:<file>`). `Db::pdo()` opens a `Pdo\Sqlite` (PHP 8.4+; its
`createFunction()` replaces the deprecated `PDO::sqliteCreateFunction()`) and calls `Dialect::connect()`, which sets the pragmas and registers the
functions SQLite lacks. Nothing else in the product knows the engine.

### Connection

- `PRAGMA foreign_keys = ON` (off by default in SQLite; the schema relies on `ON DELETE CASCADE` / `SET NULL`), `journal_mode = WAL` (readers do not
  wait for the writer), `synchronous = NORMAL`, `busy_timeout = 10000` (a second writer waits up to 10 s instead of failing).
- No session time zone: `NOW()` is a PHP function (`date('Y-m-d H:i:s')`), so it always counts time the way PHP does (`App::applyTimezone()`).
  SQLite's own `CURRENT_DATE` / `CURRENT_TIMESTAMP` are UTC, so `rewrite()` turns them into `date(NOW())` / `NOW()`.
- Locks (`Db::lock()`: migrations, the scheduler) are `flock()` on a file next to the database (`<file>.<hash>.lock`): SQLite has no named locks, and
  every process of the site sees that folder.

### SQL written the MySQL way

`Sqlite::rewrite()` runs on every statement after `Db::sql()` (as `Postgres::rewrite()` does):

| MySQL | SQLite |
|---|---|
| `` `name` `` | `"name"` |
| `NOW() - INTERVAL 5 DAY`, `col + INTERVAL ? HOUR`, `CURRENT_DATE - INTERVAL 1 DAY` | `datetime(NOW(), '-5 day')`, `datetime(col, ('+' \|\| (CAST(? AS INTEGER) * 1) \|\| ' hour'))`, `date(NOW(), '-1 day')`; a week is 7 days. The left side is `NOW()`, `CURRENT_DATE` or a column, as everywhere in the product. |
| `CURRENT_DATE`, `CURRENT_TIMESTAMP` | `date(NOW())`, `NOW()` |
| `LIKE ?` with `\` escaping `%` and `_` (MySQL's and PostgreSQL's default) | `LIKE ? ESCAPE '\'` (SQLite has no default escape character) |
| `SELECT … FOR UPDATE` | without it: a write transaction already holds the whole file |

### Functions registered on the connection

| Function | Why |
|---|---|
| `NOW()` | PHP time (above) |
| `CONCAT`, `CONCAT_WS` | 3.44+ only; and SQLite's `concat()` skips NULL where MySQL returns NULL |
| `LOWER`, `UPPER` | SQLite's fold only A–Z |
| `talea_fold(x)` | `likeInsensitive()`: `talea_fold(col) LIKE talea_fold(?)`, case and accents ignored (`remove_diacritics(mb_strtolower())`), as `utf8mb4_0900_ai_ci` does |
| `talea_match(query, col, …)` | `fulltextMatch()`: every word of the query begins a word of the columns, MySQL's `+word*`. No index: the search column (`news.search_text`, already normalised by `Core\Search`) is read row by row, which a small site's news afford. `fulltextIndex()` returns '' and `MigrationSupport::create()` makes none. |
| `talea_group_concat(value, separator, key, distinct, descending)` | `groupConcat()`: an aggregate that sorts in PHP (`ORDER BY` in an aggregate is 3.44+, `DISTINCT` with a separator does not exist) |
| `talea_unix(x)`, `talea_offset_minutes()` | `unixTime()`, `sessionOffsetMinutes()` (System status compares it with PHP: always equal) |

`jsonExtract()` is `CAST(json_extract(col, '$."a"."b"[0]') AS TEXT)`: json_extract hands numbers back as numbers, which never equal a string parameter,
while MySQL's `JSON_UNQUOTE` gives text. Upsert is `ON CONFLICT (keys) DO UPDATE SET … = excluded.col` (old row: `"tl_table".col`), insert-ignore
`ON CONFLICT DO NOTHING` / `INSERT OR IGNORE … SELECT`. The catalogue (`columns()`, `uniqueKeys()`, `tableExists()`, `tablesSize()`) reads
`sqlite_master` and the table-valued pragmas; an `INTEGER PRIMARY KEY` is the rowid and has no index of its own, so `uniqueKeysSql()` adds it as `PRIMARY`.
`Db::insert()` returns 0 for a table without an auto-numbered key (SQLite numbers every row; MySQL reports 0 there).

### Schema

The Phinx migrations run unchanged on Phinx's SQLite adapter (`Migrator::phinxConfig()`: `adapter sqlite`, `name` the file, no suffix).
`Core\PrefixAdapter` (Phinx's prefix wrapper, registered for every engine; its SQLite part) fixes two things of Phinx's prefix wrapper: an auto-numbered `biginteger` key becomes
`INTEGER` (SQLite refuses `BIGINT … AUTOINCREMENT`; its INTEGER has 64 bits), and the table names of a finished step reach SQLite's foreign key check
with their prefix. `MigrationSupport`:

- `publicId()`: `CHAR(36)` with a default built from `randomblob()` (a UUID v4 for raw inserts; `Core\Uuid` fills it on `Db::insert()`).
- `ignoreCase()` (sign-in names, e-mails, slugs, redirect paths): `COLLATE NOCASE`. Phinx cannot change a collation, so the column becomes a literal
  type and the table is rebuilt (SQLite's way of changing a column). NOCASE folds A–Z only: `Zoë` = `ZOË`, but not `Zoe`. A custom collation would fold
  accents too, but every tool that opens the file (the `sqlite3` shell, a viewer, Phinx's own connection) would then fail on those columns' indexes. Slugs
  are ASCII by construction (`slugify()`), user names in the installer too; search boxes go through `likeInsensitive()`, which does ignore accents.
- Types: Phinx writes `DATETIME_TEXT`, `BOOLEAN_INTEGER` …; by SQLite's affinity rules they are TEXT and INTEGER columns. Dates are text
  `Y-m-d H:i:s` (as PHP writes them), so comparing them with `NOW()` or a parameter compares text in the right order.

### Backup and restore

`Core\Backup` writes the same plain SQL file as on the other engines (`-- engine: sqlite`): a `DELETE FROM` of every table of the prefix, then the rows
with column lists. Strings with line breaks are concatenated with `char(10)`/`char(13)` (SQLite literals know no escapes), so a statement stays on one
line. The restore switches the foreign keys off (only possible outside a transaction) and runs in one transaction: a failed restore changes nothing.
As on PostgreSQL the structure is not in the file: restore into a site at the same migration level. Copying the file itself is a valid backup too, after
`PRAGMA wal_checkpoint(TRUNCATE)` or with the site stopped.

## 3. Differences from MySQL that remain

- Accents in `=` on the lookup columns (above). Everything a person types into a search box ignores them.
- Integer division (`5 / 2` = 2), as on PostgreSQL; the product already writes for that.
- Typing is dynamic: a string in an INTEGER column that looks like a number is stored as a number, other text stays text (MySQL would refuse or
  convert it to 0). The product binds typed parameters (`Db::run()`), so this does not show.
- One writer at a time. Writes wait for each other (`busy_timeout`); a long import or migration makes other writes wait. `Db::transaction()` starts a
  deferred transaction: a transaction that reads first and then writes while another process committed in between fails with "database is locked"
  instead of waiting. The product's transactions are short; for a busier site use MySQL or PostgreSQL.
- `tablesSize()` is the size of the whole file (one site per file).
- `Front\Stats` and the page cache write on visits: fine for a small site, the first thing to feel the single writer under load.

## 4. Rules that keep it working

The rules of docs/specs/postgres.md section 4 hold (portable SQL, `Db::upsert()`, `Dialect::*`, `= TRUE`), plus:

- The left side of `± INTERVAL` is `NOW()`, `CURRENT_DATE` or a column (`rewrite()` turns exactly that into `datetime()`).
- An alias of the table of an `UPDATE` or `DELETE` takes `AS` (`UPDATE {newsletters} AS n SET …`): SQLite refuses it without, MySQL and PostgreSQL take both.
- No SQL function newer than SQLite 3.40 (`concat`, `unistr`, `string_agg`, `ORDER BY` inside an aggregate) unless it is registered in `Sqlite::connect()`.
- A new MySQL function in product SQL needs a portable spelling for PostgreSQL anyway; check that SQLite has it too (`date()`, `substr()`, `instr()`,
  `coalesce()`, `abs()`, `round()`, `length()`, `replace()`, `trim()` exist; `LEFT()`, `IF()`, `YEAR()`, `DATE_FORMAT()` do not).

## 5. Tests

- `TALEA_TEST_DB_DRIVER=sqlite vendor/bin/phpunit --testsuite integration` and `TALEA_TEST_DB_DRIVER=sqlite vendor/bin/paratest --testsuite site --processes 6`:
  no server; the databases are files in `TALEA_TEST_SQLITE_DIR` (default a folder in the temp dir). `composer test:sqlite` runs both.
- `Support\TestDatabase` creates a database by touching a file, clones the installed-site template by copying it (after a WAL checkpoint), drops it by
  deleting the file and its `-wal`, `-shm` and lock files. A test site links its `storage/database` to that folder, so the installer's file is the one
  the harness reads.
- The test connection gets the application's pragmas and functions plus MySQL functions only tests use (`SHA2`, `MD5`, `LEFT`, `FIND_IN_SET`,
  `DAYOFWEEK`, `UNIX_TIMESTAMP`, `CURDATE`, `JSON_UNQUOTE`, `JSON_LENGTH`); `TestSql` rewrites `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, `IF()`,
  `GROUP_CONCAT … SEPARATOR` and `DIV`, then applies `Sqlite::rewrite()`. `REPLACE INTO`, `IFNULL`, `JSON_EXTRACT`, `->>` and `TIME()` are SQLite's own.
- `tests/Unit/DialectSqliteTest.php` checks the SQL the dialect writes and its functions on a database in memory, so the unit suite covers SQLite even
  where no other suite runs on it.
