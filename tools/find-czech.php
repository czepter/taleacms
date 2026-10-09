<?php
/**
 * Finds Czech in the English interface (tests/Site/EnglishInstall). Two modes:
 *
 *   php tools/find-czech.php stranka.html…   visible text of pages (text, title, placeholder, aria-label, alt, buttons, data-potvrdit)
 *   php tools/find-czech.php --de stranka.html…   the same for the German admin (2.4): German words spelled like Czech ones are fine
 *   php tools/find-czech.php --js            texts in admin scripts (image/*.js) that are neither translated in image/languages/admin-en.js nor an English source text (admin-cs.js)
 *
 * Recognizes Czech by three signs: letters with a caron or an acute accent; text that is a Czech dictionary key with an English
 * translation (= t() is missing or the translation was not used); common Czech words without diacritics as standalone words
 * (not URLs like /news).
 * Prints the findings (file: text) and exits with code 1 when there are any.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** Czech words without diacritics that have no place in English text (lowercase, whole words are compared). „Seznam“ is missing on purpose – it is also a service name. */
const WORDS = ['nebo', 'jsou', 'jako', 'pokud', 'bude', 'byla', 'bylo', 'jsme', 'jste', 'nelze', 'zde', 'tento', 'tato', 'toto', 'tyto',
    'novinky', 'novinka', 'novinek', 'kontakt', 'odkaz', 'odkazu', 'soubor', 'soubory', 'nadpis', 'nadpisy', 'obsah', 'upravit', 'smazat',
    'zobrazit', 'hledat', 'hledani', 'kotva', 'heslo', 'stavba', 'stavby', 'verze', 'firma', 'adresa', 'popis', 'popisek', 'chyba', 'druh',
    'koncept', 'kategorie', 'nastavit', 'nastaveni', 'vlastnosti', 'barva', 'sekce', 'kontejner', 'galerie', 'podklad', 'odstavec',
    'titulek', 'perex', 'aktuality', 'pravidla', 'kolekce', 'komponenta', 'komponenty', 'obnovit', 'zahodit', 'odebrat', 'posunout',
    'stranka', 'stranky', 'polozka', 'polozky', 'uzivatel', 'sluzby', 'uvod', 'znacka'];

/** German words that are spelled like Czech ones (--de). */
const GERMAN_WORDS = ['kategorie', 'kontakt', 'firma'];

/** Words with diacritics that belong in English (language names, loanwords). */
const ALLOWED = ['Čeština', 'café', 'Café'];

/** Dictionary keys that are also an English word (List = a list and a tree leaf…) are not searched for as keys. */
const DVOJZNACNE = ['List', 'Reference', 'Web', 'Region', 'Standard', 'Video', 'Menu', 'Tablet', 'Logo', 'Text', 'E-mail'];

/** @return array<string, string> Czech text => translation from all English dictionaries */
function dictionaries(string $root): array
{
    $all = [];
    foreach (['en', 'admin-en', 'install-en'] as $s) {
        $all += require $root . '/system/languages/' . $s . '.php';
    }

    return $all + jsDictionary($root);
}

/** @return array<string, string> */
function jsDictionary(string $root, string $code = 'en'): array
{
    preg_match('/window\.KALETA_TRANSLATIONS = (\{.*\});/s', (string) file_get_contents($root . '/image/languages/admin-' . $code . '.js'), $m);

    return (array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $m[1] ?? '{}'), true);
}

function hasDiacritics(string $text): bool
{
    // language names in menus (Slovenčina, Íslenska…) are in their own language on purpose
    static $languages = null;
    $languages ??= array_column((function (): array { require_once dirname(__DIR__) . '/system/src/Core/Language.php'; return \Kaleta\Core\Language::AVAILABLE; })(), 0);

    return preg_match('/[ěščřžůťďňáéíóúýĚŠČŘŽŮŤĎŇÁÉÍÓÚÝ]/u', str_replace([...ALLOWED, ...$languages], '', $text)) === 1;
}

/** Czech words in a text; words with a slash, an inner dot, @, = or an underscore are URLs and code, not text. */
function czechWords(string $text): array
{
    $finding = [];
    foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
        $word = trim($word, " \t,;:!?()[]{}\"'„“”‚‘’«».…–—-");
        if ($word === '' || preg_match('#[/@=_\#.&%]#', $word)) {
            continue;
        }
        if (in_array(mb_strtolower($word), WORDS, true)) {
            $finding[] = $word;
        }
    }

    return $finding;
}

if (($argv[1] ?? '') === '--js') {
    // every Czech text of the admin scripts (also one translated only indirectly via T(popisek)) must have a dictionary entry
    // English source texts (in admin-cs.js) need no English entry
    $dictionary = jsDictionary($root) + jsDictionary($root, 'cs');
    $findings = [];
    foreach (['admin', 'editor', 'menu', 'helper', 'builder', 'klice', 'theme'] as $file) {
        foreach (jsStrings((string) file_get_contents($root . '/image/' . $file . '.js')) as [$line, $text, $inT]) {
            // single-word strings without diacritics are keys and names in the code (stranka, sekce), not texts for people
            $czech = hasDiacritics($text) || preg_match('/\s/u', trim($text)) && czechWords($text) !== [];
            if (($czech || $inT) && !isset($dictionary[$text]) && preg_match('/\p{L}/u', $text) && !in_array($text, DVOJZNACNE, true)) {
                $findings[] = 'image/' . $file . '.js:' . $line . ': ' . $text;
            }
        }
    }
    echo $findings === [] ? '' : implode("\n", array_unique($findings)) . "\n";
    exit($findings === [] ? 0 : 1);
}

