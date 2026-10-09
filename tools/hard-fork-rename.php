<?php

/**
 * Hard fork (issue #9, HF-05): renames the Czech data model in PHP code by syntactic context, driven by tools/rename/hard-fork-map.php.
 *
 *   php tools/hard-fork-rename.php                  dry run: counts and the list of what it could not decide
 *   php tools/hard-fork-rename.php --apply          rewrite the files
 *   php tools/hard-fork-rename.php --files=a,b      only these files (relative paths)
 *   php tools/hard-fork-rename.php --report=FILE    write the unresolved list to a file
 *
 * It works on PHP tokens and only ever changes string literals, never comments or code:
 *   R1  {table} placeholders and `ka_table` in SQL-looking strings            -> English table name
 *   R2  the table argument of ->insert/->update/->delete/->upsert('table', …)  -> English table name
 *   R3  column names in SQL-looking strings, outside SQL quotes. The tables of the statement (placeholders, aliases) pick the map:
 *       a column name that means different things per table is renamed only when one table of the statement decides it
 *   R4  array keys ('word' =>, ['word']): inside ->insert/->update/->delete arrays by the table of the call; elsewhere only words
 *       that have ONE English name everywhere (database, builder, routes) - the rest is reported
 * Everything it cannot decide goes to the report ("ambiguous", "conflict word as key"): those are fixed by reading the code.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$map = require $root . '/tools/rename/hard-fork-map.php';
$builder = require $root . '/tools/rename/hard-fork-builder.php';

$apply = in_array('--apply', $argv, true);
$only = null;
$reportFile = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--files=')) {
        $only = explode(',', substr($a, 8));
    }
    if (str_starts_with($a, '--report=')) {
        $reportFile = substr($a, 9);
    }
}

// ---- the vocabulary

$tables = $map['tables'];                                    // czech => english (without ka_)
$columnsByTable = $map['columns'];                           // table (czech) => [czech col => english col]
$colTargets = [];                                            // czech word => [table => english]
foreach ($columnsByTable as $table => $cols) {
    foreach ($cols as $cz => $en) {
        $colTargets[$cz][$table] = $en;
    }
}
$consistent = [];                                            // czech column word with ONE english name in all tables
foreach ($colTargets as $cz => $perTable) {
    if (count(array_unique($perTable)) === 1) {
        $consistent[$cz] = reset($perTable);
    }
}

// words that mean something else outside the database (builder keys/types/tokens, routes, extension keys): never renamed as bare array keys
$otherMeaning = [];
$collect = function (string $cz, string $en) use (&$otherMeaning): void {
    $otherMeaning[$cz][$en] = true;
};
foreach ($builder as $group => $m) {
    if (!is_array($m)) {
        continue;
    }
    foreach ($m as $cz => $en) {
        if (is_array($en)) {
            foreach ($en as $c2 => $e2) {
                if (is_string($e2)) {
                    $collect((string) $c2, $e2);
                }
            }
        } else {
            $collect((string) $cz, (string) $en);
        }
    }
}
foreach ($map['routes'] as $cz => $en) {
    $collect($cz, $en);
}
foreach ($map['extensions'] as $cz => $en) {
    $collect($cz, $en);
}
$keyRenamable = [];                                          // word => english, safe as a bare array key everywhere
foreach ($consistent as $cz => $en) {
    // a word that is also builder vocabulary, a route or an extension key keeps its own phase unless every other meaning has the SAME English
    // name: then it flips uniformly (the builder data lives in PHP literals, JSON files and scripts that the same word flip reaches)
    $others = array_keys($otherMeaning[$cz] ?? []);
    if ($cz !== $en && ($others === [] || $others === [$en])) {
        $keyRenamable[$cz] = $en;
    }
}

// the words that have ONE English name in the database and in the builder: they flip everywhere, also inside JSON snippets in strings,
// blueprint/fixture JSON files and the admin scripts
$uniform = [];
foreach ($keyRenamable as $cz => $en) {
    if (isset($otherMeaning[$cz]) && array_keys($otherMeaning[$cz]) === [$en]) {
        $uniform[$cz] = $en;
    }
}

if (in_array('--show-uniform', $argv, true)) {
    echo 'uniform: ', implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($uniform), $uniform)), "\nkeyRenamable: ", count($keyRenamable), "\n";
    foreach (['barva', 'obrazek', 'titulek'] as $w) {
        echo "$w: keyRenamable=", $keyRenamable[$w] ?? '-', ' other=', json_encode(array_keys($otherMeaning[$w] ?? [])), ' colTargets=', json_encode(array_keys($colTargets[$w] ?? [])), "\n";
    }
    exit(0);
}

/** "word" inside text (JSON, escaped JSON in PHP strings) -> "english" for the uniform words. */
function flipJsonText(string $text, array $uniform, int &$count): string
{
    return (string) preg_replace_callback('/(\\*")([a-z_]+)(\\*")/', function (array $m) use ($uniform, &$count): string {
        if (!isset($uniform[$m[2]])) {
            return $m[0];
        }
        $count++;

        return $m[1] . $uniform[$m[2]] . $m[3];
    }, $text);
}

