#!/usr/bin/env php
<?php

/**
 * Inventory of MySQL-only SQL: `php tools/sql-dialect-scan.php [--summary] [--category=<key>] [--json]`.
 *
 * Scans system/src, system/views, bin and tools line by line (comment lines are skipped) for constructs PostgreSQL does not
 * understand, by category, and prints the counts and the files. The dialect layer (system/src/Core/Dialect, MigrationSupport)
 * is where such SQL belongs and is not scanned. docs/specs/postgres.md says how each category is converted.
 * New SQL must use the helpers of Db::dialect(); the counts only go down.
 *
 * Counts are lines that match, not statements: the point is the size of the work and where it is, not an exact number.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$root = dirname(__DIR__);
$self = 'tools/sql-dialect-scan.php';
$skip = ['system/src/Core/Dialect/', 'system/src/Core/MigrationSupport.php', 'tools/sql-dialect-scan.php'];

/** boolean columns of the schema: PostgreSQL does not compare them with 0 and 1. */
$booleans = [];
foreach (glob($root . '/system/database/migrations/*.php') ?: [] as $file) {
    preg_match_all("/addColumn\('(\w+)', 'boolean'/", (string) file_get_contents($file), $m);
    $booleans = array_merge($booleans, $m[1]);
}
$booleans = array_values(array_unique($booleans));
$booleanColumn = '(?:\w+\.)?(?:' . implode('|', array_map('preg_quote', $booleans)) . ')';

/**
 * key => [title, regex, convert (how the next steps handle it)]; 'portable' categories work on both engines and are listed for information.
 *
 * @var array<string, array{0: string, 1: string, 2: string}>
 */
$categories = [
    'upsert' => ['ON DUPLICATE KEY UPDATE', '/ON\s+DUPLICATE\s+KEY/i', 'Db::upsert() / Dialect::upsert()'],
    'insert_ignore' => ['INSERT IGNORE', '/INSERT\s+IGNORE/i', 'Db::insertIgnore()'],
    'replace_into' => ['REPLACE INTO', '/REPLACE\s+INTO/i', 'Db::upsert() (REPLACE deletes and inserts: check the foreign keys)'],
    'json' => ['JSON_* functions', '/\bJSON_[A-Z_]+\s*\(/', 'Dialect::jsonExtract(); JSON_SET/ARRAYAGG etc. need their own helper'],
    'interval' => ['INTERVAL arithmetic', '/\bINTERVAL\s+(?:\?|\d+|\w+)\s+(?:SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b/i', 'Dialect::interval()'],
    'date_functions' => ['CURDATE / UNIX_TIMESTAMP / FROM_UNIXTIME / DATE_FORMAT / DATE_ADD / DATE_SUB / TIMESTAMPDIFF / DATEDIFF', '/\b(?:CURDATE|UNIX_TIMESTAMP|FROM_UNIXTIME|DATE_FORMAT|DATE_ADD|DATE_SUB|TIMESTAMPDIFF|DATEDIFF|STR_TO_DATE)\s*\(/', 'Dialect::unixTime(), CURRENT_DATE, or compute the date in PHP'],
    'now' => ['NOW() (portable: the session time zone is set on both engines)', '/\bNOW\s*\(\)/i', 'nothing; Dialect::now() when it sits next to an interval'],
    'group_concat' => ['GROUP_CONCAT', '/\bGROUP_CONCAT\s*\(/i', 'Dialect::groupConcat()'],
    'locks' => ['GET_LOCK / RELEASE_LOCK', '/\b(?:GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK)\s*\(/i', 'Db::lock() / Db::unlock()'],
    'fulltext' => ['MATCH ... AGAINST / FULLTEXT', '/\bMATCH\s*\(.*AGAINST|\bFULLTEXT\b/i', 'Dialect::fulltextMatch() + fulltextQuery()'],
    'backticks' => ['backtick quoting (Db rewrites them for PostgreSQL; use Dialect::quote() in new code)', '/`[A-Za-z_][A-Za-z0-9_.]*`/', 'covered by the rewrite shim; replace when touching the line'],
    'limit_comma' => ['LIMIT x, y', '/\bLIMIT\s+(?:\d+|\?|\$\w+)\s*,\s*(?:\d+|\?|\$\w+)/i', 'LIMIT y OFFSET x / Dialect::limit()'],
    'limit_write' => ['UPDATE / DELETE with LIMIT or ORDER BY', '/\b(?:UPDATE|DELETE\s+FROM)\b[^;]*\bLIMIT\b/i', 'select the keys first (WHERE id IN (SELECT ... LIMIT n))'],
    'multi_table' => ['UPDATE / DELETE with JOIN', '/\bUPDATE\s+\{?\w+\}?(?:\s+\w+)?\s+(?:INNER\s+|LEFT\s+)?JOIN\b|\bDELETE\s+\w+\s+FROM\b/i', 'UPDATE ... FROM / DELETE ... USING, or a subquery'],
    'show' => ['SHOW ...', '/\bSHOW\s+(?:TABLES|COLUMNS|FULL|CREATE|INDEX|INDEXES|KEYS|VARIABLES|STATUS|DATABASES|TABLE|GRANTS|WARNINGS|ENGINE)\b/i', 'information_schema or pg_catalog behind a Db method'],
    'information_schema' => ['information_schema', '/information_schema/i', 'Db::tableExists() or a catalogue method (table_schema = DATABASE() differs)'],
    'if_ifnull' => ['IF() / IFNULL()', '/\bIF\s*\((?!\s*\$)|\bIFNULL\s*\(/', 'CASE WHEN / COALESCE'],
    'find_in_set' => ['FIND_IN_SET / FIELD / LOCATE / SUBSTRING_INDEX / REGEXP / RLIKE', '/\b(?:FIND_IN_SET|FIELD|LOCATE|SUBSTRING_INDEX|REGEXP|RLIKE|ELT)\b\s*\(?/', 'per case: = ANY(string_to_array()), position(), regexp operators'],
    'database_fn' => ['DATABASE() / LAST_INSERT_ID() / FOUND_ROWS()', '/\b(?:DATABASE|LAST_INSERT_ID|FOUND_ROWS|ROW_COUNT)\s*\(|SQL_CALC_FOUND_ROWS/i', 'Db::databaseName(), Db::insert() returns the key'],
    'concat_null' => ['CONCAT() (NULL gives NULL in MySQL, is skipped in PostgreSQL)', '/\bCONCAT(?:_WS)?\s*\(/i', 'check each: COALESCE the arguments or use ||'],
    'rand' => ['RAND()', '/\bRAND\s*\(/i', 'random() through a Dialect helper'],
    'auto_increment' => ['AUTO_INCREMENT / ENGINE= / ON UPDATE CURRENT_TIMESTAMP', '/AUTO_INCREMENT|\bENGINE\s*=|ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', 'only in migrations (Phinx identity)'],
    'ddl_maintenance' => ['TRUNCATE / OPTIMIZE / ANALYZE / FOREIGN_KEY_CHECKS / ALTER TABLE', '/\b(?:OPTIMIZE|ANALYZE|REPAIR)\s+TABLE\b|FOREIGN_KEY_CHECKS|\bALTER\s+TABLE\b|\bTRUNCATE\b/i', 'per case (SET session_replication_role, TRUNCATE ... CASCADE)'],
    'boolean_literal' => ['boolean column compared or summed as a number (= 1, = 0, SUM(col))', '/\b' . $booleanColumn . '\s*(?:=|<>|!=)\s*[01]\b|\b(?:SUM|AVG|MAX|MIN)\s*\(\s*' . $booleanColumn . '\s*\)/', 'compare with TRUE/FALSE or cast; (col)::int in sums'],
    'collation_like' => ['LIKE / COLLATE (case- and accent-insensitive by default in MySQL)', '/\bLIKE\s+[?\'"]|\bCOLLATE\b/', 'Dialect::likeInsensitive() for user-facing text, the lookup columns carry talea_ci'],
    'mysql_client' => ['MySQL PHP / command line specifics (mysqldump, MYSQL_ATTR, mysqli)', '/mysqldump|MYSQL_ATTR|mysqli|\bmysql\s+-|pdo_mysql/i', 'Backup and restore need a pg_dump counterpart'],
];
$portable = ['now' => true];

