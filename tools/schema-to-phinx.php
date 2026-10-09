<?php

/**
 * Hard fork (issue #7/#8): turns system/sql/schema.sql into Phinx migrations in the table DSL (no raw SQL).
 *
 *   php tools/schema-to-phinx.php [--out=system/database/migrations]
 *
 * One file per group of tables, with consecutive timestamps, written in an order where every foreign key points at a table
 * created earlier (the script stops when a group order cannot satisfy that). Table names are written without the prefix:
 * Phinx adds it (`table_prefix` in phinx.php). Charset and collation are never written - they come from the database.
 * Prove the result with tools/compare-schemas.php (a database built from schema.sql against one built by `phinx migrate`).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$out = $root . '/system/database/migrations';
foreach ($argv as $a) {
    if (str_starts_with($a, '--out=')) {
        $out = $root . '/' . substr($a, 6);
    }
}

/** Table (without prefix) => group; the order of the groups is the order of the files. */
const GROUPS = [
    'identity' => ['role', 'uzivatele', 'uzivatele_prava', 'uzivatele_klice', 'api_tokeny', 'oauth_klienti', 'oauth_kody', 'kontrola_ip', 'firewall_blocks', 'firewall_log'],
    'content' => ['kategorie', 'novinky', 'stitky', 'novinky_stitky', 'novinky_revize', 'novinky_koncepty', 'stranky', 'stranky_revize', 'casti', 'menu', 'presmerovani',
        'nenalezeno', 'odkazy_vadne', 'import_mapa', 'notebook', 'draft_comments'],
    'media' => ['media_slozky', 'media', 'media_pouziti'],
    'builder' => ['stavba_revize', 'tridy', 'sekce', 'komponenty', 'popupy', 'kolekce', 'kolekce_polozky', 'kolekce_sablony', 'document_versions', 'document_downloads',
        'look_versions', 'blueprints'],
    'forms' => ['poptavky', 'souhlasy', 'odberatele', 'odber_fronta', 'newsletters', 'newsletter_queue', 'testimonial_requests', 'requests', 'request_messages',
        'whistleblowing_cases', 'whistleblowing_messages'],
    'bookings' => ['booking_services', 'booking_staff', 'booking_staff_services', 'booking_hours', 'booking_off', 'bookings', 'booking_proposals'],
    'stats' => ['stat_dny', 'stat_navstevnici', 'stat_novinky', 'stat_stranky', 'stat_kampane', 'stat_zarizeni', 'stat_konverze', 'stat_zdroje', 'web_vitals', 'search_stats',
        'social_drafts', 'google_reviews'],
    'system' => ['nastaveni', 'protokol', 'posta', 'events', 'jobs', 'webhook_deliveries', 'connectors', 'connector_queue', 'connector_log', 'notice_log', 'facts', 'fact_history',
        'hours_exceptions', 'fleet_sites', 'fleet_pairing', 'fleet_kits', 'agent_sessions', 'agent_journal', 'agent_schedules', 'agent_runs'],
];

$sql = (string) file_get_contents($root . '/system/sql/schema.sql');
preg_match_all('/CREATE TABLE ka_(\w+) \((.*?)\n\) ENGINE/s', $sql, $matches, PREG_SET_ORDER);

$tables = [];
foreach ($matches as $m) {
    $tables[$m[1]] = parseTable($m[1], $m[2]);
}

$groupOf = [];
foreach (GROUPS as $group => $names) {
    foreach ($names as $name) {
        $groupOf[$name] = $group;
    }
}
$missing = array_diff(array_keys($tables), array_keys($groupOf));
$extra = array_diff(array_keys($groupOf), array_keys($tables));
if ($missing !== [] || $extra !== []) {
    fwrite(STDERR, 'Tables without a group: ' . implode(', ', $missing) . '; groups naming unknown tables: ' . implode(', ', $extra) . "\n");
    exit(1);
}

// order inside a group: a referenced table first (stable otherwise)
$files = [];
foreach (GROUPS as $group => $names) {
    $sorted = [];
    $visit = function (string $t) use (&$visit, &$sorted, $tables, $groupOf, $group, $names): void {
        if (in_array($t, $sorted, true)) {
            return;
        }
        foreach ($tables[$t]['foreign'] as $fk) {
            if ($fk['table'] !== $t && ($groupOf[$fk['table']] ?? '') === $group) {
                $visit($fk['table']);
            }
        }
        $sorted[] = $t;
    };
    foreach ($names as $t) {
        $visit($t);
    }
    $files[$group] = $sorted;
}