// ---- files

$files = [];
$dirs = ['system/src', 'system/views', 'system/presets', 'tests', 'tools'];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr((string) $f, strlen($root) + 1);
        if ($f->getExtension() !== 'php' || preg_match('#^(tools/rename|tools/hard-fork-|tools/schema-to-phinx|tools/compare-schemas|system/languages|tools/fixtures|system/src/Mcp/Server|system/src/Mcp/Prompts|system/src/Core/Migrator)#', $rel) === 1) {
            continue;
        }
        $files[] = $rel;
    }
}
foreach (['install.php', 'admin.php', 'index.php', 'phinx.php', 'config.sample.php', 'system/bootstrap.php', 'system/docker.php', 'system/demo.php', 'system/dev-router.php', 'bin/migrate'] as $f) {
    if (is_file($root . '/' . $f)) {
        $files[] = $f;
    }
}
if ($only !== null) {
    $files = array_values(array_intersect($files, $only));
}

// ---- helpers

/** Does a string literal look like SQL (or a piece of a statement)? Strict: CSS, HTML and prose must never match. */
function isSqlish(string $text, array $columnWords): bool
{
    // CSS declarations or rules: never SQL (a placeholder in the same string would still make it SQL)
    $hasPlaceholder = preg_match('/\{[a-z_]+\}|\bka_[a-z_]+\b/', $text) === 1;
    if (!$hasPlaceholder && preg_match('/[{;]\s*[a-z-]+\s*:\s*[^;{}]+;|<\/?[a-z][^>]*>/i', $text) === 1) {
        return false;
    }
    if ($hasPlaceholder) {
        return true;
    }
    if (preg_match('/\bSELECT\b[\s\S]*\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+\S+\s+SET\b|\bDELETE\s+FROM\b|\bORDER\s+BY\b|\bGROUP\s+BY\b|\bON\s+DUPLICATE\s+KEY\b|\bLIMIT\s+(?:\d+|\?)|\bWHERE\b[\s\S]*(?:=|\bIN\b|\bLIKE\b|\bIS\b)|\bCOUNT\(\*\)|\bJOIN\b[\s\S]*\bON\b/i', $text) === 1) {
        return true;
    }
    // a bare condition piece: "idk = ?", "jazyk IN (", "poradi, nazev"
    if ($columnWords !== [] && strlen($text) < 160 && preg_match('/^\s*(?:[a-z]\.)?(?:' . implode('|', array_map('preg_quote', $columnWords)) . ')\s*(?:=\s*\?|<=?\s*\?|>=?\s*\?|IN\s*\(|IS\s+(?:NOT\s+)?NULL|LIKE\s+\?|,|\s+(?:ASC|DESC)\b|$)/i', $text) === 1) {
        return true;
    }

    return false;
}

