<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Language;
use Talea\Tests\Support\FakeSettings;

final class TranslationsTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en'); // a language a test switched to does not leak into the next test
    }

    /** the English dictionary covers site and admin texts */
    public function testSourceTextsAreEnglish(): void
    {
        $missingTranslation = static function (string $dictionary, array $patterns): array {
            // the source texts are English: a t() text with Czech letters is a mistake unless the Czech dictionary lists it as a key
            $translations = require TALEA_ROOT . '/system/languages/' . str_replace('en.php', 'cs.php', $dictionary);
            $missing = [];
            foreach ($patterns as $pattern) {
                foreach (glob(TALEA_ROOT . '/' . $pattern) ?: [] as $file) {
                    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/u", (string) file_get_contents($file), $m);
                    foreach ($m[1] as $k) {
                        $k = stripslashes($k);
                        if (!isset($translations[$k]) && preg_match('/[áčďéěíňóřšťúůýž]/iu', $k)) { // check-english: allow
                            $missing[] = basename($file) . ': ' . $k;
                        }
                    }
                }
            }

            return array_values(array_unique($missing));
        };
        $this->assertSame([], $missingTranslation('en.php', ['system/views/front/*.php', 'system/src/Front/*.php', 'system/src/Builder/*.php', 'system/src/Builder/Elements/*.php']), 'Front-end source texts are English (t() without Czech diacritics)');
        $this->assertSame([[], [], []], array_map(fn (string $f): array => array_values(array_diff(array_keys(require TALEA_ROOT . '/system/languages/' . $f), ['datum_format', 'date_in_words'])), ['en.php', 'admin-en.php', 'install-en.php']), 'English dictionaries carry only the data-driven keys (English is the source language)');
    }

    public function testSiteDictionaries(): void
    {
        // dictionaries of the site languages: same keys as the Czech one (English source texts), the same %s and tags in the values
        $sourceDictionary = require TALEA_ROOT . '/system/languages/cs.php';
        $dataNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $brokenDictionaries = [];
        foreach (glob(TALEA_ROOT . '/system/languages/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            // a language (de) or a regional version holding what differs (pt-br); not the admin and installer sets, not the German -du overlay
            if ($code === 'en' || $code === 'cs' || preg_match('/^' . \Talea\Core\Language::TAG . '$/D', $code) !== 1 || str_ends_with($code, '-du')) {
                continue;
            }
            $dictionary = require $file;
            if (!isset(\Talea\Core\Language::AVAILABLE[$code])) {
                $brokenDictionaries[] = $code . ': language is not in Language::AVAILABLE';
            }
            foreach ($dictionary as $key => $translation) {
                if (!isset($sourceDictionary[$key]) && !in_array($key, $dataNames, true) && $key !== 'date_in_words' && $key !== 'datum_format') {
                    $brokenDictionaries[] = $code . ': extra "' . $key . '"';
                } elseif (isset($sourceDictionary[$key]) && (preg_match_all('/%(?:\d+\$)?[sd]/', $key) !== preg_match_all('/%(?:\d+\$)?[sd]/', $translation) || substr_count($key, '<') !== substr_count($translation, '<'))) {
                    $brokenDictionaries[] = $code . ': placeholders or tags in "' . $key . '"';
                }
            }
        }
        $this->assertSame([], $brokenDictionaries, 'Site language dictionaries: keys from cs.php, same %s and HTML');
    }

    public function testNewerVisitorDictionariesAreComplete(): void
    {
        // Portuguese, Chinese (Simplified and Traditional) and Hungarian translate every text the Arabic dictionary does (#159)
        $keys = array_keys(require TALEA_ROOT . '/system/languages/ar.php');
        foreach (['pt', 'zh', 'zh-hant', 'hu'] as $code) {
            $dictionary = require TALEA_ROOT . '/system/languages/' . $code . '.php';
            $this->assertSame([], array_values(array_diff($keys, array_keys($dictionary))), $code . ' misses texts');
            $this->assertSame([], array_keys(array_filter($dictionary, fn (string $v): bool => trim($v) === '')), $code . ' has empty texts');
        }
    }

    /** installer: every text has a translation in all languages */
    public function testInstallerDictionaries(): void
    {
        $keys = [];
        foreach (['system/views/install/form.php', 'system/views/install/done.php', 'system/src/Install/Installer.php'] as $file) {
            preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(TALEA_ROOT . '/' . $file), $found);
            foreach ($found[1] as $text) {
                $keys[stripslashes($text)] = true;
            }
        }
        // the installer prints the extension and sample site cards via t() from constants
        foreach ([...array_values(\Talea\Core\Extensions::CATALOG), ...array_values(\Talea\Builder\Library::SITES)] as $card) {
            $keys[$card['name'] ?? $card[0]] = true;
            $keys[$card['description'] ?? $card[1]] = true;
        }
        foreach (['en', 'de'] as $code) {
            // English: Czech keys to English on top of the English source texts; German (2.5): every source text translated
            $dictionary = (require TALEA_ROOT . '/system/languages/install-' . $code . '.php') + ($code === 'en' ? require TALEA_ROOT . '/system/languages/install-cs.php' : []);
            // international words are not translated (the dictionary tool does not write identical entries)
            $missing = array_values(array_diff(array_keys($keys), array_keys($dictionary), ['Server', 'Port', 'E-mail', 'Newsletter']));
            $this->assertSame([], $missing, 'installer: complete dictionary ' . $code);
        }
    }

    /** German in two registers: formal (Sie) and informal (du), issue #20 */
    public function testGermanRegisters(): void
    {
        // A form of address is a capitalised Sie/Ihr… inside a sentence; at the start of a sentence (or after a quotation mark) it is the application or a term ("Sie handelt…").
        $addressForms = static function (string $text): int {
            $count = 0;
            preg_match_all('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen)\b/u', $text, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [, $offset]) {
                $before = rtrim(substr($text, 0, $offset));
                if ($before !== '' && preg_match('/[.!?:\n„“"»(–]$/u', $before) !== 1) {
                    $count++;
                }
            }

            return $count;
        };
        // a sentence that starts with Sie/Ihr is counted when the English source speaks to the reader ("Your request" → "Ihre Anfrage")
        $needsOverlay = static fn (string $english, string $german): bool => $addressForms($german) > 0
            || (preg_match('/\byou(r|rs|rself)?\b/i', $english) === 1 && preg_match('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen)\b/u', $german) === 1);
        $scriptDictionary = static function (string $file): array {
            return json_decode((string) preg_replace('/,\s*\}$/', '}', rtrim(preg_replace('/^[^{]*/', '', (string) file_get_contents($file), 1), " \n;)")), true) ?? [];
        };
        foreach (['admin-de', 'de', 'install-de'] as $set) {
            $base = require TALEA_ROOT . '/system/languages/' . $set . '.php';
            $overlay = require TALEA_ROOT . '/system/languages/' . $set . '-du.php';
            $formalLeft = array_keys(array_filter($overlay, fn ($v, $k): bool => $addressForms((string) $v) > 0, ARRAY_FILTER_USE_BOTH));
            $withoutOverlay = array_keys(array_filter($base, fn ($v, $k): bool => is_string($v) && $needsOverlay((string) $k, $v) && !isset($overlay[$k]), ARRAY_FILTER_USE_BOTH));
            $this->assertSame([[], [], []], [array_keys(array_diff_key($overlay, $base)), $formalLeft, $withoutOverlay], 'Deutsch du: ' . $set . '-du.php – only keys of the base dictionary, no Sie/Ihr in the overlay, every base string with a form of address has its counterpart');
        }
        $baseScripts = $scriptDictionary(TALEA_ROOT . '/image/languages/admin-de.js');
        $overlayScripts = $scriptDictionary(TALEA_ROOT . '/image/languages/admin-de-du.js');
        $this->assertSame([[], [], []], [
            array_keys(array_diff_key($overlayScripts, $baseScripts)),
            array_keys(array_filter($overlayScripts, fn ($v): bool => $addressForms((string) $v) > 0)),
            array_keys(array_filter($baseScripts, fn ($v, $k): bool => $needsOverlay((string) $k, (string) $v) && !isset($overlayScripts[$k]), ARRAY_FILTER_USE_BOTH)),
        ], 'Deutsch du: admin-de-du.js – only keys of admin-de.js, no Sie/Ihr in the overlay, every script text with a form of address has its counterpart');
        // every Claude panel suggestion (AskClaude::EXAMPLES) follows the register of the administration
        $suggestionsLeft = [];
        foreach (['formal', 'informal'] as $register) {
            foreach (\Talea\Core\AskClaude::EXAMPLES as $key => [, $suggestion]) {
                $text = \Talea\Core\Language::runWith('de', fn (): string => t($suggestion), 'admin-', $register);
                if (($addressForms($text) > 0) !== false && $register === 'informal') {
                    $suggestionsLeft[] = $register . ':' . $key;
                }
            }
        }
        $this->assertSame([], $suggestionsLeft, 'Deutsch du: the Claude panel suggestions have no Sie in the informal administration');
        $this->assertSame(['Geben Sie mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.', 'Enter at least 3 characters.', 'xformal', 'Gib mindestens 3 Zeichen ein.'], [
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'formal'),
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'informal'),
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-', 'informal'),
            \Talea\Core\Language::runWith('en', fn (): string => t('Enter at least 3 characters.'), '', 'informal'),
            \Talea\Core\Language::runWith('de', fn (): string => \Talea\Core\Language::runWith('de', fn (): string => 'x', '', 'informal') . \Talea\Core\Language::register(), '', 'formal'),
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'nonsense'),
        ], 'Deutsch du: the register picks the dictionary (site, admin), is restored after runWith, and English ignores it');
        \Talea\Core\Language::setSiteRegister('informal');
        \Talea\Core\Language::setAdminRegister('formal');
        $this->assertSame(['Gib mindestens 3 Zeichen ein.', 'Geben Sie mindestens 3 Zeichen ein.'], [
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.')),
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-'),
        ], 'Deutsch du: without an explicit register the site follows german_register and the administration the user’s choice');
        \Talea\Core\Language::setSiteRegister('');
        \Talea\Core\Language::setAdminRegister('');
        $this->assertSame(['Gib mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.'], [
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.')),
            \Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-'),
        ], 'Deutsch du: without a choice (an empty register, no german_register) the site and the administration say du');
        \Talea\Core\Language::set('cs', 'admin-');
        // the form of address of the visitors reaches Claude: the connection instructions, site_info and the text copied from the dashboard
        $this->assertSame(['informal', 'informal', 'informal', null, 'informal', 'formal'], [
            \Talea\Core\Language::visitorAddress(FakeSettings::site(['site_language' => 'de', 'german_register' => 'informal'])),
            \Talea\Core\Language::visitorAddress(FakeSettings::site(['site_language' => 'de'])),
            \Talea\Core\Language::visitorAddress(FakeSettings::site(['site_language' => 'cs', 'additional_languages' => 'de', 'german_register' => 'informal', 'extensions' => 'languages'])),
            \Talea\Core\Language::visitorAddress(FakeSettings::site(['site_language' => 'en', 'german_register' => 'informal'])),
            \Talea\Core\Language::normalizeRegister('x'),
            \Talea\Core\Language::visitorAddress(FakeSettings::site(['site_language' => 'de', 'german_register' => 'formal'])),
        ], 'Deutsch du: Language::visitorAddress – only a site with a German version has one');
        $promptIn = fn (string $language, ?string $address, string $register = 'formal'): string => \Talea\Core\Language::runWith($language, fn (): string => \Talea\Core\AskClaude::prompt('https://example.com', $address), 'admin-', $register);
        $this->assertSame([true, true, false, true, true], [
            str_contains($promptIn('en', 'informal'), 'informal “du”'), str_contains($promptIn('en', 'formal'), 'formal “Sie”'), str_contains($promptIn('en', null), 'German'),
            str_contains($promptIn('de', 'formal'), 'Schreiben Sie deutsche Texte'), str_contains($promptIn('de', 'formal', 'informal'), 'Schreibe deutsche Texte'),
        ], 'Deutsch du: the text copied for Claude names the form of address of the visitors – in the language and register of the administration');
    }

    /** builder English: editor texts (JS) and schema labels (PHP) */
    public function testBuilderTexts(): void
    {
        $builderScripts = '';
        foreach (glob(TALEA_ROOT . '/image/builder/*.js') ?: [] as $builderScript) { // the editor's ES modules, the direct manipulation on the canvas included
            $builderScripts .= (string) file_get_contents($builderScript);
        }
        preg_match_all("/\bT\('((?:[^'\\\\]|\\\\.)*)'\)/", $builderScripts, $enJs);
        preg_match('/window\.TALEA_TRANSLATIONS = (\{.*\});/s', (string) file_get_contents(TALEA_ROOT . '/image/languages/admin-en.js'), $enJsDictionary);
        $enJsKeys = array_keys((array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $enJsDictionary[1] ?? '{}'), true));
        preg_match('/window\.TALEA_TRANSLATIONS = (\{.*\});/s', (string) file_get_contents(TALEA_ROOT . '/image/languages/admin-cs.js'), $csJsDictionary);
        $enJsKeys = [...$enJsKeys, ...array_keys((array) json_decode((string) preg_replace('/,\s*\}$/', '}', $csJsDictionary[1] ?? '{}'), true))];
        $this->assertSame([], array_values(array_diff(array_unique(array_map('stripslashes', $enJs[1])), $enJsKeys, ['Tablet', 'Menu'])), 'Builder: all editor texts have an English translation');
        $enAdmin = (require TALEA_ROOT . '/system/languages/admin-en.php') + (require TALEA_ROOT . '/system/languages/admin-cs.php');
        $enSchema = \Talea\Builder\ElementSchema::schema(true, 'cs', true);
        $enTexts = array_merge(array_column($enSchema['elements'], 'name'), array_column($enSchema['elements'], 'description'), array_column($enSchema['elements'], 'group'), array_values($enSchema['style_groups']));
        $enFields = function (array $properties) use (&$enFields, &$enTexts): void {
            foreach ($properties as $d) {
                $enTexts[] = (string) ($d['label'] ?? '');
                array_push($enTexts, ...array_values($d['options'] ?? []));
                if (isset($d['fields'])) {
                    $enFields($d['fields']);
                }
            }
        };
        foreach ($enSchema['elements'] as $p) {
            $enFields($p['properties']);
        }
        $enFields($enSchema['style']);
        $this->assertSame([], array_values(array_filter(array_unique($enTexts), fn (string $x): bool => $x !== '' && preg_match('/\p{L}/u', $x) === 1 && !isset($enAdmin[$x]) && !in_array($x, ['Video', 'Logo', 'HTML', 'Text', 'text'], true))), 'Builder: all schema labels have an English translation');
    }

    /** 2.4: German administration */
    public function testGermanAdministration(): void
    {
        // every admin text translated into Czech has a German translation too, with the same placeholders and tags
        $placeholders = function (string $text): array { preg_match_all('/%(?:\d+\$)?[sd]|<\/?[a-z]+/i', $text, $m); sort($m[0]); return $m[0]; };
        $jsDictionary = function (string $file): array { preg_match_all('/^\t("(?:[^"\\\\]|\\\\.)*"):\s*("(?:[^"\\\\]|\\\\.)*"),?$/m', (string) file_get_contents($file), $m, PREG_SET_ORDER);
            return array_combine(array_map(fn (array $x): string => json_decode($x[1]), $m), array_map(fn (array $x): string => json_decode($x[2]), $m)); };
        $deGaps = [];
        foreach ([[require TALEA_SYSTEM . '/languages/admin-cs.php', require TALEA_SYSTEM . '/languages/admin-de.php'],
            [$jsDictionary(TALEA_ROOT . '/image/languages/admin-cs.js'), $jsDictionary(TALEA_ROOT . '/image/languages/admin-de.js')]] as [$csTexts, $deTexts]) {
            foreach ($csTexts as $key => $_) {
                $key = (string) $key;
                if (!isset($deTexts[$key]) && !in_array($key, ['Name'], true)) { $deGaps[] = 'missing: ' . $key; }
                elseif (isset($deTexts[$key]) && $placeholders($key) !== $placeholders((string) $deTexts[$key])) { $deGaps[] = 'placeholders: ' . $key; }
            }
        }
        $this->assertSame([], array_slice($deGaps, 0, 5), '2.4: German admin covers every Czech admin text');
        $this->assertSame([true, true], [isset(\Talea\Core\Language::ADMIN_LANGUAGES['de']), count($jsDictionary(TALEA_ROOT . '/image/languages/admin-de.js')) > 2500], '2.4: German is an admin language');

        $this->assertSame([1, 3, 4], array_column(\Talea\Core\Alerts::worth([
            ['id' => 1, 'created_at' => '', 'type' => 'backup.failed', 'severity' => 'error', 'message' => '', 'data' => []],
            ['id' => 2, 'created_at' => '', 'type' => 'fleet.site_updated', 'severity' => 'warning', 'message' => '', 'data' => []],
            ['id' => 3, 'created_at' => '', 'type' => 'content.review', 'severity' => 'warning', 'message' => '', 'data' => []],
            ['id' => 4, 'created_at' => '', 'type' => 'notfound.spike', 'severity' => 'warning', 'message' => '', 'data' => []]]), 'id'), '2.10: alert e-mails – errors and the warnings that need the owner, not every warning');
    }
}