$only = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--category=')) {
        $only = substr($arg, 11);
    }
}
$summary = in_array('--summary', $argv, true);
$json = in_array('--json', $argv, true);

$files = [];
foreach (['system/src', 'system/views', 'bin', 'tools'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr((string) $file, strlen($root) + 1);
        $isScript = $file->getExtension() === 'php' || ($dir === 'bin' && $file->isFile());
        if (!$isScript || array_filter($skip, fn (string $s): bool => str_starts_with($relative, $s)) !== []) {
            continue;
        }
        $files[] = $relative;
    }
}
sort($files);

/** @var array<string, array<string, int>> $found category => file => lines */
$found = array_fill_keys(array_keys($categories), []);
foreach ($files as $relative) {
    foreach (file($root . '/' . $relative, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }
        foreach ($categories as $key => [, $regex]) {
            if (preg_match($regex, $line) === 1) {
                $found[$key][$relative] = ($found[$key][$relative] ?? 0) + 1;
            }
        }
    }
}

$total = 0;
$result = [];
foreach ($categories as $key => [$title, , $convert]) {
    $count = array_sum($found[$key]);
    $total += isset($portable[$key]) ? 0 : $count;
    $result[$key] = ['title' => $title, 'lines' => $count, 'files' => count($found[$key]), 'convert' => $convert, 'portable' => isset($portable[$key]), 'by_file' => $found[$key]];
}

if ($json) {
    echo json_encode(['total' => $total, 'categories' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
foreach ($result as $key => $r) {
    if ($only !== null && $only !== $key) {
        continue;
    }
    printf("%6d lines  %3d files  %-18s %s%s\n", $r['lines'], $r['files'], $key, $r['title'], $r['portable'] ? '' : '');
    if (!$summary && $r['lines'] > 0) {
        arsort($r['by_file']);
        foreach ($r['by_file'] as $file => $n) {
            printf("            %4d  %s\n", $n, $file);
        }
    }
}
printf("\n%d lines of MySQL-only SQL in %d files scanned (portable categories not counted).\n", $total, count($files));
