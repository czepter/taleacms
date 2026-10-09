<?php

/**
 * Hard fork (issue #5): checks tools/rename/hard-fork-map.php against system/sql/schema.sql and sizes the rename.
 *
 *   php tools/hard-fork-inventory.php           checks the map, prints the findings, exit code 1 when there are errors
 *   php tools/hard-fork-inventory.php --usage   also counts the code references of every ambiguous column name
 *
 * Errors: a column that is neither mapped nor kept, a mapped column that is not in the schema, two columns of one table
 * with the same English name, a table in the map that is not in the schema. The ambiguity report lists Czech column names
 * that get different English names in different tables - the rename tool cannot decide those from the name alone.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$map = require $root . '/tools/rename/hard-fork-map.php';
$sql = (string) file_get_contents($root . '/system/sql/schema.sql');

/** @var array<string, list<string>> $schema table without prefix => columns */
$schema = [];
preg_match_all('/CREATE TABLE ka_(\w+) \((.*?)\n\) ENGINE/s', $sql, $tables, PREG_SET_ORDER);
foreach ($tables as $t) {
    foreach (explode("\n", $t[2]) as $line) {
        if (preg_match('/^\s{4}(?!PRIMARY|UNIQUE|KEY|FULLTEXT|CONSTRAINT)([a-z_0-9]+)\s+(?:INT|VARCHAR|CHAR|TEXT|MEDIUMTEXT|LONGTEXT|TINYINT|SMALLINT|BIGINT|DATETIME|DATE|BOOL|DECIMAL|TIMESTAMP|JSON)/i', $line, $c)) {
            $schema[$t[1]][] = $c[1];
        }
    }
}

$errors = [];
$keep = array_flip($map['keep']['*']);

foreach ($map['tables'] as $czech => $english) {
    if (!isset($schema[$czech])) {
        $errors[] = "table '$czech' is in the map but not in schema.sql";
    }
}
foreach (array_diff_key($map['columns'], $schema) as $table => $_) {
    $errors[] = "columns of '$table' are mapped but the table is not in schema.sql";
}

// Czech column names known from the map: in a table that already has an English name, an unmapped column is fine
// unless it is one of these (then the map forgot it).
$czechNames = [];
foreach ($map['columns'] as $m) {
    $czechNames += array_fill_keys(array_keys($m), true);
}

$unmapped = [];
foreach ($schema as $table => $columns) {
    $m = $map['columns'][$table] ?? [];
    $renamedTable = isset($map['tables'][$table]);
    $targets = [];
    foreach ($columns as $column) {
        if (isset($m[$column])) {
            $target = $m[$column];
        } elseif (isset($keep[$column]) || (!$renamedTable && !isset($czechNames[$column]))) {
            $target = $column;
        } else {
            $unmapped[$table][] = $column;
            $target = $column;
        }
        if (isset($targets[$target])) {
            $errors[] = "ka_$table: '{$targets[$target]}' and '$column' both become '$target'";
        }
        $targets[$target] = $column;
    }
    foreach (array_diff_key($m, array_flip($columns)) as $column => $_) {
        $errors[] = "ka_$table.$column is mapped but not in schema.sql";
    }
}
foreach ($unmapped as $table => $columns) {
    $errors[] = "ka_$table has unmapped columns (map them or add to 'keep'): " . implode(', ', $columns);
}

// every table that gets a public_id must exist in the schema (under its Czech or English name)
$english = array_merge(array_keys($schema), array_values($map['tables']));
foreach ($map['public_ids'] as $table) {
    if (!in_array($table, $english, true)) {
        $errors[] = "public_ids lists '$table', which is not a table";
    }
}

// ambiguous: one Czech column name, several English names
$byName = [];
foreach ($map['columns'] as $table => $m) {
    foreach ($m as $czech => $english) {
        $byName[$czech][$english][] = $table;
    }
}
$ambiguous = array_filter($byName, fn (array $e): bool => count($e) > 1);
ksort($ambiguous);

echo count($schema), " tables in schema.sql, ", count($map['tables']), " renamed; ", array_sum(array_map('count', $map['columns'])), " columns renamed; ",
    count($byName), " distinct Czech column names, ", count($ambiguous), " ambiguous\n\n";

echo "Ambiguous column names (the same Czech name means different things per table):\n";
$usage = in_array('--usage', $argv, true);
foreach ($ambiguous as $czech => $english) {
    $parts = [];
    foreach ($english as $name => $tables) {
        $parts[] = "$name <- " . implode('/', $tables);
    }
    $line = sprintf('  %-10s %s', $czech, implode('; ', $parts));
    if ($usage) {
        $n = (int) shell_exec('grep -rEow ' . escapeshellarg($czech) . ' ' . escapeshellarg($root . '/system/src') . ' ' . escapeshellarg($root . '/system/views') . ' | wc -l');
        $line .= "  [$n references]";
    }
    echo $line, "\n";
}

echo "\n";
if ($errors === []) {
    echo "OK: every column of every table is mapped or kept.\n";
    exit(0);
}
echo count($errors), " error(s):\n";
foreach ($errors as $e) {
    echo "  - $e\n";
}
exit(1);