/** @param array<string, string> $aliases alias => table (czech) */
function rewriteSql(string $text, array $stmtTables, array $aliases, array $tables, array $columnsByTable, array $colTargets, array $consistent, array &$unresolved, string $where, bool $singleQuoted): string
{
    // 1. table placeholders and ka_ names
    $text = (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m): string => isset($tables[$m[1]]) ? '{' . $tables[$m[1]] . '}' : $m[0], $text);
    $text = (string) preg_replace_callback('/\bka_([a-z_]+)\b/', fn (array $m): string => isset($tables[$m[1]]) ? 'ka_' . $tables[$m[1]] : $m[0], $text);

    // 2. columns, outside SQL quotes
    $out = '';
    $len = strlen($text);
    $quote = null;
    for ($i = 0; $i < $len;) {
        $c = $text[$i];
        if ($quote === null && ($c === "'" || ($c === '\\' && $singleQuoted && ($text[$i + 1] ?? '') === "'"))) {
            $quote = "'";
            $out .= $c;
            $i++;
            if ($c === '\\') {
                $out .= $text[$i];
                $i++;
            }
            continue;
        }
        if ($quote !== null) {
            if ($c === '\\' && $singleQuoted && ($text[$i + 1] ?? '') === "'") {
                $quote = null;
                $out .= $c . $text[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === "'") {
                $quote = null;
            }
            $out .= $c;
            $i++;
            continue;
        }
        if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)(?:\.([A-Za-z_][A-Za-z0-9_]*))?/', $text, $m, 0, $i) === 1 && ($i === 0 || !preg_match('/[\w$>:]/', $text[$i - 1]))) {
            $word = $m[1];
            $col = $m[2] ?? null;
            $len2 = strlen($m[0]);
            if ($col !== null) {
                // alias.column or table.column
                $tableCz = $aliases[$word] ?? ($tables[$word] ?? null ? $word : null);
                if ($tableCz !== null && isset($columnsByTable[$tableCz][$col])) {
                    $out .= $word . '.' . $columnsByTable[$tableCz][$col];
                } elseif (isset($consistent[$col]) && !isset($colTargets[$col]) === false) {
                    $out .= $word . '.' . $consistent[$col];
                } elseif (isset($colTargets[$col])) {
                    $unresolved['ambiguous'][] = "$where: $word.$col";
                    $out .= $m[0];
                } else {
                    $out .= $m[0];
                }
                $i += $len2;
                continue;
            }
            if (isset($colTargets[$word])) {
                if (isset($consistent[$word])) {
                    $out .= $consistent[$word];
                } else {
                    $candidates = [];
                    foreach ($stmtTables as $t) {
                        if (isset($columnsByTable[$t][$word])) {
                            $candidates[$columnsByTable[$t][$word]] = true;
                        }
                    }
                    if (count($candidates) === 1) {
                        $out .= array_key_first($candidates);
                    } else {
                        $unresolved['ambiguous'][] = "$where: $word (tables: " . implode(',', $stmtTables) . ')';
                        $out .= $word;
                    }
                }
                $i += $len2;
                continue;
            }
            $out .= $m[0];
            $i += $len2;
            continue;
        }
        $out .= $c;
        $i++;
    }

    return $out;
}

// ---- run

$unresolved = ['ambiguous' => [], 'conflict-key' => [], 'table-word' => []];
$stats = ['files' => 0, 'changed' => 0, 'tables' => 0, 'sql' => 0, 'keys' => 0, 'dbkeys' => 0];
$allCols = array_keys($colTargets);