// every foreign key must point at the same or an earlier file
$fileIndex = array_flip(array_keys(GROUPS));
foreach ($tables as $t => $def) {
    foreach ($def['foreign'] as $fk) {
        if ($fileIndex[$groupOf[$fk['table']]] > $fileIndex[$groupOf[$t]]) {
            fwrite(STDERR, "ka_$t references ka_{$fk['table']}, which is created in a later file ({$groupOf[$fk['table']]} after {$groupOf[$t]}) - reorder GROUPS\n");
            exit(1);
        }
    }
}

if (!is_dir($out)) {
    mkdir($out, 0775, true);
}
foreach (glob($out . '/*_create_*_tables.php') ?: [] as $old) {
    unlink($old);
}

$stamp = strtotime('2026-10-09 12:00:00');
$n = 0;
foreach ($files as $group => $names) {
    $time = date('YmdHis', $stamp + $n++);
    $class = 'Create' . ucfirst($group) . 'Tables';
    $body = '';
    foreach ($names as $t) {
        $body .= renderTable($t, $tables[$t]);
    }
    $code = "<?php\n\ndeclare(strict_types=1);\n\nuse Phinx\\Db\\Adapter\\MysqlAdapter;\nuse Phinx\\Migration\\AbstractMigration;\n\n"
        . "/** Generated by tools/schema-to-phinx.php from system/sql/schema.sql. */\nfinal class $class extends AbstractMigration\n{\n    public function change(): void\n    {\n"
        . "        \$prefix = (string) \$this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database\n\n"
        . $body . "    }\n}\n";
    file_put_contents("$out/{$time}_create_{$group}_tables.php", $code);
    echo "$time create_{$group}_tables  (" . count($names) . " tables)\n";
}

/** @return array{columns: list<array<string, mixed>>, primary: list<string>, indexes: list<array<string, mixed>>, foreign: list<array<string, mixed>>} */
function parseTable(string $name, string $block): array
{
    $def = ['columns' => [], 'primary' => [], 'indexes' => [], 'foreign' => []];
    foreach (explode("\n", $block) as $raw) {
        $line = rtrim($raw);
        if (trim($line) === '' || str_starts_with(trim($line), '--')) {
            continue;
        }
        $comment = '';
        if (preg_match('/^(.*?),?\s+--\s?(.*)$/', $line, $c) && !str_contains($c[1], "'--")) {
            $line = $c[1];
            $comment = trim($c[2]);
        }
        $line = str_replace('`', '', rtrim(trim($line), ','));
        if (preg_match('/^PRIMARY KEY \(([^)]+)\)$/i', $line, $m)) {
            $def['primary'] = columnList($m[1]);
        } elseif (preg_match('/^(UNIQUE KEY|FULLTEXT KEY|KEY) (\w+) \(([^)]+)\)$/i', $line, $m)) {
            $kind = strtoupper($m[1]);
            $def['indexes'][] = ['name' => $m[2], 'columns' => columnList($m[3]), 'unique' => $kind === 'UNIQUE KEY', 'fulltext' => $kind === 'FULLTEXT KEY'];
        } elseif (preg_match('/^CONSTRAINT (\w+)\s+FOREIGN KEY \((\w+)\)\s+REFERENCES ka_(\w+) \((\w+)\)(?: ON DELETE (CASCADE|SET NULL|RESTRICT|NO ACTION))?(?: ON UPDATE (CASCADE|SET NULL|RESTRICT|NO ACTION))?$/i', $line, $m)) {
            $def['foreign'][] = ['name' => $m[1], 'column' => $m[2], 'table' => $m[3], 'ref' => $m[4], 'delete' => $m[5] ?? '', 'update' => $m[6] ?? ''];
        } elseif (preg_match('/^(\w+)\s+(BIGINT|INT|SMALLINT|TINYINT|BOOL|VARCHAR|CHAR|MEDIUMTEXT|LONGTEXT|TEXT|DATETIME|DATE|DECIMAL|TIMESTAMP)(?:\((\d+)(?:,(\d+))?\))?(.*)$/i', $line, $m)) {
            $rest = $m[5];
            $col = ['name' => $m[1], 'type' => strtoupper($m[2]), 'size' => $m[3] !== '' ? (int) $m[3] : null, 'scale' => ($m[4] ?? '') !== '' ? (int) $m[4] : null,
                'unsigned' => (bool) preg_match('/\bUNSIGNED\b/i', $rest), 'null' => !preg_match('/\bNOT NULL\b/i', $rest), 'auto' => (bool) preg_match('/\bAUTO_INCREMENT\b/i', $rest),
                'default' => null, 'hasDefault' => false, 'comment' => $comment];
            if (preg_match("/\bDEFAULT\s+('(?:[^']*)'|-?[0-9.]+|NULL|[A-Za-z_()]+)/i", $rest, $d)) {
                $col['hasDefault'] = true;
                $col['default'] = $d[1] === 'NULL' ? null : (str_starts_with($d[1], "'") ? substr($d[1], 1, -1) : (is_numeric($d[1]) ? $d[1] + 0 : $d[1]));
            }
            $def['columns'][] = $col;
        } else {
            fwrite(STDERR, "ka_$name: cannot parse line: $line\n");
            exit(1);
        }
    }

    return $def;
}

