<?php

/**
 * Compares two databases through information_schema: tables, columns (type, nullability, default, extra, comment, position),
 * indexes (name, columns in order, uniqueness, type) and foreign keys (name, columns, target, rules).
 *
 *   php tools/compare-schemas.php --a=db_from_schema_sql --b=db_from_phinx [--host=127.0.0.1] [--port=3306] [--user=root] [--pass=]
 *        [--ignore=ka_migrations] [--collation] [--no-comments]
 *
 * Exit code 0 = identical, 1 = differences (printed). With --collation the character set and collation of tables and
 * columns are compared too; without it they are only reported as a summary, because a schema.sql written for another
 * collation is expected to differ there. --no-comments leaves column comments out (schema.sql keeps its notes as SQL comments).
 */

declare(strict_types=1);

$o = ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => '', 'ignore' => 'ka_migrations'];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([\w-]+)(?:=(.*))?$/', $arg, $m)) {
        $o[$m[1]] = $m[2] ?? true;
    }
}
if (!isset($o['a'], $o['b'])) {
    fwrite(STDERR, "usage: php tools/compare-schemas.php --a=<db> --b=<db> [--host= --port= --user= --pass= --ignore=t1,t2 --collation]\n");
    exit(2);
}
$ignore = array_filter(explode(',', (string) $o['ignore']));
$pdo = new PDO("mysql:host={$o['host']};port={$o['port']}", $o['user'], $o['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/** @return array<string, mixed> */
function describe(PDO $pdo, string $db, array $ignore, bool $collation, bool $noComments): array
{
    $q = fn (string $sql): array => (function () use ($pdo, $sql, $db): array {
        $s = $pdo->prepare($sql);
        $s->execute([$db]);

        return $s->fetchAll(PDO::FETCH_ASSOC);
    })();
    $skip = fn (string $t): bool => in_array($t, $ignore, true);
    $out = ['tables' => [], 'columns' => [], 'indexes' => [], 'foreign' => []];

    foreach ($q('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE"') as $r) {
        if (!$skip($r['TABLE_NAME'])) {
            $out['tables'][$r['TABLE_NAME']] = $r['ENGINE'] . ($collation ? ' ' . $r['TABLE_COLLATION'] : '');
        }
    }
    foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, COLLATION_NAME
                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
        if (!$skip($r['TABLE_NAME'])) {
            $out['columns']["{$r['TABLE_NAME']}.{$r['COLUMN_NAME']}"] = implode(' | ', [$r['ORDINAL_POSITION'], $r['COLUMN_TYPE'], $r['IS_NULLABLE'],
                $r['COLUMN_DEFAULT'] ?? 'NULL', $r['EXTRA'], $noComments ? '' : $r['COLUMN_COMMENT']]) . ($collation ? ' | ' . ($r['COLLATION_NAME'] ?? '-') : '');
        }
    }
    $idx = [];
    foreach ($q('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE, SUB_PART FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $r) {
        if (!$skip($r['TABLE_NAME'])) {
            $k = "{$r['TABLE_NAME']}.{$r['INDEX_NAME']}";
            $idx[$k]['head'] = ($r['NON_UNIQUE'] ? 'index' : 'unique') . ' ' . $r['INDEX_TYPE'];
            $idx[$k]['cols'][] = $r['COLUMN_NAME'] . ($r['SUB_PART'] !== null ? "({$r['SUB_PART']})" : '');
        }
    }
    foreach ($idx as $k => $i) {
        $out['indexes'][$k] = $i['head'] . ' (' . implode(', ', $i['cols']) . ')';
    }
    $fk = [];
    foreach ($q('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION') as $r) {
        if (!$skip($r['TABLE_NAME'])) {
            $fk["{$r['TABLE_NAME']}.{$r['CONSTRAINT_NAME']}"][] = "{$r['COLUMN_NAME']} -> {$r['REFERENCED_TABLE_NAME']}.{$r['REFERENCED_COLUMN_NAME']} del {$r['DELETE_RULE']} upd {$r['UPDATE_RULE']}";
        }
    }
    foreach ($fk as $k => $v) {
        $out['foreign'][$k] = implode('; ', $v);
    }

    return $out;
}

$withCollation = isset($o['collation']);
$noComments = isset($o['no-comments']);
$a = describe($pdo, (string) $o['a'], $ignore, $withCollation, $noComments);
$b = describe($pdo, (string) $o['b'], $ignore, $withCollation, $noComments);

$diff = 0;
foreach (['tables', 'columns', 'indexes', 'foreign'] as $kind) {
    foreach (array_unique([...array_keys($a[$kind]), ...array_keys($b[$kind])]) as $key) {
        if (($a[$kind][$key] ?? null) !== ($b[$kind][$key] ?? null)) {
            $diff++;
            echo sprintf("%-8s %s\n   a: %s\n   b: %s\n", $kind, $key, $a[$kind][$key] ?? '(missing)', $b[$kind][$key] ?? '(missing)');
        }
    }
}

echo sprintf("%d tables, %d columns, %d indexes, %d foreign keys compared: %s\n", count($a['tables']), count($a['columns']), count($a['indexes']), count($a['foreign']),
    $diff === 0 ? 'identical' : "$diff difference(s)");
exit($diff === 0 ? 0 : 1);
