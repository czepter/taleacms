<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;
use Kaleta\Builder\Build;

/**
 * Business facts (2.10): what the site states in many places – the year the company was founded, the number of
 * projects, a price from, the phone – kept once and written as {{fact.key}} in pages, site parts, pop-ups, components
 * and news. The site fills them in when a page is shown (never in the builder, so the token stays in the build), adds
 * facts with a schema.org property to the organisation, lists them in llms.txt and offers them to Claude.
 *
 *  - Built-in facts come from Settings → Company (company_phone, company_address…), the site name and the current year;
 *    they cannot be edited here.
 *  - A language version may have its own value of a fact; without one the default value is used.
 *  - Every change of a value is kept (ka_fact_history), and occurrences() finds the sentences that still state the old
 *    value as plain text – the claims inventory.
 */
final class Facts
{
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /** {{fact.key}} – spaces inside the braces are allowed. */
    public const string TOKEN_PATTERN = '/\{\{\s*fact\.([a-z][a-z0-9_]{1,39})\s*\}\}/';

    /** {{hours.status}} and {{hours.today}} – opening hours with their exceptions (Core\Hours). */
    public const string HOURS_PATTERN = '/\{\{\s*hours\.(status|today)\s*\}\}/';

    public const array TYPES = ['text' => 'text', 'number' => 'number', 'money' => 'amount of money', 'date' => 'date', 'year' => 'year', 'phone' => 'phone', 'email' => 'e-mail', 'url' => 'web address'];

    /** schema.org properties of the organisation a fact may fill (Front\Company::schema). */
    public const array SCHEMA_PROPS = ['' => '—', 'foundingDate' => 'founding date', 'numberOfEmployees' => 'number of employees', 'priceRange' => 'price range',
        'slogan' => 'slogan', 'award' => 'award', 'areaServed' => 'area served', 'knowsLanguage' => 'languages', 'founder' => 'founder'];

    /** Built-in facts from the settings (read-only) => label. */
    public const array BUILT_IN = ['site_name' => 'Site name', 'company_name' => 'Company name', 'company_phone' => 'Phone', 'company_email' => 'E-mail',
        'company_address' => 'Address', 'company_id' => 'Company ID', 'company_vat_id' => 'VAT ID', 'year' => 'Current year'];

    /** @var array<string, array<string, array<string, mixed>>> facts by language for this request */
    private static array $cache = [];

    /**
     * Every fact for a language version: the site's own ones (with the default value where the language has none) and
     * the built-in ones.
     *
     * @return array<string, array{key: string, label: string, type: string, value: string, display: string, schema: string, source: string, updated: ?string, builtIn: bool, translated: bool}>
     */
    public static function all(App $app, string $language = ''): array
    {
        if (isset(self::$cache[$language])) {
            return self::$cache[$language];
        }
        $s = $app->settings();
        $out = [];
        foreach (self::BUILT_IN as $key => $label) {
            $value = match ($key) {
                'site_name' => $s->get('site_name'),
                'company_address' => implode(', ', \Kaleta\Front\Company::address($s)),
                'year' => date('Y'),
                default => $s->get($key),
            };
            $type = match ($key) { 'company_phone' => 'phone', 'company_email' => 'email', 'year' => 'year', default => 'text' };
            $out[$key] = ['key' => $key, 'label' => t($label), 'type' => $type, 'value' => $value, 'display' => $value, 'schema' => '', 'source' => '', 'updated' => null, 'builtIn' => true, 'translated' => false];
        }
        try {
            $rows = $app->db()->all('SELECT * FROM {facts} WHERE language IN (?, ?) ORDER BY fact_key, language', ['', $language]);
        } catch (\Throwable) {
            $rows = []; // before the 2.10 migration
        }
        foreach ($rows as $r) {
            $key = (string) $r['fact_key'];
            if ((string) $r['language'] !== '' && isset($out[$key])) {
                $out[$key]['value'] = (string) $r['value'];
                $out[$key]['translated'] = true;
                $out[$key]['display'] = self::display((string) $out[$key]['type'], (string) $r['value']);
                continue;
            }
            $out[$key] = ['key' => $key, 'label' => (string) $r['label'], 'type' => (string) $r['type'], 'value' => (string) $r['value'], 'display' => self::display((string) $r['type'], (string) $r['value']),
                'schema' => (string) $r['schema_prop'], 'source' => (string) $r['source'], 'updated' => (string) $r['updated_at'], 'builtIn' => false, 'translated' => false];
        }

        return self::$cache[$language] = $out;
    }

