<?php

/**
 * Hard fork (issue #8): applies tools/rename/hard-fork-map.php to system/sql/schema.sql (tables, columns, keys, constraints, comments).
 * The result is then turned into the Phinx baseline by tools/schema-to-phinx.php.
 *
 *   php tools/hard-fork-schema.php            dry run: prints the renamed index and constraint names and what stayed Czech
 *   php tools/hard-fork-schema.php --apply    rewrites schema.sql
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$map = require $root . '/tools/rename/hard-fork-map.php';
$apply = in_array('--apply', $argv, true);
$sql = (string) file_get_contents($root . '/system/sql/schema.sql');

$tables = $map['tables'];
$columns = $map['columns'];

$renamedNames = [];
$usedNames = [];

/** ix_/uq_/ft_/fk_ + table + columns, at most 50 characters (a hash replaces the tail), unique per table (keys) or database (constraints). */
$generateName = function (string $kind, string $table, array $cols, string $old) use (&$renamedNames, &$usedNames): string {
    $base = $kind . '_' . $table . '_' . implode('_', $cols);
    if (strlen($base) > 50) {
        $base = substr($base, 0, 43) . '_' . substr(hash('crc32b', $base), 0, 6);
    }
    $scope = $kind === 'fk' ? 'db' : $table;
    $name = $base;
    for ($i = 2; isset($usedNames[$scope][$name]); $i++) {
        $name = substr($base, 0, 47) . '_' . $i;
    }
    $usedNames[$scope][$name] = true;
    if ($old !== '' && $old !== $name) {
        $renamedNames[$old] = $name;
    }

    return $name;
};
$stillCzech = [];

$out = [];
$current = null;
foreach (explode("\n", $sql) as $line) {
    if (preg_match('/^CREATE TABLE ka_(\w+) \(/', $line, $m)) {
        $current = $m[1];
        $line = preg_replace('/ka_\w+/', 'ka_' . ($tables[$current] ?? $current), $line, 1);
        $out[] = $line;
        continue;
    }
    if ($current !== null && preg_match('/^\) ENGINE/', $line)) {
        $current = null;
        $out[] = $line;
        continue;
    }
    if ($current !== null) {
        $cols = $columns[$current] ?? [];
        // column definition
        if (preg_match('/^(\s{4})(\w+)(\s+)(INT|BIGINT|SMALLINT|TINYINT|BOOL|VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|DATETIME|DATE|DECIMAL|TIMESTAMP)\b/', $line, $m)) {
            $line = $m[1] . ($cols[$m[2]] ?? $m[2]) . substr($line, strlen($m[1]) + strlen($m[2]));
        } elseif (preg_match('/^(\s{4})(UNIQUE KEY|FULLTEXT KEY|KEY)\s+(\w+)(\s*\()([^)]*)\)(.*)$/', $line, $m)) {
            $newCols = array_map(fn (string $c): string => $cols[trim($c)] ?? trim($c), explode(',', $m[5]));
            $kind = ['UNIQUE KEY' => 'uq', 'FULLTEXT KEY' => 'ft', 'KEY' => 'ix'][$m[2]];
            $line = $m[1] . $m[2] . ' ' . $generateName($kind, $tables[$current] ?? $current, $newCols, $m[3]) . $m[4] . implode(', ', $newCols) . ')' . $m[6];
        } elseif (preg_match('/^(\s{4}PRIMARY KEY\s*)\(([^)]*)\)(.*)$/', $line, $m)) {
            $line = $m[1] . '(' . implode(', ', array_map(fn (string $c): string => $cols[trim($c)] ?? trim($c), explode(',', $m[2]))) . ')' . $m[3];
        } elseif (preg_match('/^(\s{4}CONSTRAINT\s+)(\w+)(\s+FOREIGN KEY\s*\()(\w+)(\)\s*REFERENCES\s+ka_)(\w+)(\s*\()(\w+)(\).*)$/', $line, $m)) {
            $refTable = $m[6];
            $newCol = $cols[$m[4]] ?? $m[4];
            $line = $m[1] . $generateName('fk', $tables[$current] ?? $current, [$newCol], $m[2]) . $m[3] . $newCol . $m[5] . ($tables[$refTable] ?? $refTable) . $m[7] . (($columns[$refTable][$m[8]] ?? $m[8])) . $m[9];
        }
    }
    $out[] = $line;
}
$result = implode("\n", $out);

// comments that name a table or column (ka_novinky.idc, ka_kolekce_polozky.idp …)
$result = (string) preg_replace_callback('/\bka_([a-z_]+)\.([a-z_]+)\b/', function (array $m) use ($tables, $columns): string {
    return 'ka_' . ($tables[$m[1]] ?? $m[1]) . '.' . ($columns[$m[1]][$m[2]] ?? $m[2]);
}, $result);
$result = (string) preg_replace_callback('/\bka_([a-z_]+)\b/', fn (array $m): string => 'ka_' . ($tables[$m[1]] ?? $m[1]), $result);

echo count($renamedNames), " index/constraint names renamed:\n";
foreach ($renamedNames as $a => $b) {
    echo "  $a -> $b\n";
}

if ($apply) {
    file_put_contents($root . '/system/sql/schema.sql', $result);
    echo "\nschema.sql rewritten.\n";
}
