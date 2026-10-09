<?php

/**
 * Hard fork (issue #12): re-keys the non-Czech dictionaries to the English source text. The Czech dictionaries (already keyed by English)
 * are the bridge: Czech text -> English key. One-off tool.
 *
 *   php tools/rekey-dictionaries.php            report only
 *   php tools/rekey-dictionaries.php --apply    rewrite the files
 *
 * A Czech key whose text belongs to several English keys is assigned only to the candidates that have no translation yet, and only when
 * there is exactly one such candidate; the rest is listed in the report (never merged silently). A key that is neither English nor
 * bridgeable is dropped (a dead translation); the data-driven keys (datum_format, datum_slovy) stay.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$dir = $root . '/system/languages/';

$union = [];                                    // english key => czech text
foreach (['cs', 'admin-cs', 'install-cs'] as $f) {
    $union += require $dir . $f . '.php';
}
$czToEn = [];                                   // czech text => list of english keys
foreach ($union as $en => $cz) {
    $czToEn[$cz][] = (string) $en;
}
$special = ['datum_format' => true, 'datum_slovy' => true];

/** @param array<string, string> $dict @return array{0: array<string, string>, 1: list<string>, 2: int} */
function rekey(array $dict, array $union, array $czToEn, array $special): array
{
    $isEnglish = static fn (string $k): bool => isset($union[$k]);
    $out = [];
    $report = [];
    $dropped = 0;
    foreach ($dict as $k => $v) {            // the English-keyed entries first: they win
        if ($isEnglish((string) $k) || isset($special[$k])) {
            $out[$k] = $v;
        }
    }
    foreach ($dict as $k => $v) {
        $k = (string) $k;
        if ($isEnglish($k) || isset($special[$k])) {
            continue;
        }
        $candidates = $czToEn[$k] ?? [];
        $free = array_values(array_filter($candidates, static fn (string $e): bool => !isset($out[$e])));
        if ($candidates === []) {
            $dropped++;
        } elseif (count($free) === 1) {
            $out[$free[0]] = $v;
        } elseif ($free === []) {
            // every candidate has a translation of its own already: this Czech-keyed one is redundant
        } else {
            $report[] = $k . '  <-  ' . implode(' | ', $free);
        }
    }

    return [$out, $report, $dropped];
}

$summary = [];
foreach (glob($dir . '*.php') ?: [] as $file) {
    $name = basename($file, '.php');
    if (in_array($name, ['cs', 'admin-cs', 'install-cs'], true)) {
        continue;
    }
    $dict = require $file;
    if (in_array($name, ['en', 'admin-en', 'install-en'], true)) {
        // English is the source language: its dictionary carries only the data-driven keys
        $new = array_intersect_key($dict, $special);
        $report = [];
        $dropped = count($dict) - count($new);
    } else {
        [$new, $report, $dropped] = rekey($dict, $union, $czToEn, $special);
    }
    $summary[] = sprintf('%-14s %5d -> %5d  dropped %4d  ambiguous %3d', $name, count($dict), count($new), $dropped, count($report));
    foreach ($report as $line) {
        $summary[] = '      ? ' . $line;
    }
    if ($apply) {
        $source = (string) file_get_contents($file);
        $header = preg_match('/^(<\?php.*?)return \[/s', $source, $m) === 1 ? $m[1] : "<?php\n\n";
        $header = (string) preg_replace('/keyed by the source text:[^)]*\)/', 'keyed by the English source text)', $header);
        $header = (string) preg_replace('/\(source texts are English; a few older keys are Czech\)/', '(keyed by the English source text)', $header);
        $body = '';
        foreach ($new as $k => $v) {
            $body .= '    ' . var_export((string) $k, true) . ' => ' . var_export($v, true) . ",\n";
        }
        file_put_contents($file, $header . "return [\n" . $body . "];\n");
    }
}

// the scripts' dictionaries
foreach (glob($root . '/image/languages/admin-*.js') ?: [] as $file) {
    $name = basename($file, '.js');
    if ($name === 'admin-cs') {
        continue;
    }
    $source = (string) file_get_contents($file);
    if (preg_match('/^(.*?window\.KALETA_TRANSLATIONS = )(\{.*\});?\s*$/s', $source, $m) !== 1) {
        $summary[] = "$name.js: format not recognised";
        continue;
    }
    $dict = json_decode(preg_replace('/,\s*}\s*$/', '}', $m[2]), true);
    if (!is_array($dict)) {
        $summary[] = "$name.js: JSON not readable";
        continue;
    }
    if ($name === 'admin-en') {
        $new = [];
        $report = [];
        $dropped = count($dict);
    } else {
        [$new, $report, $dropped] = rekey($dict, $union, $czToEn, $special);
    }
    $summary[] = sprintf('%-14s %5d -> %5d  dropped %4d  ambiguous %3d (js)', $name, count($dict), count($new), $dropped, count($report));
    foreach ($report as $line) {
        $summary[] = '      ? ' . $line;
    }
    if ($apply) {
        $header = (string) preg_replace('#/\*.*?\*/#s', '/* Kaleta - texts of the admin scripts (' . substr($name, 6) . '), keyed by the English source text (T()). */', $m[1], 1);
        $body = $new === [] ? '{}' : (string) json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($file, $header . str_replace('    ', "\t", $body) . ";\n");
    }
}
echo implode("\n", $summary), "\n", $apply ? "APPLIED\n" : "DRY RUN\n";
