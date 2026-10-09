<?php

/**
 * Hard fork (issue #12): t('Czech text') literals that were only English through the old English dictionaries (en.php, admin-en.php,
 * install-en.php keyed by Czech) become t('English text') in the code, and the Czech text moves into the Czech dictionaries as a
 * translation. One-off tool; run it before tools/rekey-dictionaries.php.
 *
 *   php tools/english-sources.php            report
 *   php tools/english-sources.php --apply
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$dir = $root . '/system/languages/';
$oldDir = rtrim((string) (preg_replace('/^--old=/', '', current(array_filter($argv, fn ($a) => str_starts_with($a, '--old='))) ?: '') ?: $dir), '/') . '/';   // where the old Czech-keyed English dictionaries are (--old=<dir>)

$cs = ['cs' => require $dir . 'cs.php', 'admin-cs' => require $dir . 'admin-cs.php', 'install-cs' => require $dir . 'install-cs.php'];
$englishKeys = array_merge($cs['cs'], $cs['admin-cs'], $cs['install-cs']);
$old = [];                                      // czech text => english text, the area's own dictionary first
foreach (['en', 'admin-en', 'install-en'] as $f) {
    foreach (require $oldDir . $f . '.php' as $k => $v) {
        if (preg_match('/^[a-z_]+$/', (string) $k) !== 1) {
            $old[$f][(string) $k] = (string) $v;
        }
    }
}
$allOld = array_merge($old['install-en'] ?? [], $old['admin-en'] ?? [], $old['en'] ?? []);
$jsOld = [];
if (preg_match('/window\.KALETA_TRANSLATIONS = (\{.*\});?\s*$/s', (string) file_get_contents(is_file($oldDir . 'admin-en.js') ? $oldDir . 'admin-en.js' : $root . '/image/languages/admin-en.js'), $m) === 1) {
    $jsOld = json_decode((string) preg_replace('/,\s*}\s*$/', '}', $m[1]), true) ?: [];
}

$newPairs = [];                                 // english => czech, for the Czech dictionaries
$jsPairs = [];
$unresolved = [];
$changed = 0;

$files = [];
foreach (['system/src', 'system/views', 'system/presets'] as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (str_ends_with($f->getPathname(), '.php')) {
            $files[] = $f->getPathname();
        }
    }
}
foreach ($files as $file) {
    $text = (string) file_get_contents($file);
    $out = '';
    $prev = '';
    foreach (PhpToken::tokenize($text) as $t) {
        $s = $t->text;
        // a single-quoted literal that is exactly an old Czech key: t('…') arguments, menu labels, option lists, titles later passed to t()
        if ($t->id === T_CONSTANT_ENCAPSED_STRING && $s[0] === "'") {
            $key = stripslashes(substr($s, 1, -1));
            if (!isset($englishKeys[$key]) && isset($allOld[$key]) && ($prev === 't(' || preg_match('/^[a-z_]+$/', $key) !== 1)) {
                $english = $allOld[$key];
                $newPairs[$english] = $key;
                $changed++;
                $s = "'" . addcslashes($english, "'\\") . "'";
            } elseif (!isset($englishKeys[$key]) && $prev === 't(' && preg_match('/[áčďéěíňóřšťúůýžÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ]/u', $key) === 1) {
                $unresolved[] = substr($file, strlen($root) + 1) . ': ' . $key;
            }
        }
        if (!$t->isIgnorable()) {
            $prev = $t->text === '(' && $prev === 't' ? 't(' : $t->text;
        }
        $out .= $s;
    }
    if ($out !== $text && $apply) {
        file_put_contents($file, $out);
    }
}

foreach (glob($root . '/image/*.js') ?: [] as $file) {
    $text = (string) file_get_contents($file);
    $new = preg_replace_callback('/\bT\((["\'])((?:(?!\1)[^\\\\]|\\\\.)+)\1/u', function (array $m) use ($jsOld, &$jsPairs, &$unresolved, &$changed, $file, $root): string {
        $key = stripcslashes($m[2]);
        if (isset($jsOld[$key])) {
            $jsPairs[$jsOld[$key]] = $key;
            $changed++;

            return 'T(' . json_encode($jsOld[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (preg_match('/[áčďéěíňóřšťúůýžÁČĎÉĚÍŇÓŘŠŤÚŮÝŽ]/u', $key) === 1) {
            $unresolved[] = substr($file, strlen($root) + 1) . ': ' . $key;
        }

        return $m[0];
    }, $text);
    $new = preg_replace_callback('/(["\'])((?:(?!\1)[^\\\\\n]|\\\\.)+)\1/u', function (array $m) use ($jsOld, &$jsPairs, &$changed): string {
        $key = stripcslashes($m[2]);
        if (!isset($jsOld[$key]) || preg_match('/^[a-z_]+$/', $key) === 1) {
            return $m[0];
        }
        $jsPairs[$jsOld[$key]] = $key;
        $changed++;

        return json_encode($jsOld[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }, (string) $new);
    if ($new !== $text && $apply) {
        file_put_contents($file, $new);
    }
}

// every old English translation of a Czech text is, the other way round, the Czech translation of that English text (also for texts
// that the code switched to English earlier or never through t() literals)
foreach ($allOld as $czech => $english) {
    if (!isset($englishKeys[$czech])) {
        $newPairs += [$english => $czech];
    }
}
foreach ($jsOld as $czech => $english) {
    $jsPairs += [$english => $czech];
}

if ($apply) {
    foreach ($cs as $name => $dict) {
        foreach ($newPairs as $en => $czech) {
            $dict += [$en => $czech];
        }
        $source = (string) file_get_contents($dir . $name . '.php');
        $header = preg_match('/^(<\?php.*?)return \[/s', $source, $m) === 1 ? $m[1] : "<?php\n\n";
        $body = '';
        foreach ($dict as $k => $v) {
            $body .= '    ' . var_export((string) $k, true) . ' => ' . var_export($v, true) . ",\n";
        }
        file_put_contents($dir . $name . '.php', $header . "return [\n" . $body . "];\n");
    }
    $csJs = $root . '/image/languages/admin-cs.js';
    $source = (string) file_get_contents($csJs);
    if (preg_match('/^(.*?window\.KALETA_TRANSLATIONS = )(\{.*\});?\s*$/s', $source, $m) === 1) {
        $dict = json_decode((string) preg_replace('/,\s*}\s*$/', '}', $m[2]), true) ?: [];
        foreach ($jsPairs as $en => $czech) {
            $dict += [$en => $czech];
        }
        file_put_contents($csJs, $m[1] . str_replace('    ', "\t", (string) json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . ";\n");
    }
}
printf("%s: %d literals switched to English, %d new Czech translations (php) + %d (js), %d unresolved\n", $apply ? 'APPLIED' : 'DRY RUN', $changed, count($newPairs), count($jsPairs), count($unresolved));
foreach ($unresolved as $u) {
    echo "  ? $u\n";
}
