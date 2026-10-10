<?php

/**
 * Hard fork (issue #14, HF-10): the one scripted pass that renames the product Kaleta -> Talea. A one-off tool, deleted afterwards.
 *
 *   php tools/rebrand-talea.php            dry run: counts per rule and the files that would be renamed
 *   php tools/rebrand-talea.php --apply    rewrite the files and `git mv` the renamed ones
 *
 * Rules (text files; word-boundary, case-preserving, never inside words):
 *   Kaleta/Kalety/Kaletě/Kaletu/Kaletou -> Talea  (the Czech cases of the name: the dictionaries keep the plain name)
 *   KALETA -> TALEA, kaleta -> talea, kaletacms -> taleacms
 *   table prefix  ka_<x>  -> tl_<x>;  CSS/HTML hooks  ka-<x> -> tl-<x>  (also --ka-, data-ka-, .ka-)
 * Never touched: vendor/, .git/, .claude/, node_modules/, LICENSE, NOTICE, docs/DECISIONS.md, tools/rename/, the other one-off tools,
 * binary files. File names containing "kaleta" are renamed to "talea".
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);

$skip = '#^(vendor|\.git|\.claude|node_modules|\.phpunit\.cache|dist|storage|media)/|^(LICENSE|NOTICE|docs/DECISIONS\.md)$|^tools/(rename/|hard-fork-|schema-to-phinx|compare-schemas|rekey-dictionaries|english-sources|rebrand-talea|hard-fork)#';
$textExtensions = ['php', 'js', 'mjs', 'css', 'md', 'json', 'yml', 'yaml', 'sh', 'txt', 'xml', 'html', 'neon', 'toml', 'conf', 'ini', 'example', 'svg', 'py', 'lock', 'dist', 'htaccess', 'gitignore', 'dockerignore', 'pub', 'xml'];

$rules = [
    // the Czech cases first: the name as one word
    ['/\bKalet(?:a|y|ě|u|ou)\b/u', 'Talea'],
    ['/\bKALETA/', 'TALEA'],
    ['/(?<![A-Za-z0-9])Kaleta/', 'Talea'],
    ['/kaletacms/', 'taleacms'],
    ['/(?<![A-Za-z])kaleta/', 'talea'],
    ['/(?<![A-Za-z0-9])kaleta(?=[_\-.\/])/', 'talea'],
    // the table prefix (SQL in code and tests, config defaults, comments)
    ['/(?<![A-Za-z0-9_])ka_(?=[a-z])/', 'tl_'],
    // CSS custom properties, classes, data attributes, ids
    ['/(?<![A-Za-z0-9_])ka-(?=[a-z])/', 'tl-'],
];

$files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files --cached --others --exclude-standard')));
$changedFiles = 0;
$counts = array_fill(0, count($rules), 0);
$renames = [];
foreach ($files as $rel) {
    if ($rel === '' || preg_match($skip, $rel) === 1 || !is_file($root . '/' . $rel)) {
        continue;
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $isText = in_array($ext, $textExtensions, true) || in_array(basename($rel), ['Dockerfile', 'Caddyfile', '.htaccess', '.gitignore', '.dockerignore', 'composer.json'], true);
    if ($isText) {
        $text = (string) file_get_contents($root . '/' . $rel);
        $new = $text;
        foreach ($rules as $i => [$pattern, $replacement]) {
            $new = (string) preg_replace_callback($pattern, function (array $m) use ($replacement, &$counts, $i): string {
                $counts[$i]++;

                return $replacement === 'tl_' || $replacement === 'tl-' ? $replacement : $replacement;
            }, $new);
        }
        if ($new !== $text) {
            $changedFiles++;
            if ($apply) {
                file_put_contents($root . '/' . $rel, $new);
            }
        }
    }
    if (stripos($rel, 'kaleta') !== false) {
        $renames[$rel] = (string) preg_replace('/kaleta/i', 'talea', $rel);
    }
}
if ($apply) {
    foreach ($renames as $from => $to) {
        @mkdir(dirname($root . '/' . $to), 0775, true);
        shell_exec('cd ' . escapeshellarg($root) . ' && git mv ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' 2>&1');
    }
}
foreach ($rules as $i => [$pattern]) {
    printf("%6d  %s\n", $counts[$i], $pattern);
}
echo ($apply ? 'APPLIED' : 'DRY RUN'), ": $changedFiles files with replacements, ", count($renames), " files renamed\n";
foreach ($renames as $from => $to) {
    echo "  $from -> $to\n";
}