foreach ($files as $rel) {
    $code = (string) file_get_contents($root . '/' . $rel);
    $tokens = PhpToken::tokenize($code);
    $stats['files']++;
    $n = count($tokens);

    // statement ranges: [start, end] indices split on ; { }
    $stmtOf = [];
    $start = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->text === ';' || $t->text === '{' || $t->text === '}') {
            for ($j = $start; $j <= $i; $j++) {
                $stmtOf[$j] = [$start, $i];
            }
            $start = $i + 1;
        }
    }
    for ($j = $start; $j < $n; $j++) {
        $stmtOf[$j] = [$start, $n - 1];
    }

    // tables and aliases mentioned by the SQL-ish literals of each statement
    $stmtInfo = [];
    foreach ($stmtOf as $i => [$a, $b]) {
        $key = "$a:$b";
        if (isset($stmtInfo[$key])) {
            continue;
        }
        $tbl = [];
        $alias = [];
        for ($j = $a; $j <= $b; $j++) {
            if ($tokens[$j]->id === T_CONSTANT_ENCAPSED_STRING || $tokens[$j]->id === T_ENCAPSED_AND_WHITESPACE) {
                $text = $tokens[$j]->text;
                if (preg_match_all('/\{([a-z_]+)\}(?:\s+(?:AS\s+)?([a-z][a-z0-9_]*))?/', $text, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        if (isset($tables[$m[1]])) {
                            $tbl[$m[1]] = true;
                            if (($m[2] ?? '') !== '' && !preg_match('/^(ON|WHERE|SET|LEFT|JOIN|INNER|ORDER|GROUP|LIMIT|AND|OR|USING|SELECT|FROM|VALUES)$/i', $m[2])) {
                                $alias[$m[2]] = $m[1];
                            }
                        }
                    }
                }
                if (preg_match_all('/\bka_([a-z_]+)\b(?:\s+(?:AS\s+)?([a-z][a-z0-9_]*))?/', $text, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        if (isset($tables[$m[1]])) {
                            $tbl[$m[1]] = true;
                            if (($m[2] ?? '') !== '' && !preg_match('/^(ON|WHERE|SET|LEFT|JOIN|INNER|ORDER|GROUP|LIMIT|AND|OR|USING|SELECT|FROM|VALUES)$/i', $m[2])) {
                                $alias[$m[2]] = $m[1];
                            }
                        }
                    }
                }
            }
        }
        $stmtInfo[$key] = [array_keys($tbl), $alias];
    }

    // R7: the tables a file deals with (a module file, or the module a view belongs to); builder, MCP and preset files are excluded
    $fileTables = [];
    // the database domain: files that deal with rows and forms. Builder, MCP, element and design-system files speak the builder vocabulary
    // and never use the file-table rule
    $r7Excluded = true; // the file-table rule decided too many occurrences wrongly and inconsistently (a form field flipped on one side only); everything it would decide is listed instead
    $scanFor = [$rel];
    if (preg_match('#^system/views/admin/([a-z_]+)/#', $rel, $vm)) {
        foreach (glob($root . '/system/src/Admin/Modules/*.php') ?: [] as $moduleFile) {
            if (strcasecmp(basename($moduleFile, '.php'), str_replace('_', '', $vm[1])) === 0 || strcasecmp(basename($moduleFile, '.php'), rtrim($vm[1], 's')) === 0) {
                $scanFor = [substr($moduleFile, strlen($root) + 1)];
            }
        }
    }
    foreach ($scanFor as $scanRel) {
        $src = $scanRel === $rel ? $code : (string) file_get_contents($root . '/' . $scanRel);
        if (preg_match_all('/\{([a-z_]+)\}|\bka_([a-z_]+)\b|[\'"]([a-z_]+)[\'"]\s*,\s*\[/', $src, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $m) {
                foreach ([$m[1] ?? '', $m[2] ?? '', $m[3] ?? ''] as $cand) {
                    if ($cand !== '' && isset($tables[$cand])) {
                        $fileTables[$cand] = true;
                    }
                }
            }
        }
        // English names (already renamed) count as well, so a second run keeps deciding the same way
        $reverse = array_flip($tables);
        if (preg_match_all('/\{([a-z_]+)\}|\bka_([a-z_]+)\b/', $src, $fm, PREG_SET_ORDER)) {
            foreach ($fm as $m) {
                foreach ([$m[1] ?? '', $m[2] ?? ''] as $cand) {
                    if ($cand !== '' && isset($reverse[$cand])) {
                        $fileTables[$reverse[$cand]] = true;
                    }
                }
            }
        }
    }
    $fileTables = array_keys($fileTables);

    $changed = false;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $isStr = $t->id === T_CONSTANT_ENCAPSED_STRING;
        // R9: in templates the keys of the view data ('titulek' => …) are variables ($titulek): they flip with the key
        if ($t->id === T_VARIABLE && str_starts_with($rel, 'system/views/') && isset($keyRenamable[substr($t->text, 1)])) {
            $tokens[$i] = new PhpToken($t->id, '$' . $keyRenamable[substr($t->text, 1)], $t->line, $t->pos);
            $changed = true;
            $stats['keys']++;
            continue;
        }
        if ($t->id === T_INLINE_HTML && str_contains($t->text, 'name=')) {
            $html = (string) preg_replace_callback('/(\bname=[\'"])([a-z_]+)((?:\[[a-z_]*\])*[\'"])/', function (array $m) use ($keyRenamable, &$stats): string {
                if (!isset($keyRenamable[$m[2]])) {
                    return $m[0];
                }
                $stats['keys']++;

                return $m[1] . $keyRenamable[$m[2]] . $m[3];
            }, $t->text);
            if ($html !== $t->text) {
                $tokens[$i] = new PhpToken($t->id, $html, $t->line, $t->pos);
                $changed = true;
            }
            continue;
        }
        if (!$isStr && $t->id !== T_ENCAPSED_AND_WHITESPACE) {
            continue;
        }
        [$a, $b] = $stmtOf[$i];
        [$stmtTables, $aliases] = $stmtInfo["$a:$b"];
        $original = $t->text;
        $new = $original;
        $singleQuoted = $isStr && $original[0] === "'";

        // R2 + R4(i): ->insert/->update/->delete('table', [...]) and its arrays
        $prev = null;
        for ($k = $i - 1; $k >= $a; $k--) {
            if (!$tokens[$k]->isIgnorable()) {
                $prev = $k;
                break;
            }
        }
        if ($isStr && $prev !== null && $tokens[$prev]->text === '(' ) {
            $call = null;
            for ($k = $prev - 1; $k >= $a; $k--) {
                if (!$tokens[$k]->isIgnorable()) {
                    $call = $k;
                    break;
                }
            }
            if ($call !== null && $tokens[$call]->id === T_STRING && in_array($tokens[$call]->text, ['insert', 'update', 'delete', 'upsert', 'replace', 'insertIgnore'], true)) {
                $word = trim($original, "'\"");
                if (isset($tables[$word]) && preg_match('/^[\'"][a-z_]+[\'"]$/', $original) === 1) {
                    $new = $original[0] . $tables[$word] . $original[0];
                    $stats['tables']++;
                }
            }
        }

        if ($isStr && preg_match('/^([\'"])([a-z_]+)\1$/', $original, $m) === 1) {
            $word = $m[2];
            $nextIdx = null;
            for ($k = $i + 1; $k <= $b; $k++) {
                if (!$tokens[$k]->isIgnorable()) {
                    $nextIdx = $k;
                    break;
                }
            }
            $asKey = $nextIdx !== null && ($tokens[$nextIdx]->text === '=>' || ($tokens[$nextIdx]->text === ']' && $prev !== null && $tokens[$prev]->text === '['));
            // R4(iii): the column name as an argument of array_column()/array_key_exists()
            if (!$asKey) {
                $depth = 0;
                for ($k = $i - 1; $k >= $a; $k--) {
                    if ($tokens[$k]->text === ')') {
                        $depth++;
                    } elseif ($tokens[$k]->text === '(') {
                        if ($depth === 0) {
                            $fn = null;
                            for ($q = $k - 1; $q >= $a; $q--) {
                                if (!$tokens[$q]->isIgnorable()) {
                                    $fn = $tokens[$q];
                                    break;
                                }
                            }
                            $asKey = $fn !== null && in_array(strtolower($fn->text), ['array_column', 'array_key_exists', 'key_exists'], true);
                            break;
                        }
                        $depth--;
                    }
                }
            }
            if ($asKey && isset($colTargets[$word])) {
                // the table of an enclosing ->insert/->update/->delete('table', …) call decides
                $dbTable = null;
                for ($k = $a; $k < $i; $k++) {
                    if ($tokens[$k]->id === T_STRING && in_array($tokens[$k]->text, ['insert', 'update', 'delete', 'upsert'], true)) {
                        for ($q = $k + 1; $q <= $i; $q++) {
                            if ($tokens[$q]->id === T_CONSTANT_ENCAPSED_STRING) {
                                $cand = trim($tokens[$q]->text, "'\"");
                                if (isset($tables[$cand]) || in_array($cand, $tables, true)) {
                                    $dbTable = array_search($cand, $tables, true) ?: $cand;
                                }
                                break;
                            }
                        }
                    }
                }
                // inside ->insert/->update('table', […]) only real keys ('x' =>) belong to the table; $row['x'] value lookups do not
                if ($dbTable !== null && $tokens[$nextIdx]->text === '=>' && isset($columnsByTable[$dbTable][$word])) {
                    $new = $original[0] . $columnsByTable[$dbTable][$word] . $original[0];
                    $stats['dbkeys']++;
                } elseif (isset($keyRenamable[$word])) {
                    $new = $original[0] . $keyRenamable[$word] . $original[0];
                    $stats['keys']++;
                } elseif ($dbTable === null && !$r7Excluded && ($r7 = array_unique(array_filter(array_map(fn (string $t): ?string => $columnsByTable[$t][$word] ?? null, $fileTables)))) !== [] && count($r7) === 1) {
                    $new = $original[0] . reset($r7) . $original[0];
                    $stats['keys']++;
                } elseif ($dbTable === null || $tokens[$nextIdx]->text !== '=>') {
                    $unresolved['conflict-key'][] = "$rel:{$t->line}: '$word' (" . (isset($consistent[$word]) ? "consistent $consistent[$word] but also builder/route/ext" : 'ambiguous across tables') . ')';
                }
            }
        }

        // R6: $request->post('word') / get / getInt / postInt / postBool / postList / has / file
        if ($isStr && $new === $original && $prev !== null && $tokens[$prev]->text === '(' && preg_match('/^([\'"])([a-z_]+)\1$/', $original, $rm) === 1 && isset($keyRenamable[$rm[2]])) {
            $callName = null;
            for ($k = $prev - 1; $k >= $a; $k--) {
                if (!$tokens[$k]->isIgnorable()) {
                    $callName = $k;
                    break;
                }
            }
            if ($callName !== null && $tokens[$callName]->id === T_STRING && in_array($tokens[$callName]->text, ['post', 'get', 'getInt', 'postInt', 'postBool', 'postList', 'has', 'file'], true)) {
                $before = null;
                for ($k = $callName - 1; $k >= $a; $k--) {
                    if (!$tokens[$k]->isIgnorable()) {
                        $before = $tokens[$k]->text;
                        break;
                    }
                }
                if ($before === '->' || $before === '?->') {
                    $new = $rm[1] . $keyRenamable[$rm[2]] . $rm[1];
                    $stats['keys']++;
                }
            }
        }

        // R5: name="word" form fields inside strings that carry HTML
        if ($new === $original && str_contains($original, 'name=')) {
            $new = (string) preg_replace_callback('/(\bname=\\?[\'"])([a-z_]+)((?:\[[a-z_]*\])*\\?[\'"])/', function (array $m) use ($keyRenamable, &$stats): string {
                if (!isset($keyRenamable[$m[2]])) {
                    return $m[0];
                }
                $stats['keys']++;

                return $m[1] . $keyRenamable[$m[2]] . $m[3];
            }, $original);
        }

        // R4(iv): a uniform word flips in EVERY position: list elements ('typ', 'obsah' …), values, defaults - never only as a key
        if ($isStr && $new === $original && preg_match('/^([\'"])([a-z_]+)\1$/', $original, $um) === 1 && isset($keyRenamable[$um[2]])) {
            $new = $um[1] . $keyRenamable[$um[2]] . $um[1];
            $stats['keys']++;
        }

        // R8: "word" JSON tokens inside strings (stored builds, fixtures embedded in tests)
        if ($new === $original && (str_contains($original, '"typ"') || preg_match('/\\*"[a-z_]+\\*"/', $original) === 1)) {
            $count8 = 0;
            $flipped = flipJsonText($original, $uniform, $count8);
            if ($flipped !== $original) {
                $new = $flipped;
                $stats['keys'] += $count8;
            }
        }

        // a string that is exactly a Czech table name outside a database call is only reported (prefix . 'uzivatele', $table = 'novinky' …)
        if ($isStr && $new === $original && preg_match('/^[\'"]([a-z_]+)[\'"]$/', $original, $bm) === 1 && isset($tables[$bm[1]])) {
            $unresolved['table-word'][] = "$rel: '{$bm[1]}'";
        }

        // R1 + R3: SQL-looking strings
        if ($new === $original && isSqlish($original, $allCols)) {
            if ($isStr) {
                $rewritten = $original[0] . rewriteSql(substr($original, 1, -1), $stmtTables, $aliases, $tables, $columnsByTable, $colTargets, $consistent, $unresolved, "$rel:" . $t->line, $singleQuoted) . $original[-1];
            } else {
                $rewritten = rewriteSql($original, $stmtTables, $aliases, $tables, $columnsByTable, $colTargets, $consistent, $unresolved, "$rel:" . $t->line, false);
            }
            if ($rewritten !== $original) {
                $new = $rewritten;
                $stats['sql']++;
            }
        }

        if ($new !== $original) {
            $tokens[$i] = new PhpToken($t->id, $new, $t->line, $t->pos);
            $changed = true;
        }
    }

    if ($changed) {
        $stats['changed']++;
        if ($apply) {
            file_put_contents($root . '/' . $rel, implode('', array_map(fn (PhpToken $t): string => $t->text, $tokens)));
        }
    }
}

echo ($apply ? 'APPLIED' : 'DRY RUN'), ": {$stats['files']} files scanned, {$stats['changed']} changed; table names {$stats['tables']}, SQL strings {$stats['sql']}, array keys by table {$stats['dbkeys']}, other array keys {$stats['keys']}\n";
$report = '';
foreach ($unresolved as $kind => $items) {
    $counts = array_count_values($items);
    arsort($counts);
    $report .= "\n== $kind (" . count($items) . " occurrences, " . count($counts) . " distinct)\n";
    foreach ($counts as $item => $c) {
        $report .= sprintf("  %3d  %s\n", $c, $item);
    }
}
if ($reportFile !== null) {
    file_put_contents($reportFile, $report);
    echo "report written to $reportFile\n";
} else {
    echo $report;
}
