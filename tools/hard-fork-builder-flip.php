<?php

/**
 * Hard fork (issue #10): the builder words that have ONE English name everywhere flip in every quoted position – PHP string
 * literals (array keys, values, list items, JSON snippets inside strings), JSON files and the scripts in image/. No SQL, no HTML
 * attributes, no CSS: those have their own passes. Idempotent (an English word is never a key of the map).
 *
 *   php tools/hard-fork-builder-flip.php           dry run, counts per file
 *   php tools/hard-fork-builder-flip.php --apply
 *   php tools/hard-fork-builder-flip.php --words   print the words that flip
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$map = require $root . '/tools/rename/hard-fork-map.php';
$builder = require $root . '/tools/rename/hard-fork-builder.php';
$apply = in_array('--apply', $argv, true);

// every Czech word with its English name(s): database columns, builder vocabulary, routes, extension keys
$targets = [];
foreach ($map['columns'] as $cols) {
    foreach ($cols as $cz => $en) {
        $targets[$cz][$en] = true;
    }
}
$walk = function (array $m) use (&$walk, &$targets): void {
    foreach ($m as $cz => $en) {
        if (is_array($en)) {
            $walk($en);
        } elseif (is_string($cz) && is_string($en)) {
            $targets[$cz][$en] = true;
        }
    }
};
$walk($builder);
$inBuilder = [];                                    // only builder vocabulary flips here; database column words went through the SQL pass
$collectBuilder = function (array $m) use (&$collectBuilder, &$inBuilder): void {
    foreach ($m as $cz => $en) {
        if (is_array($en)) {
            $collectBuilder($en);
        } elseif (is_string($cz)) {
            $inBuilder[$cz] = true;
        }
    }
};
$collectBuilder($builder);
foreach (array_merge($map['routes'], $map['extensions']) as $cz => $en) {
    $targets[$cz][$en] = true;
}

// short or generic Czech words that need a human look (settings types, period bounds…): not flipped here
$skip = array_flip(['ano', 'ne', 'od', 'do', 'za', 'pred', 'jedna', 'konec', 'kod', 'text', 'menu', 'html', 'auto', 'site', 'user', 'list', 'body']);
$words = [];
foreach ($targets as $cz => $ens) {
    if (isset($inBuilder[$cz]) && count($ens) === 1 && !isset($skip[$cz]) && $cz !== array_key_first($ens) && preg_match('/^[a-z][a-z_-]*$/', $cz) === 1) {
        $words[$cz] = array_key_first($ens);
    }
}
if (in_array('--words', $argv, true)) {
    echo count($words), ' words: ', implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($words), $words)), "\n";
    exit(0);
}

$excluded = '#^(tools/(rename|hard-fork-|schema-to-phinx|compare-schemas|fixtures)|system/(jazyka|languages|jazyky)|system/src/Mcp/(Translator|Vocabulary|Server|Prompts)|system/src/Core/Migrator|image/(jazyky|languages)/|vendor/|\.git/|\.phpunit)#';
$flipJson = static function (string $text) use ($words, &$count): string {
    return (string) preg_replace_callback('/(\\\\*")([a-z][a-z_-]*)(\\\\*")(?=\s*[:,\]}])/', function (array $m) use ($words, &$count): string {
        if (!isset($words[$m[2]])) {
            return $m[0];
        }
        $count++;

        return $m[1] . $words[$m[2]] . $m[3];
    }, $text);
};

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match($excluded, $rel) === 1 || !preg_match('#\.(php|js|json)$#', $rel)) {
        continue;
    }
    if (preg_match('#^(system/(src|views|presets|blueprints)|tests|tools/contracts|image)/#', $rel) !== 1 && !preg_match('#^[^/]+\.php$#', $rel)) {
        continue;
    }
    $files[] = $rel;
}

$total = 0;
foreach ($files as $rel) {
    $text = (string) file_get_contents($root . '/' . $rel);
    $count = 0;
    if (str_ends_with($rel, '.php')) {
        $tokens = PhpToken::tokenize($text);
        $out = '';
        $prevSignificant = '';
        foreach ($tokens as $i => $t) {
            $s = $t->text;
            if ($t->id === T_CONSTANT_ENCAPSED_STRING && $prevSignificant !== 't(') {
                if (preg_match('/^([\'"])([a-z][a-z_-]*)\1$/', $s, $m) === 1 && isset($words[$m[2]])) {
                    $s = $m[1] . $words[$m[2]] . $m[1];
                    $count++;
                } elseif (preg_match('/\\\\*"[a-z][a-z_-]*\\\\*"\s*[:,\]}]/', $s) === 1) {
                    $s = $flipJson($s);
                }
            }
            if (!$t->isIgnorable()) {
                $prevSignificant = $t->text === '(' && $prevSignificant === 't' ? 't(' : $t->text;
            }
            $out .= $s;
        }
        $new = $out;
        if (str_starts_with($rel, 'system/views/')) { // the keys of the data a view receives flipped, so its variables ($nadpis → $heading) do too
            $new = (string) preg_replace_callback('/(?<![\\w$>:])\$([a-z][a-z_]*)\b(?!\s*\()/', function (array $m) use ($words, &$count): string {
                if (!isset($words[$m[1]])) {
                    return $m[0];
                }
                $count++;

                return '$' . $words[$m[1]];
            }, $new);
        }
    } elseif (str_ends_with($rel, '.json')) {
        $new = $flipJson($text);
    } else { // image/*.js: quoted words, object keys and property accesses outside comments (the same rules as tools/hard-fork-uniform.php)
        $lines = explode("\n", $text);
        $inBlock = false;
        foreach ($lines as $i => $line) {
            $trim = ltrim($line);
            if ($inBlock) {
                $inBlock = !str_contains($line, '*/');
                continue;
            }
            if (str_starts_with($trim, '//') || str_starts_with($trim, '*')) {
                continue;
            }
            if (str_starts_with($trim, '/*')) {
                $inBlock = !str_contains($line, '*/');
                continue;
            }
            $code = $line;
            $comment = '';
            if (preg_match('#^((?:[^\'"`/]|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|`(?:\\\\.|[^`\\\\])*`|/(?!/))*)(//.*)$#', $line, $m) === 1) {
                $code = $m[1];
                $comment = $m[2];
            }
            $lines[$i] = preg_replace_callback('/([\'"`])([a-z][a-z_-]*)\1|(?<![\w$.\'"-])([a-z_]+)(?=\s*:(?!:))|\.([a-z_]+)\b/', function (array $m) use ($words, &$count): string {
                $word = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                if (!isset($words[$word])) {
                    return $m[0];
                }
                $count++;

                return str_replace($word, $words[$word], $m[0]);
            }, $code) . $comment;
        }
        $new = implode("\n", $lines);
    }
    if ($count > 0) {
        printf("%-70s %d\n", $rel, $count);
        $total += $count;
        if ($apply) {
            file_put_contents($root . '/' . $rel, $new);
        }
    }
}
echo ($apply ? 'APPLIED' : 'DRY RUN'), ": $total replacements in the files above; ", count($words), " words\n";