/**
 * Script strings without comments and regular expressions: [line, text, whether directly in T(…)].
 * Strings composed with a variable (`…${x}`) are taken part by part.
 *
 * @return list<array{0:int, 1:string, 2:bool}>
 */
function jsStrings(string $js): array
{
    $result = [];
    $n = strlen($js);
    $line = 1;
    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];
        $next = $js[$i + 1] ?? '';
        if ($c === "\n") {
            $line++;
        } elseif ($c === '/' && $next === '/') {
            $i = (strpos($js, "\n", $i) ?: $n) - 1;
        } elseif ($c === '/' && $next === '*') {
            $end = strpos($js, '*/', $i + 2) ?: $n;
            $line += substr_count(substr($js, $i, $end - $i), "\n");
            $i = $end + 1;
        } elseif ($c === '/' && preg_match('/[(,=:\[!&|?{};]\s*$/', substr($js, max(0, $i - 20), min(20, $i)))) {
            // regular expression: skip up to an unescaped slash outside a [class]
            for ($j = $i + 1, $className = false; $j < $n && $js[$j] !== "\n"; $j++) {
                if ($js[$j] === '\\') {
                    $j++;
                } elseif ($js[$j] === '[') {
                    $className = true;
                } elseif ($js[$j] === ']') {
                    $className = false;
                } elseif ($js[$j] === '/' && !$className) {
                    break;
                }
            }
            $i = $j;
        } elseif ($c === "'" || $c === '"' || $c === '`') {
            for ($j = $i + 1; $j < $n && $js[$j] !== $c; $j++) {
                $j += $js[$j] === '\\' ? 1 : 0;
            }
            $raw = substr($js, $i + 1, $j - $i - 1);
            $inT = (bool) preg_match('/\bT\(\s*$/', substr($js, max(0, $i - 10), min(10, $i)));
            $text = $c === '`' ? $raw : (string) json_decode('"' . str_replace(['\\\'', '"'], ["'", '\\"'], $raw) . '"');
            foreach ($c === '`' ? preg_split('/\$\{[^}]*\}/', $text) ?: [] : [$text] as $part) {
                $result[] = [$line, $part, $inT];
            }
            $line += substr_count($raw, "\n");
            $i = $j;
        }
    }

    return $result;
}

// ---------- visible text of pages ----------

$keys = [];
$patterns = [];
foreach (dictionaries($root) as $czech => $translation) {
    $czech = (string) $czech;
    if ($czech === $translation || !preg_match('/\p{L}{3}/u', $czech) || in_array($czech, DVOJZNACNE, true) || preg_match('/^[a-z_]+$/', $czech) && str_contains($czech, '_')) {
        continue;
    }
    if (preg_match('/%(\d\$)?[sd]/', $czech)) {
        // text with a filled-in value (Nalezeno: %s): searched as a pattern, only when there is enough text around the value
        $fixed = trim((string) preg_replace('/%(\d\$)?[sd]/', ' ', $czech));
        if (preg_match('/\p{L}{4}/u', $fixed)) {
            $patterns[] = '/^' . implode('.+?', array_map(fn (string $x): string => preg_quote($x, '/'), preg_split('/%(?:\d\$)?[sd]/', $czech) ?: [])) . '$/u';
        }
        continue;
    }
    $keys[$czech] = true;
}

// --de: a Czech key that is also the German translation of something (Telefon, Datum, Typ) is German text
$german = ($argv[1] ?? '') === '--de';
if ($german) {
    $keys = array_diff_key($keys, array_flip(array_map('strval', [...array_values(require $root . '/system/languages/admin-de.php'), ...array_values(require $root . '/system/languages/de.php')])));
}

$findings = 0;
foreach (array_slice($argv, $german ? 2 : 1) as $file) {
    $html = (string) file_get_contents($file);
    if (trim($html) === '') {
        continue;
    }
    $dom = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR | Dom\HTML_NO_DEFAULT_NS);
    $xp = new Dom\XPath($dom);
    $texts = [];
    foreach ($xp->query('//text()[not(ancestor::script or ancestor::style)]') as $node) {
        $texts[] = $node->textContent;
    }
    foreach ($xp->query('//@title | //@placeholder | //@aria-label | //@alt | //@data-potvrdit | //button/@value | //input[@type="submit" or @type="button" or @type="reset"]/@value | //meta[@name="description"]/@content | //optgroup/@label') as $attribute) {
        $texts[] = $attribute->value;
    }
    $printed = [];
    foreach ($texts as $text) {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || isset($printed[$text])) {
            continue;
        }
        $reason = match (true) {
            hasDiacritics($text) => 'diakritika',
            isset($keys[$text]) || isset($keys[rtrim($text, ':')]) => 'český klíč slovníku',
            (bool) array_filter($patterns, fn (string $v): bool => preg_match($v, $text) === 1) => 'český klíč slovníku',
            ($words = array_values(array_filter(czechWords($text), fn (string $w): bool => !$german || !in_array(mb_strtolower($w), GERMAN_WORDS, true)))) !== [] => 'české slovo „' . implode('“, „', $words) . '“',
            default => '',
        };
        if ($reason !== '') {
            $printed[$text] = true;
            echo '         ', mb_strimwidth($text, 0, 160, '…'), '   [', $reason, "]\n";
            $findings++;
        }
    }
}
exit($findings > 0 ? 1 : 0);