    /** The value as the site shows it: numbers with the thousands separator of the language, a date in its format. */
    public static function display(string $type, string $value): string
    {
        return match ($type) {
            'number' => is_numeric($value) ? format_count((float) $value, str_contains($value, '.') ? strlen(substr(strrchr($value, '.') ?: '', 1)) : 0) : $value,
            'money' => preg_match('/^(\d+(?:\.\d+)?)\s*([A-Z]{3})?$/', $value, $m) ? format_count((float) $m[1], str_contains($m[1], '.') ? 2 : 0) . (($m[2] ?? '') !== '' ? "\u{00A0}" . $m[2] : '') : $value,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? format_date($value) : $value,
            default => $value,
        };
    }

    /** A value checked by the type of the fact; null = not valid. */
    public static function clean(string $type, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '{{')) {
            return $value === '' ? '' : null; // a fact never contains another token
        }

        return match ($type) {
            'number' => preg_match('/^-?\d+(\.\d+)?$/', str_replace([' ', "\u{00A0}"], '', $value)) ? str_replace([' ', "\u{00A0}"], '', $value) : null,
            'money' => preg_match('/^\d+(\.\d{1,2})?(\s+[A-Z]{3})?$/', $value) ? $value : null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null,
            'year' => preg_match('/^\d{4}$/', $value) ? $value : null,
            'phone' => preg_match('/^[+()\d\s\/.-]{3,30}$/', $value) ? $value : null,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
            'url' => preg_match('#^https?://[^\s"<>]{3,400}$#i', $value) ? $value : null,
            default => mb_substr(strip_tags($value), 0, 500),
        };
    }

    /** Fills {{fact.key}} in HTML of the site (escaped); an unknown fact becomes empty – the site audit reports it. */
    public static function fill(string $html, App $app): string
    {
        if (!str_contains($html, '{{')) {
            return $html;
        }
        $facts = self::all($app, Language::siteColumn());
        $html = (string) preg_replace_callback(self::TOKEN_PATTERN, fn (array $m): string => e((string) ($facts[$m[1]]['display'] ?? '')), $html);

        return (string) preg_replace_callback(self::HOURS_PATTERN, fn (array $m): string => e(self::hours($app, $m[1])), $html);
    }

    /** {{hours.status}} = open now / closed, until when; {{hours.today}} = today's hours (2.10, Core\Hours). */
    private static function hours(App $app, string $what): string
    {
        return $what === 'status' ? Hours::statusText($app) : Hours::todayText($app);
    }

    /** Fills {{fact.key}} in plain text (titles, descriptions, llms.txt) – not escaped. */
    public static function fillText(string $text, App $app): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        $facts = self::all($app, Language::siteColumn());
        $text = (string) preg_replace_callback(self::TOKEN_PATTERN, fn (array $m): string => (string) ($facts[$m[1]]['display'] ?? ''), $text);

        return (string) preg_replace_callback(self::HOURS_PATTERN, fn (array $m): string => self::hours($app, $m[1]), $text);
    }

    /**
     * schema.org properties of the organisation from facts that have one (Front\Seo adds them to the company node).
     *
     * @return array<string, mixed>
     */
    public static function schema(App $app): array
    {
        $out = [];
        foreach (self::all($app, Language::siteColumn()) as $f) {
            if ($f['schema'] === '' || $f['value'] === '') {
                continue;
            }
            $out[$f['schema']] = $f['schema'] === 'numberOfEmployees' ? ['@type' => 'QuantitativeValue', 'value' => is_numeric($f['value']) ? (float) $f['value'] + 0 : $f['value']]
                : ($f['schema'] === 'founder' ? ['@type' => 'Person', 'name' => $f['value']] : $f['value']);
        }

        return $out;
    }

    /**
     * Saves a fact (or its value in a language version). Returns null, or the error.
     *
     * @param array{label?: string, type?: string, value?: string, schema?: string, source?: string} $data
     */
    public static function save(App $app, string $key, array $data, string $language = ''): ?string
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return 'The key may contain lowercase letters, digits and _ and must start with a letter (2–40 characters).';
        }
        if (isset(self::BUILT_IN[$key])) {
            return 'This fact comes from the settings (Settings → Company) – change it there.';
        }
        $db = $app->db();
        $existing = $db->one('SELECT * FROM {facts} WHERE fact_key = ? AND language = ?', [$key, $language]);
        $base = $language === '' ? $existing : $db->one("SELECT * FROM {facts} WHERE fact_key = ? AND language = ''", [$key]);
        if ($language !== '' && $base === null) {
            return 'Create the fact in the default language first.';
        }
        $type = $language !== '' ? (string) $base['type'] : (isset(self::TYPES[$data['type'] ?? '']) ? (string) $data['type'] : (string) ($existing['type'] ?? 'text'));
        $value = self::clean($type, (string) ($data['value'] ?? ($existing['value'] ?? '')));
        if ($value === null) {
            return t('The value does not fit the type of the fact (%s).', t(self::TYPES[$type]));
        }
        $row = ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')];
        if ($language === '') {
            $row += ['type' => $type, 'label' => mb_substr(trim((string) ($data['label'] ?? ($existing['label'] ?? $key))), 0, 150),
                'schema_prop' => isset($data['schema']) && isset(self::SCHEMA_PROPS[$data['schema']]) ? (string) $data['schema'] : (string) ($existing['schema_prop'] ?? ''),
                'source' => mb_substr(trim(strip_tags((string) ($data['source'] ?? ($existing['source'] ?? '')))), 0, 255)];
        }
        if ($existing === null) {
            $db->insert('facts', $row + ['fact_key' => $key, 'language' => $language]);
        } else {
            $db->update('facts', $row, ['id' => (int) $existing['id']]);
        }
        if ($existing !== null && (string) $existing['value'] !== $value) {
            $db->insert('fact_history', ['fact_key' => $key, 'language' => $language, 'old_value' => (string) $existing['value'], 'new_value' => $value, 'changed_at' => date('Y-m-d H:i:s')]);
            Events::record($db, 'fact.changed', 'info', t('The fact %s changed.', $key), ['fact' => $key, 'language' => $language]);
        }
        ChangeLog::write($app, 'facts', $existing === null ? 'create' : 'update', $key . ($language !== '' ? ' (' . $language . ')' : ''));
        self::$cache = [];
        \Kaleta\Front\Cache::clear(); // cached pages show the new value right away

        return null;
    }

    public static function delete(App $app, string $key): bool
    {
        $deleted = $app->db()->delete('facts', ['fact_key' => $key]) > 0;
        if ($deleted) {
            ChangeLog::write($app, 'facts', 'delete', $key);
            self::$cache = [];
            \Kaleta\Front\Cache::clear();
        }

        return $deleted;
    }

    /** @return list<array{language: string, old_value: string, new_value: string, changed_at: string}> */
    public static function history(Db $db, string $key): array
    {
        return array_map(fn (array $r): array => ['language' => (string) $r['language'], 'old_value' => (string) $r['old_value'], 'new_value' => (string) $r['new_value'], 'changed_at' => (string) $r['changed_at']],
            $db->all('SELECT language, old_value, new_value, changed_at FROM {fact_history} WHERE fact_key = ? ORDER BY id DESC LIMIT 20', [$key]));
    }

    /**
     * Every piece of content as plain text, with where it is: pages, news, collection items, site parts, pop-ups and
     * components (published versions).
     *
     * @return \Generator<array{kind: string, where: string, target: array<string, int|string>, edit: string, text: string}>
     */
    public static function texts(Db $db): \Generator
    {
        foreach ($db->all('SELECT ids, titulek, text, stavba, jazyk FROM {stranky} WHERE smazano IS NULL') as $p) {
            $build = Build::fromJson((string) ($p['stavba'] ?? ''));
            yield ['kind' => 'page', 'where' => (string) $p['titulek'], 'target' => ['page' => (int) $p['ids']], 'edit' => 'admin.php?module=pages&action=edit&id=' . (int) $p['ids'],
                'text' => $build !== null ? Build::asText($build) : (string) $p['text']]; // HTML – sentences() breaks it at block ends
        }
        foreach ($db->all('SELECT idc, titulek, uvod, text FROM {novinky} WHERE smazano IS NULL') as $n) {
            yield ['kind' => 'news', 'where' => (string) $n['titulek'], 'target' => ['news' => (int) $n['idc']], 'edit' => 'admin.php?module=news&action=edit&id=' . (int) $n['idc'],
                'text' => $n['titulek'] . "\n" . $n['uvod'] . "\n" . $n['text']];
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.popis, p.data, k.nazev AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NULL') as $i) {
            $values = json_decode((string) $i['data'], true);
            yield ['kind' => 'item', 'where' => $i['kolekce'] . ': ' . $i['nazev'], 'target' => ['collection' => (int) $i['idk'], 'item' => (int) $i['idp']],
                'edit' => 'admin.php?module=collections&action=item&id=' . (int) $i['idk'] . '&polozka=' . (int) $i['idp'],
                'text' => $i['nazev'] . "\n" . $i['popis'] . "\n" . implode("\n", array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($values) ? $values : []))];
        }
        foreach ($db->all('SELECT typ, jazyk, varianta, nazev, stavba FROM {casti}') as $c) {
            $build = Build::fromJson((string) ($c['stavba'] ?? ''));
            if ($build !== null) {
                yield ['kind' => 'part', 'where' => (string) ($c['nazev'] ?: $c['typ']), 'target' => ['part' => (string) $c['typ']], 'edit' => 'admin.php?module=parts', 'text' => Build::asText($build)];
            }
        }
        foreach ($db->all('SELECT idpp, nazev, stavba FROM {popupy}') as $p) {
            $build = Build::fromJson((string) ($p['stavba'] ?? ''));
            if ($build !== null) {
                yield ['kind' => 'popup', 'where' => (string) $p['nazev'], 'target' => ['popup' => (int) $p['idpp']], 'edit' => 'admin.php?module=popups&action=edit&id=' . (int) $p['idpp'], 'text' => Build::asText($build)];
            }
        }
        foreach ($db->all('SELECT idm, nazev, stavba FROM {komponenty}') as $m) {
            $build = Build::fromJson((string) ($m['stavba'] ?? ''));
            if ($build !== null) {
                yield ['kind' => 'component', 'where' => (string) $m['nazev'], 'target' => ['component' => (int) $m['idm']], 'edit' => 'admin.php?module=components&action=edit&id=' . (int) $m['idm'], 'text' => Build::asText($build)];
            }
        }
    }

    /**
     * Where the text is stated in the content as plain text (not as a token) – e.g. the old value of a changed fact.
     * Digits match also with different spaces in between (1500, 1 500, 1 500).
     *
     * @return list<array{kind: string, where: string, target: array<string, int|string>, edit: string, sentence: string}>
     */
    public static function occurrences(Db $db, string $needle, int $limit = 100): array
    {
        $needle = trim($needle);
        if (mb_strlen($needle) < 2) {
            return [];
        }
        $pattern = '/' . implode('[\s\x{00A0}\x{202F}]*', array_map(fn (string $ch): string => preg_quote($ch, '/'), preg_split('//u', preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $needle) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [])) . '/iu';
        // a number must not be part of a longer one (2004 in 12004)
        if (preg_match('/^\d/', $needle)) {
            $pattern = '/(?<![\d])' . substr($pattern, 1, -3) . '(?![\d])/iu';
        }
        $out = [];
        foreach (self::texts($db) as $t) {
            foreach (self::sentences($t['text']) as $sentence) {
                if (preg_match($pattern, $sentence) === 1) {
                    $out[] = ['kind' => $t['kind'], 'where' => $t['where'], 'target' => $t['target'], 'edit' => $t['edit'], 'sentence' => mb_substr($sentence, 0, 300)];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Sentences that state something countable – a year, a number, a percentage, an amount – without a fact token: the
     * candidates for facts (the claims inventory for Claude).
     *
     * @return list<array{kind: string, where: string, target: array<string, int|string>, edit: string, sentence: string}>
     */
    public static function claims(Db $db, int $limit = 200): array
    {
        $out = [];
        foreach (self::texts($db) as $t) {
            foreach (self::sentences($t['text']) as $sentence) {
                if (preg_match('/\d/', $sentence) === 1 && !str_contains($sentence, '{{') && preg_match('/(\b(19|20)\d{2}\b|\d[\d\s\x{00A0}]*\s*(%|\+|×|x\b|let|years|jahre|klient|client|kunden|projekt|project|realiz|zakáz|kč|czk|eur|€|\$))/iu', $sentence) === 1) {
                    $out[] = ['kind' => $t['kind'], 'where' => $t['where'], 'target' => $t['target'], 'edit' => $t['edit'], 'sentence' => mb_substr($sentence, 0, 300)];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Which facts are used where (by their tokens).
     *
     * @return array<string, int> key => number of places
     */
    public static function usage(Db $db): array
    {
        $counts = [];
        foreach (self::texts($db) as $t) {
            preg_match_all(self::TOKEN_PATTERN, $t['text'], $m);
            foreach (array_unique($m[1]) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        // builds give HTML: block ends become line breaks, the tags go
        $text = strip_tags((string) preg_replace('#<(br|/p|/h[1-6]|/li|/div|/td|/th|/blockquote|/figcaption)\b[^>]*>#i', "\n", $text));
        $text = (string) preg_replace('/[ \t]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: []), fn (string $s): bool => $s !== ''));
    }
}
