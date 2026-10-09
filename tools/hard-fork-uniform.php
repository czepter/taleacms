<?php

/**
 * Hard fork (issue #9/#10): the words that have ONE English name in the database and in the builder flip everywhere. tools/hard-fork-rename.php
 * does PHP; this one does the JSON data files and the admin/site scripts (image/*.js).
 *
 *   php tools/hard-fork-uniform.php            dry run
 *   php tools/hard-fork-uniform.php --apply
 *
 * JSON: "word" tokens (keys and values). JavaScript: quoted 'word' / "word", object keys `word:` and property accesses `.word`, outside comments.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$map = require $root . '/tools/rename/hard-fork-map.php';
$builder = require $root . '/tools/rename/hard-fork-builder.php';
$apply = in_array('--apply', $argv, true);

$colTargets = [];
foreach ($map['columns'] as $cols) {
    foreach ($cols as $cz => $en) {
        $colTargets[$cz][$en] = true;
    }
}
$other = [];
foreach ($builder as $m) {
    if (!is_array($m)) {
        continue;
    }
    foreach ($m as $cz => $en) {
        if (is_array($en)) {
            foreach ($en as $c2 => $e2) {
                if (is_string($e2)) {
                    $other[(string) $c2][$e2] = true;
                }
            }
        } else {
            $other[(string) $cz][(string) $en] = true;
        }
    }
}
foreach (array_merge($map['routes'], $map['extensions']) as $cz => $en) {
    $other[$cz][$en] = true;
}
$uniform = [];
foreach ($colTargets as $cz => $ens) {
    if (count($ens) === 1 && isset($other[$cz]) && array_keys($other[$cz]) === array_keys($ens)) {
        $en = array_key_first($ens);
        if ($cz !== $en) {
            $uniform[$cz] = $en;
        }
    }
}

$files = [];
foreach (glob($root . '/system/blueprints/*.json') ?: [] as $f) {
    $files[] = $f;
}
foreach (glob($root . '/tools/fixtures/*.json') ?: [] as $f) {
    $files[] = $f;
}
foreach (glob($root . '/image/*.js') ?: [] as $f) {
    $files[] = $f;
}

$total = 0;
foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    $text = (string) file_get_contents($file);
    $count = 0;
    if (str_ends_with($file, '.json')) {
        $new = (string) preg_replace_callback('/"([a-z_]+)"/', function (array $m) use ($uniform, &$count): string {
            if (!isset($uniform[$m[1]])) {
                return $m[0];
            }
            $count++;

            return '"' . $uniform[$m[1]] . '"';
        }, $text);
    } else {
        $lines = explode("\n", $text);
        $inBlock = false;
        foreach ($lines as $i => $line) {
            $trim = ltrim($line);
            if ($inBlock) {
                if (str_contains($line, '*/')) {
                    $inBlock = false;
                }
                continue;
            }
            if (str_starts_with($trim, '//')) {
                continue;
            }
            if (str_starts_with($trim, '/*') || str_starts_with($trim, '*')) {
                $inBlock = str_starts_with($trim, '/*') && !str_contains($line, '*/');
                continue;
            }
            // the code part of the line: up to a trailing // comment that is not inside quotes
            $code = $line;
            $comment = '';
            if (preg_match('#^((?:[^\'"`/]|\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"|`(?:\\\\.|[^`\\\\])*`|/(?!/))*)(//.*)$#', $line, $m) === 1) {
                $code = $m[1];
                $comment = $m[2];
            }
            $new = (string) preg_replace_callback('/([\'"`])([a-z_]+)\1|(?<![\w$.\'"-])([a-z_]+)(?=\s*:(?!:))|\.([a-z_]+)\b/', function (array $m) use ($uniform, &$count): string {
                $word = $m[2] ?? '';
                if ($word === '') {
                    $word = $m[3] ?? '';
                }
                if ($word === '') {
                    $word = $m[4] ?? '';
                }
                if (!isset($uniform[$word])) {
                    return $m[0];
                }
                $count++;

                return str_replace($word, $uniform[$word], $m[0]);
            }, $code);
            $lines[$i] = $new . $comment;
        }
        $new = implode("\n", $lines);
    }
    if ($count > 0) {
        echo sprintf("%-40s %d\n", $rel, $count);
        $total += $count;
        if ($apply) {
            file_put_contents($file, $new);
        }
    }
}
echo ($apply ? 'APPLIED' : 'DRY RUN'), ": $total replacements; uniform words: ", implode(' ', array_keys($uniform)), "\n";
