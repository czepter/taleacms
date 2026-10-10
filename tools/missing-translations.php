<?php

/**
 * Lists the English source texts that code uses (t('…') in PHP, T('…') in scripts) but a dictionary lacks.
 *
 *   php tools/missing-translations.php [code]        e.g. cs (default) or de; exit 1 when something is missing
 *
 * Texts of the PHP code are looked up in the site, admin and installer dictionaries of the language together (a text may live in any of
 * them), the texts of the scripts in image/languages/admin-<code>.js. Feed the result to tools/add-translations.py.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$code = $argv[1] ?? 'cs';
$known = [];
foreach (['', 'admin-', 'install-'] as $set) {
    $file = $root . "/system/languages/{$set}{$code}.php";
    if (is_file($file)) {
        $known += array_fill_keys(array_map('strval', array_keys(require $file)), true);
    }
}
$scripts = [];
if (preg_match('/window\.TALEA_TRANSLATIONS = (\{.*\});/s', (string) @file_get_contents($root . "/image/languages/admin-{$code}.js"), $m) === 1) {
    $scripts = (array) json_decode((string) preg_replace('/,\s*\}$/', '}', $m[1]), true);
}

$missing = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (preg_match('#^(vendor|tests|tools|docs|\.git|\.claude|node_modules|storage|media|system/languages|image/languages)/#', $rel) === 1) {
        continue;
    }
    if (str_ends_with($rel, '.php')) {
        $prev = '';
        foreach (PhpToken::tokenize((string) file_get_contents($f->getPathname())) as $t) {
            if ($t->id === T_CONSTANT_ENCAPSED_STRING && $prev === 't(' && $t->text[0] === "'") {
                $key = stripslashes(substr($t->text, 1, -1));
                if (!isset($known[$key]) && preg_match('/\p{L}{2}/u', $key) === 1 && preg_match('/^[a-z_]+$/', $key) !== 1) {
                    $missing["$rel"][] = $key;
                }
            }
            if (!$t->isIgnorable()) {
                $prev = $t->text === '(' && $prev === 't' ? 't(' : $t->text;
            }
        }
    } elseif (str_ends_with($rel, '.js') && str_starts_with($rel, 'image/')) {
        preg_match_all('/\bT\(\s*(["\'])((?:(?!\1)[^\\\\\n]|\\\\.)+)\1/u', (string) file_get_contents($f->getPathname()), $found);
        foreach ($found[2] as $text) {
            $text = stripcslashes($text);
            if (!isset($scripts[$text])) {
                $missing[$rel][] = $text;
            }
        }
    }
}
$total = 0;
foreach ($missing as $file => $keys) {
    foreach (array_unique($keys) as $key) {
        echo $file, ': ', $key, "\n";
        $total++;
    }
}
echo $total, " missing in {$code}\n";
exit($total === 0 ? 0 : 1);