/** @return list<string> */
function columnList(string $s): array
{
    return array_map(fn (string $c): string => trim($c, " `"), explode(',', $s));
}

/** @param array<string, mixed> $def */
function renderTable(string $name, array $def): string
{
    $auto = array_values(array_filter($def['columns'], fn (array $c): bool => $c['auto']));
    $options = ['id' => false];
    if ($def['primary'] !== []) {
        $options['primary_key'] = $def['primary'];
    }
    $php = '        $this->table(' . q($name) . ', ' . arr($options) . ")\n";
    foreach ($def['columns'] as $c) {
        $php .= '            ->addColumn(' . q($c['name']) . ', ' . q(phinxType($c)) . ', ' . arr(columnOptions($c)) . ")\n";
    }
    foreach ($def['indexes'] as $i) {
        $o = ['name' => $i['name']];
        if ($i['unique']) {
            $o['unique'] = true;
        }
        if ($i['fulltext']) {
            $o['type'] = 'fulltext';
        }
        $php .= '            ->addIndex(' . arr($i['columns']) . ', ' . arr($o) . ")\n";
    }
    foreach ($def['foreign'] as $f) {
        $o = ['constraint' => '$prefix . ' . q($f['name'])]; // unique per database, so it carries the table prefix (a raw PHP expression)
        if ($f['delete'] !== '') {
            $o['delete'] = str_replace(' ', '_', strtoupper($f['delete']));
        }
        if ($f['update'] !== '') {
            $o['update'] = str_replace(' ', '_', strtoupper($f['update']));
        }
        $php .= '            ->addForeignKey(' . q($f['column']) . ', ' . q($f['table']) . ', ' . q($f['ref']) . ', ' . arr($o) . ")\n";
    }

    return $php . "            ->create();\n\n";
}

function phinxType(array $c): string
{
    return match ($c['type']) {
        'BIGINT' => 'biginteger', 'INT' => 'integer', 'SMALLINT' => 'smallinteger', 'TINYINT' => ($c['size'] === 1 ? 'boolean' : 'tinyinteger'),
        'BOOL' => 'boolean', 'VARCHAR' => 'string', 'CHAR' => 'char', 'MEDIUMTEXT', 'LONGTEXT', 'TEXT' => 'text', 'DATETIME' => 'datetime',
        'DATE' => 'date', 'DECIMAL' => 'decimal', 'TIMESTAMP' => 'timestamp',
    };
}

/** @return array<string, mixed> */
function columnOptions(array $c): array
{
    $o = [];
    if (in_array($c['type'], ['VARCHAR', 'CHAR'], true)) {
        $o['limit'] = $c['size'];
    }
    if ($c['type'] === 'MEDIUMTEXT') {
        $o['limit'] = 'MysqlAdapter::TEXT_MEDIUM';
    }
    if ($c['type'] === 'LONGTEXT') {
        $o['limit'] = 'MysqlAdapter::TEXT_LONG';
    }
    if ($c['type'] === 'DECIMAL') {
        $o['precision'] = $c['size'];
        $o['scale'] = $c['scale'] ?? 0;
    }
    if (in_array($c['type'], ['BIGINT', 'INT', 'SMALLINT', 'TINYINT'], true) && $c['size'] !== 1) {
        $o['signed'] = !$c['unsigned'];
    }
    if ($c['unsigned'] && $c['type'] === 'TINYINT' && $c['size'] === 1) {
        $o['signed'] = false;
    }
    if ($c['auto']) {
        $o['identity'] = true;
    }
    $o['null'] = $c['null']; // Phinx makes a column NULL unless told otherwise
    if ($c['hasDefault'] && !$c['auto']) {
        $o['default'] = $c['default'];
    }
    if ($c['comment'] !== '') {
        $o['comment'] = $c['comment'];
    }

    return $o;
}

function q(string $s): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
}

/** @param array<int|string, mixed> $a */
function arr(array $a): string
{
    $isList = array_is_list($a);
    $parts = [];
    foreach ($a as $k => $v) {
        $val = match (true) {
            $v === null => 'null', is_bool($v) => $v ? 'true' : 'false', is_int($v) || is_float($v) => (string) $v,
            is_array($v) => arr($v), is_string($v) && (str_starts_with($v, 'MysqlAdapter::') || str_starts_with($v, '$prefix . ')) => $v, default => q((string) $v),
        };
        $parts[] = $isList ? $val : q((string) $k) . ' => ' . $val;
    }

    return '[' . implode(', ', $parts) . ']';
}
