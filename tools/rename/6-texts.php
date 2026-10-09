<?php
/**
 * Step 6 of the English identifiers (docs/glossary.md): UI source texts in English. The Czech source texts that the
 * English dictionaries translate become their English translation in the code, and Czech moves to dictionaries
 * (system/languages/cs.php, admin-cs.php, install-cs.php, image/languages/admin-cs.js), like every other language.
 *
 * Safe by construction: t() accepts a source text in either language – the English dictionaries keep all their Czech keys
 * (a text may still be passed in Czech elsewhere, e.g. a label translated later through t($label)) – so texts that cannot
 * switch yet stay Czech and keep working:
 *   - Czech texts that share one English translation, or are translated differently on the site and in the admin;
 *   - one lowercase word outside t()/T() (often data: element types, build keys, option values).
 * Replaced: every whole string literal that is such a text inside t()/T(), and multi-word or capitalised ones elsewhere –
 * not in MCP (own message translation), LegacyUrls, the settings defaults, the dictionaries and the migrations.
 * The other languages' dictionaries (de, fr…) are re-keyed to the English source.
 *   php tools/rename/6-texts.php [--apply]      (JavaScript needs NODE_PATH with acorn, see tools/rename-js.mjs)
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);
$apply = in_array('--apply', $argv, true);

// --- the map: Czech source => English, from the English dictionaries
$sets = ['' => require 'system/languages/en.php', 'admin-' => require 'system/languages/admin-en.php', 'install-' => require 'system/languages/install-en.php'];
$jsFile = 'image/languages/admin-en.js';
$jsSource = (string) file_get_contents($jsFile);
preg_match_all('/^\t("(?:[^"\\\\]|\\\\.)*"): ("(?:[^"\\\\]|\\\\.)*"),?$/m', $jsSource, $mm, PREG_SET_ORDER);
$js = [];
foreach ($mm as $x) {
    $js[json_decode($x[1])] = json_decode($x[2]);
}
$map = [];
$keep = [];
foreach ($sets + ['js' => $js] as $d) {
    foreach ($d as $cz => $en) {
        if (!is_string($en) || in_array($cz, ['datum_format', 'datum_slovy'], true) || $cz === $en) {
            continue;
        }
        if (isset($map[$cz]) && $map[$cz] !== $en) {
            $keep[$cz] = true;
        }
        $map[$cz] ??= $en;
    }
}
$rev = [];
foreach ($map as $cz => $en) {
    $rev[$en][] = $cz;
}
foreach ($rev as $czs) {
    if (count($czs) > 1) {
        foreach ($czs as $cz) {
            $keep[$cz] = true;
        }
    }
}
foreach ($map as $cz => $en) {
    if (isset($map[$en])) {
        $keep[$cz] = true;
    }
}
$map = array_diff_key($map, $keep);
$oneWord = fn (string $s): bool => (bool) preg_match('/^[\p{Ll}0-9_-]+$/u', $s);

// --- PHP: whole string literals
$decode = fn (string $lit): string => $lit[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($lit, 1, -1)) : stripcslashes(substr($lit, 1, -1));
$encode = fn (string $s, string $quote): string => $quote === "'" ? "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'" : '"' . addcslashes($s, "\"\\\$\n\t\r") . '"';
$skip = fn (string $f): bool => str_starts_with($f, 'system/languages/') || str_starts_with($f, 'system/src/Mcp/') || str_starts_with($f, 'system/sql/')
    || in_array($f, ['system/src/Admin/LegacyUrls.php', 'system/src/Core/Settings.php'], true) || str_starts_with($f, 'tools/');
$used = [];
$changed = [];
$count = 0;
foreach (explode("\n", trim((string) shell_exec('git ls-files system layout admin.php index.php install.php'))) as $f) {
    if (!str_ends_with($f, '.php') || $skip($f)) {
        continue;
    }
    $t = PhpToken::tokenize((string) file_get_contents($f));
    $out = '';
    foreach ($t as $i => $x) {
        if ($x->is(T_CONSTANT_ENCAPSED_STRING) && str_contains($x->text, '$') === false) {
            $v = $decode($x->text);
            if (isset($map[$v])) {
                // inside t(): the literal is the first argument of a call named t
                $j = $i - 1;
                while ($j >= 0 && $t[$j]->isIgnorable()) {
                    $j--;
                }
                $k = $j - 1;
                while ($k >= 0 && $t[$k]->isIgnorable()) {
                    $k--;
                }
                $inT = $j >= 0 && $t[$j]->text === '(' && $k >= 0 && $t[$k]->text === 't';
                if ($inT || !$oneWord($v)) {
                    $out .= $encode($map[$v], $x->text[0]);
                    $used[$v] = true;
                    $count++;
                    continue;
                }
            }
        }
        $out .= $x->text;
    }
    if ($out !== (string) file_get_contents($f)) {
        $changed[$f] = $out;
    }
}

// --- JavaScript: string literals by acorn tokens (T('…') always, others when not one lowercase word)
$node = <<<'JS'
const r = require('module').createRequire(process.env.NODE_PATH + '/');
const acorn = r('acorn');
const fs = require('fs');
const [file, mapFile] = process.argv.slice(2);
const map = JSON.parse(fs.readFileSync(mapFile, 'utf8'));
const src = fs.readFileSync(file, 'utf8');
const toks = [...acorn.tokenizer(src, { ecmaVersion: 'latest' })];
const edits = []; const used = [];
toks.forEach((t, i) => {
  if (t.type.label !== 'string' || !(t.value in map)) return;
  const inT = i >= 2 && toks[i - 1].type.label === '(' && toks[i - 2].type.label === 'name' && toks[i - 2].value === 'T';
  if (!inT && /^[\p{Ll}0-9_-]+$/u.test(t.value)) return;
  const q = src[t.start];
  const enc = q === "'" ? "'" + map[t.value].replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'" : JSON.stringify(map[t.value]);
  edits.push([t.start, t.end, enc]); used.push(t.value);
});
let out = src;
for (const [s, e, t] of edits.sort((a, b) => b[0] - a[0])) out = out.slice(0, s) + t + out.slice(e);
process.stdout.write(JSON.stringify({ out, used }));
JS;
$tmpNode = sys_get_temp_dir() . '/kaleta-6-texts.js';
$tmpMap = sys_get_temp_dir() . '/kaleta-6-map.json';
file_put_contents($tmpNode, $node);
file_put_contents($tmpMap, json_encode($map, JSON_UNESCAPED_UNICODE));
foreach (glob('image/*.js') as $f) {
    $res = json_decode((string) shell_exec('node ' . escapeshellarg($tmpNode) . ' ' . escapeshellarg($f) . ' ' . escapeshellarg($tmpMap)), true);
    if (!is_array($res)) {
        exit("JavaScript failed on $f – is NODE_PATH set?\n");
    }
    foreach ($res['used'] as $v) {
        $used[$v] = true;
        $count++;
    }
    if ($res['out'] !== file_get_contents($f)) {
        $changed[$f] = $res['out'];
    }
}

// --- dictionaries
$switched = array_intersect_key($map, $used);           // Czech => English, actually replaced in the code
$czech = array_flip($switched);                         // English => Czech
$php = function (array $d, string $comment): string {
    $s = "<?php\n/** $comment */\n\nreturn [\n";
    foreach ($d as $k => $v) {
        $s .= "    " . var_export((string) $k, true) . ' => ' . var_export($v, true) . ",\n";
    }

    return $s . "];\n";
};
$dict = [];
foreach ($sets as $prefix => $en) {
    // cs: English source => Czech for every switched text (a text may show up in any part of the system)
    $dict["system/languages/{$prefix}cs.php"] = $php($czech, 'Kaleta – Czech texts' . ($prefix === '' ? ' of the site' : ($prefix === 'admin-' ? ' of the admin' : ' of the installer')) . ' (source texts are English).');
}
// other languages of the site: re-keyed to the English source
foreach (glob('system/languages/*.php') as $f) {
    $code = basename($f, '.php');
    if (in_array($code, ['en', 'cs', 'admin-en', 'install-en', 'admin-cs', 'install-cs'], true)) {
        continue;
    }
    $d = require $f;
    $new = $d;
    foreach ($d as $k => $v) {
        if (isset($switched[$k])) {
            $new[$switched[$k]] = $v; // the English source; the Czech key stays for places that still pass the Czech text
        }
    }
    $dict[$f] = $php($new, "Kaleta – texts of the site in language '$code' (keyed by the source text: English, or Czech where it is still Czech).");
}
// admin scripts
$jsDict = function (array $d, string $comment): string {
    $s = "/* $comment */\nwindow.KALETA_PREKLAD = {\n";
    foreach ($d as $k => $v) {
        $s .= "\t" . json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ': ' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ",\n";
    }

    return rtrim($s, ",\n") . "\n};\n";
};
$dict['image/languages/admin-cs.js'] = $jsDict($czech, 'Kaleta – Czech texts of the admin scripts (T()); the source texts are English.');

echo count($changed) . " source files change, $count literals, " . count($switched) . " texts switch to English, " . count($keep) . " stay Czech\n";
if (!$apply) {
    echo "dry run – add --apply\n";
    exit(0);
}
foreach ($changed + $dict as $f => $code) {
    file_put_contents($f, $code);
}
echo "applied\n";
