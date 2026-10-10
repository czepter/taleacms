<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

use Talea\Core\Dialect\Postgres;
use Talea\Tests\Support\TestDatabase;

/**
 * The SQL a site test writes to look at or prepare the database (`Site::value/rows/exec`) is MySQL SQL: GROUP_CONCAT, IF(), IFNULL, JSON_EXTRACT,
 * `visible = 1`. On PostgreSQL this turns it into the equivalent, so a test keeps saying what it checks and not how the engine spells it.
 * Not a general translator: the constructs the tests use, nothing more. What cannot be translated (a boolean printed inside CONCAT, REPLACE INTO,
 * a multi-table DELETE) is written portable in the test, or branches on TestDatabase::isPostgres() with a comment.
 * (The product's own SQL never goes through this: it is portable by itself, see tests/Unit/NoMysqlOnlySqlTest.php.)
 */
final class TestSql
{
    private const string BOOLEANS = 'active|blocked|breakpoint|closed|detail|in_menu|manage_updates|noindex|ok|pinned|proposed|requires_confirmation|silent_reported|up|visible';

    /** @param ?\PDO $pdo the connection of the site: INSERT … ON DUPLICATE KEY UPDATE and REPLACE INTO look up the table's unique key there */
    public static function forEngine(string $sql, ?\PDO $pdo = null): string
    {
        if (!TestDatabase::isPostgres()) {
            return $sql;
        }
        $sql = self::insertBooleans($sql);
        $sql = self::duplicateKey($sql, $pdo);
        $sql = preg_replace('/(\b(?:\w+\.)?\w+)\s*->>\s*\'(\$[^\']*)\'/', 'JSON_EXTRACT($1, \'$2\')', $sql) ?? $sql; // data->>'$.key'
        $sql = self::calls($sql, 'DAYOFWEEK', fn (array $a): string => '(EXTRACT(DOW FROM ' . $a[0] . ')::int + 1)');
        $sql = self::calls($sql, 'TIME', fn (array $a): string => "to_char({$a[0]}, 'HH24:MI:SS')");
        $sql = self::calls($sql, 'LEFT', fn (array $a): string => "LEFT(({$a[0]})::text, {$a[1]})");
        $sql = self::calls($sql, 'CONCAT', self::concat(...));
        $sql = self::calls($sql, 'CONCAT_WS', fn (array $a): string => 'CONCAT_WS(' . implode(', ', array_map(self::asNumber(...), $a)) . ')');
        $sql = self::calls($sql, 'SUM', fn (array $a): string => 'SUM(' . self::asNumber($a[0]) . ')');
        $sql = self::calls($sql, 'MAX', fn (array $a): string => 'MAX(' . self::asNumber($a[0]) . ')');
        $sql = self::calls($sql, 'MIN', fn (array $a): string => 'MIN(' . self::asNumber($a[0]) . ')');
        $sql = preg_replace('/\)\s+DIV\s+/i', ') / ', $sql) ?? $sql; // integer division of two integers
        $sql = preg_replace('/(\b\w*_id\s*(?:=|<>|!=)\s*)\((SELECT value FROM tl_settings[^)]*)\)/i', '$1CAST(($2) AS integer)', $sql) ?? $sql; // a setting is text, a key is a number
        $sql = preg_replace('/\s+FROM\s+DUAL\b/i', '', $sql) ?? $sql;
        $sql = self::calls($sql, 'IFNULL', self::coalesce(...));
        $sql = self::calls($sql, 'COALESCE', self::coalesce(...));
        $sql = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $sql) ?? $sql;
        $sql = preg_replace('/table_schema\s*=\s*DATABASE\s*\(\s*\)/i', 'table_schema = current_schema()', $sql) ?? $sql;
        $sql = preg_replace('/\bDATABASE\s*\(\s*\)/i', 'current_database()', $sql) ?? $sql;
        $col = '((?:\w+\.)?(?:' . self::BOOLEANS . '))';
        $sql = preg_replace('/\b' . $col . '\s*=\s*1\b(?![.\d])/', '$1 = TRUE', $sql) ?? $sql;
        $sql = preg_replace('/\b' . $col . '\s*=\s*0\b(?![.\d])/', '$1 = FALSE', $sql) ?? $sql;
        $sql = preg_replace('/\b' . $col . '\s*(?:<>|!=)\s*0\b(?![.\d])/', '$1 = TRUE', $sql) ?? $sql;
        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO/i', $sql) === 1) {
            $sql = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO/i', 'INSERT INTO', $sql) . ' ON CONFLICT DO NOTHING';
        }
        $sql = self::calls($sql, 'UNIX_TIMESTAMP', fn (array $a): string => 'EXTRACT(EPOCH FROM ' . (($a[0] ?? '') !== '' ? $a[0] : 'NOW()') . ')::bigint');
        $sql = self::calls($sql, 'SHA2', fn (array $a): string => "encode(sha256(convert_to(CAST({$a[0]} AS text), 'UTF8')), 'hex')");
        $sql = self::calls($sql, 'FIND_IN_SET', fn (array $a): string => "COALESCE(array_position(string_to_array({$a[1]}, ','), CAST({$a[0]} AS text)), 0)");
        $sql = self::calls($sql, 'JSON_LENGTH', fn (array $a): string => 'jsonb_array_length((' . $a[0] . ')::jsonb' . (isset($a[1]) ? ' #> ' . self::pathArray($a[1]) : '') . ')');
        $sql = preg_replace('/\bJSON_OBJECT\s*\(/i', 'jsonb_build_object(', $sql) ?? $sql;
        $sql = self::calls($sql, 'JSON_SET', fn (array $a): string => 'jsonb_set((' . $a[0] . ')::jsonb, ' . self::pathArray($a[1]) . ', to_jsonb(' . (str_starts_with($a[2], "'") ? $a[2] . '::text' : $a[2]) . '))::text'); // one path, a scalar value
        $sql = self::calls($sql, 'JSON_UNQUOTE', fn (array $a): string => $a[0]);
        $sql = self::calls($sql, 'JSON_EXTRACT', fn (array $a): string => '((' . $a[0] . ')::jsonb #>> ' . self::pathArray($a[1]) . ')');
        $sql = self::calls($sql, 'IF', fn (array $a): string => "CASE WHEN {$a[0]} THEN {$a[1]} ELSE {$a[2]} END");
        $sql = self::calls($sql, 'GROUP_CONCAT', self::groupConcat(...));

        return (new Postgres())->rewrite($sql);
    }

    /** INSERT INTO t (cols) VALUES (…), (…): a literal 0 or 1 under a boolean column becomes FALSE or TRUE. */
    private static function insertBooleans(string $sql): string
    {
        if (preg_match('/^(\s*INSERT\s+(?:IGNORE\s+)?INTO\s+\w+\s*\(([^)]*)\)\s*VALUES\s*)(\(.*)$/is', $sql, $m) !== 1) {
            return $sql;
        }
        $columns = array_map('trim', explode(',', $m[2]));
        $booleans = array_keys(array_filter($columns, fn (string $c): bool => preg_match('/^(?:' . self::BOOLEANS . ')$/', $c) === 1));
        if ($booleans === []) {
            return $sql;
        }
        $rest = $m[3];
        $out = '';
        $i = 0;
        $n = strlen($rest);
        while ($i < $n) {
            if ($rest[$i] !== '(') {
                $out .= $rest[$i++];
                continue;
            }
            $depth = 0;
            $quote = '';
            $values = [];
            $start = $i + 1;
            for (; $i < $n; $i++) {
                $c = $rest[$i];
                if ($quote !== '') {
                    $quote = $c === $quote ? '' : $quote;
                } elseif ($c === "'" || $c === '"') {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    $values[] = substr($rest, $start, $i - $start);
                    $i++;
                    break;
                } elseif ($c === ',' && $depth === 1) {
                    $values[] = substr($rest, $start, $i - $start);
                    $start = $i + 1;
                }
            }
            foreach ($booleans as $index) {
                if (isset($values[$index]) && in_array(trim($values[$index]), ['0', '1'], true)) {
                    $values[$index] = trim($values[$index]) === '1' ? 'TRUE' : 'FALSE';
                }
            }
            $out .= '(' . implode(',', $values) . ')';
        }

        return $m[1] . $out;
    }

    /** CONCAT: a boolean (column or predicate) prints as 1 or 0, as in MySQL. @param list<string> $args */
    private static function concat(array $args): string
    {
        return 'CONCAT(' . implode(', ', array_map(fn (string $a): string => trim($a) === '?' ? 'CAST(? AS text)' : self::asNumber($a), $args)) . ')'; // a bare parameter has no type in a variadic call
    }

    /** IFNULL/COALESCE: a boolean first argument is a number (as in MySQL), a text default makes the first argument text (a date, a number). @param list<string> $args */
    private static function coalesce(array $args): string
    {
        $args[0] = self::asNumber($args[0]);
        if (isset($args[1]) && preg_match("/^'[^']*'$/", $args[1]) === 1 && preg_match("/^'|^\\(*SELECT\\s/i", $args[0]) !== 1) {
            $args[0] = '(' . $args[0] . ')::text';
        }

        return 'COALESCE(' . implode(', ', $args) . ')';
    }

    /** A boolean column or predicate as 1/0 (MySQL's booleans are numbers); anything else as it is. */
    private static function asNumber(string $expr): string
    {
        $e = trim($expr);
        // (SELECT <predicate or boolean column> FROM …): the select item becomes the number
        if (preg_match('/^\(\s*SELECT\s+/i', $e, $m) === 1 && substr($e, -1) === ')' && ($from = self::topLevel(substr($e, 1, -1), ' FROM ')) !== null) {
            $inner = substr($e, 1, -1);
            $head = trim(substr($inner, strlen($m[0]) - 1, $from - strlen($m[0]) + 1));
            $number = self::asNumber($head);

            return $number === $head ? $e : '(SELECT ' . $number . substr($inner, $from) . ')';
        }
        $predicate = preg_match('/^\(.*\)\s*(?:=|<>|!=|<=|>=|<|>)\s*[\w\']+$/s', $e) === 1 || self::topLevel($e, ' IS ') !== null || self::topLevel($e, ' LIKE ') !== null || self::topLevel($e, ' AND ') !== null || self::topLevel($e, ' OR ') !== null
            || preg_match('/[^<>!=]\s*(=|<>|!=|<=|>=|<|>)\s*[^=]/', self::masked($e)) === 1;
        if ($predicate || preg_match('/^(?:\w+\.)?(?:' . self::BOOLEANS . ')$/', $e) === 1) {
            return 'CASE WHEN ' . $e . ' THEN 1 ELSE 0 END';
        }

        return $e;
    }

    /** INSERT … ON DUPLICATE KEY UPDATE and REPLACE INTO as ON CONFLICT (the unique key of the table is asked of the database). */
    private static function duplicateKey(string $sql, ?\PDO $pdo): string
    {
        $replace = preg_match('/^\s*REPLACE\s+INTO\s+(\w+)/i', $sql, $m) === 1;
        $duplicate = !$replace && preg_match('/^\s*INSERT\s+INTO\s+(\w+)[\s\S]*\sON\s+DUPLICATE\s+KEY\s+UPDATE\s/i', $sql, $m) === 1;
        if (!($replace || $duplicate) || $pdo === null) {
            return $sql;
        }
        $table = $m[1];
        $columns = preg_match('/^\s*(?:REPLACE|INSERT)\s+INTO\s+\w+\s*\(([^)]*)\)/i', $sql, $c) === 1 ? array_map('trim', explode(',', $c[1])) : null;
        $dialect = new Postgres();
        $stmt = $pdo->prepare($dialect->columnsSql());
        $stmt->execute([$table]);
        $all = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $columns ??= $all;
        $stmt = $pdo->prepare($dialect->uniqueKeysSql());
        $stmt->execute([$table]);
        $keys = [];
        foreach ($stmt->fetchAll() as $r) {
            $keys[$r['key_name']][] = $r['column_name'];
        }
        $key = null;
        foreach ($keys as $k) {
            if (array_diff($k, $columns) === []) {
                $key = $k;
                break;
            }
        }
        if ($key === null) {
            return $sql;
        }
        $target = ' ON CONFLICT (' . implode(', ', $key) . ') DO ';
        if ($replace) {
            $sql = preg_replace('/^\s*REPLACE\s+INTO/i', 'INSERT INTO', $sql) ?? $sql;
            $set = array_map(fn (string $c): string => "$c = EXCLUDED.$c", array_values(array_diff($columns, $key)));

            return $sql . $target . ($set === [] ? 'NOTHING' : 'UPDATE SET ' . implode(', ', $set));
        }

        return preg_replace_callback('/\sON\s+DUPLICATE\s+KEY\s+UPDATE\s([\s\S]*)$/i', fn (array $u): string => $target . 'UPDATE SET ' . preg_replace('/VALUES\s*\(\s*(\w+)\s*\)/i', 'EXCLUDED.$1', $u[1]), $sql) ?? $sql;
    }

    /** `'$.a.b[0]'` (as written in SQL, with its quotes) -> `ARRAY['a','b','0']` */
    private static function pathArray(string $quoted): string
    {
        $path = trim($quoted, " '\"");
        $keys = [];
        foreach (explode('.', preg_replace('/^\$\.?/', '', $path) ?? '') as $part) {
            if (preg_match('/^([^\[]*)((?:\[\d+\])*)$/', $part, $m) === 1) {
                if ($m[1] !== '') {
                    $keys[] = $m[1];
                }
                preg_match_all('/\[(\d+)\]/', $m[2], $i);
                foreach ($i[1] as $index) {
                    $keys[] = $index;
                }
            }
        }

        return "ARRAY['" . implode("','", $keys) . "']";
    }

    /** GROUP_CONCAT([DISTINCT] expr [ORDER BY o [ASC|DESC]] [SEPARATOR 's']) @param list<string> $args */
    private static function groupConcat(array $args): string
    {
        $inside = implode(',', $args);
        $separator = "','";
        if (preg_match('/\s+SEPARATOR\s+(\'[^\']*\')\s*$/i', $inside, $m) === 1) {
            $separator = $m[1];
            $inside = substr($inside, 0, -strlen($m[0]));
        }
        $order = '';
        if (($pos = self::topLevel($inside, ' ORDER BY ')) !== null) {
            $order = ' ORDER BY ' . trim(substr($inside, $pos + 10));
            $inside = substr($inside, 0, $pos);
        }
        $distinct = preg_match('/^\s*DISTINCT\s+/i', $inside) === 1;
        $expr = self::asNumber(trim((string) preg_replace('/^\s*DISTINCT\s+/i', '', $inside))); // a boolean prints as 1/0
        if ($distinct && $order !== '') {
            $order = ' ORDER BY (' . $expr . ')::text'; // PostgreSQL: the ORDER BY of a DISTINCT aggregate is the argument itself (the tests sort by it)
        }

        return 'string_agg(' . ($distinct ? 'DISTINCT ' : '') . '(' . $expr . ')::text, ' . $separator . $order . ')';
    }

    /** The expression with quoted text and parenthesised parts blanked (what is left is the top level). */
    private static function masked(string $s): string
    {
        $out = '';
        $depth = 0;
        $quote = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote !== '') {
                $quote = $c === $quote ? '' : $quote;
                $out .= '_';
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
                $out .= '_';
            } elseif ($c === '(') {
                $depth++;
                $out .= '_';
            } elseif ($c === ')') {
                $depth--;
                $out .= '_';
            } else {
                $out .= $depth > 0 ? '_' : $c;
            }
        }

        return $out;
    }

    /** Position of $needle outside parentheses and quotes, or null. */
    private static function topLevel(string $s, string $needle): ?int
    {
        $depth = 0;
        $quote = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($quote !== '') {
                $quote = $c === $quote ? '' : $quote;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($depth === 0 && strcasecmp(substr($s, $i, strlen($needle)), $needle) === 0) {
                return $i;
            }
        }

        return null;
    }

    /** Replaces every call NAME(args) (innermost last, so nesting works) with what $make returns for the argument list. @param \Closure(list<string>): string $make */
    private static function calls(string $sql, string $name, \Closure $make): string
    {
        $offset = 0;
        while (preg_match('/(?<![\w.])' . $name . '\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];
            $open = $start + strlen($m[0][0]);
            $depth = 1;
            $quote = '';
            $args = [];
            $argStart = $open;
            for ($i = $open, $n = strlen($sql); $i < $n && $depth > 0; $i++) {
                $c = $sql[$i];
                if ($quote !== '') {
                    $quote = $c === $quote ? '' : $quote;
                } elseif ($c === "'" || $c === '"') {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $args[] = substr($sql, $argStart, $i - $argStart);
                    }
                } elseif ($c === ',' && $depth === 1 && $name !== 'GROUP_CONCAT') {
                    $args[] = substr($sql, $argStart, $i - $argStart);
                    $argStart = $i + 1;
                }
            }
            if ($depth !== 0) {
                return $sql;
            }
            $args = array_map('trim', $args);
            // the arguments may hold calls of the same name: translate them first (the outer call needs their result)
            $args = array_map(fn (string $a): string => stripos($a, $name . '(') !== false ? self::calls($a, $name, $make) : $a, $args);
            $replacement = $make($args);
            $sql = substr($sql, 0, $start) . $replacement . substr($sql, $i);
            $offset = $start + strlen($replacement);
        }

        return $sql;
    }
}
