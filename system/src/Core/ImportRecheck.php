<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Build;

/**
 * Imported content checked again with today's sanitizers (3.3.3, N63). Before 3.3.2 (N23) the importers could turn the
 * text of an attribute into markup, and what they stored then is still on the site. Migration 0074 starts this check, the
 * background job "import_recheck" finishes it on a large site; the state is the setting imported_recheck (JSON).
 *
 * Only what the import map (ka_import_mapa) lists is looked at – imported news, pages and collection items – and only
 * what is risky changes, so everything an editor wrote since stays as it is:
 *  - HTML that can run a script (a <script>, an on… attribute, a javascript: address, an <object>…) goes through the
 *    sanitizer the import used: Html::safe for an import of a website (source web:…), WpContent::safeHtml for the others;
 *  - HTML whose attribute values hold a raw < or > is only written out again (Html::transform), so no regular expression
 *    can read that text as a tag; nothing else changes;
 *  - a risky build goes through Build::sanitize(…, false), the Custom HTML elements an administrator added keep their
 *    content.
 * The version before the change goes into the item's history (news and page revisions, build versions), so the owner can
 * bring it back. A second run finds nothing to change.
 */
final class ImportRecheck
{
    public const string SETTING = 'imported_recheck';

    /** Rows of the import map per database round trip. */
    public const int BATCH = 100;

    /** Attributes a browser follows as an address (and SVG animation values that can set one). */
    private const array ADDRESS_ATTRIBUTES = ['href', 'src', 'srcset', 'action', 'formaction', 'xlink:href', 'data', 'poster', 'background', 'ping', 'to', 'from', 'values', 'lowsrc', 'dynsrc', 'codebase'];

    /** Elements that run code or change where the page's links lead. */
    private const array SCRIPT_ELEMENTS = ['script', 'object', 'embed', 'applet', 'base', 'frame', 'frameset'];

    /** Starts the check (migration 0074): an earlier state is kept, so running it again never starts over. */
    public static function start(Settings $settings): void
    {
        if ($settings->get(self::SETTING) === '') {
            $settings->set(self::SETTING, (string) json_encode(['after' => null, 'checked' => 0, 'changed' => 0, 'done' => false]));
        }
    }

    /**
     * The state: after = the last import map key checked [zdroj, typ, cizi_id], checked and changed = counts, done.
     *
     * @return array{after: ?list<string>, checked: int, changed: int, done: bool}|null null = never started
     */
    public static function state(Settings $settings): ?array
    {
        $state = json_decode($settings->get(self::SETTING), true);
        if (!is_array($state)) {
            return null;
        }

        return ['after' => is_array($state['after'] ?? null) && count($state['after']) === 3 ? array_map('strval', array_values($state['after'])) : null,
            'checked' => (int) ($state['checked'] ?? 0), 'changed' => (int) ($state['changed'] ?? 0), 'done' => (bool) ($state['done'] ?? false)];
    }

    /**
     * Checks the next rows of the import map within $seconds; returns the state. Safe to run any number of times and to
     * stop at any moment: the position is saved after every batch, and a row checked twice is left as it is.
     *
     * @return array{after: ?list<string>, checked: int, changed: int, done: bool}|null
     */
    public static function run(Db $db, Settings $settings, float $seconds): ?array
    {
        $state = self::state($settings);
        if ($state === null || $state['done']) {
            return $state;
        }
        $end = microtime(true) + $seconds;
        do {
            [$zdroj, $typ, $key] = $state['after'] ?? ['', '', ''];
            $rows = $db->all("SELECT zdroj, typ, cizi_id, nase_id FROM {import_mapa} WHERE typ IN ('clanek', 'stranka', 'polozka') AND nase_id > 0
                AND (zdroj > ? OR (zdroj = ? AND typ > ?) OR (zdroj = ? AND typ = ? AND cizi_id > ?)) ORDER BY zdroj, typ, cizi_id LIMIT " . self::BATCH,
                [$zdroj, $zdroj, $typ, $zdroj, $typ, $key]);
            foreach ($rows as $r) {
                try {
                    $state['changed'] += self::recheck($db, (string) $r['zdroj'], (string) $r['typ'], (int) $r['nase_id']) ? 1 : 0;
                } catch (\Throwable $e) {
                    error_log('Import recheck: ' . $r['typ'] . ' ' . $r['nase_id'] . ' – ' . $e->getMessage()); // one odd row never stops the rest
                }
                $state['checked']++;
                $state['after'] = [(string) $r['zdroj'], (string) $r['typ'], (string) $r['cizi_id']];
            }
            $state['done'] = count($rows) < self::BATCH;
            $settings->set(self::SETTING, (string) json_encode($state, JSON_UNESCAPED_UNICODE));
        } while (!$state['done'] && microtime(true) < $end);

        return $state;
    }

    /** One imported record; true = something risky was changed. */
    private static function recheck(Db $db, string $source, string $type, int $id): bool
    {
        $sanitize = str_starts_with($source, 'web:') ? Html::safe(...) : WpContent::safeHtml(...);
        $now = date('Y-m-d H:i:s');
        if ($type === 'clanek') {
            $r = $db->one('SELECT idc, titulek, uvod, text FROM {novinky} WHERE idc = ?', [$id]);
            if ($r === null) {
                return false;
            }
            $new = ['uvod' => self::html((string) $r['uvod'], $sanitize), 'text' => self::html((string) $r['text'], $sanitize)];
            if ($new === ['uvod' => (string) $r['uvod'], 'text' => (string) $r['text']]) {
                return false;
            }
            $db->insert('novinky_revize', ['idc' => $id, 'datum' => $now, 'kdo' => null, 'titulek' => (string) $r['titulek'], 'uvod' => (string) $r['uvod'], 'text' => (string) $r['text']]);
            $db->update('novinky', $new, ['idc' => $id]);
            Search::index($db, $id);

            return true;
        }
        if ($type === 'stranka') {
            $r = $db->one('SELECT ids, titulek, text, stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [$id]);
            if ($r === null) {
                return false;
            }
            $changed = false;
            $text = self::html((string) $r['text'], $sanitize);
            if ($text !== (string) $r['text']) {
                $db->insert('stranky_revize', ['ids' => $id, 'datum' => $now, 'kdo' => null, 'titulek' => (string) $r['titulek'], 'text' => (string) $r['text']]);
                $db->update('stranky', ['text' => $text], ['ids' => $id]);
                $changed = true;
            }
            foreach (['stavba', 'stavba_koncept'] as $column) {
                $json = $r[$column] === null ? null : (string) $r[$column];
                $clean = $json === null ? null : self::build($json);
                if ($clean !== null && $clean !== $json) {
                    $db->insert('stavba_revize', ['ids' => $id, 'datum' => $now, 'kdo' => null, 'stavba' => $json]);
                    $db->update('stranky', [$column => $clean], ['ids' => $id]);
                    $changed = true;
                }
            }

            return $changed;
        }
        $r = $db->one('SELECT * FROM {kolekce_polozky} WHERE idp = ?', [$id]);
        $collection = $r === null ? null : \Kaleta\Builder\Collections::byId($db, (int) $r['idk']);
        $data = $r === null ? null : json_decode((string) $r['data'], true);
        if ($collection === null || !is_array($data)) {
            return false;
        }
        $new = $data;
        foreach ($collection['pole'] as $field) {
            if (($field['typ'] ?? '') === 'html' && is_string($data[$field['klic'] ?? ''] ?? null)) {
                $new[$field['klic']] = self::html($data[$field['klic']], WpContent::safeHtml(...)); // what Collections::sanitizeData uses
            }
        }
        if ($new === $data) {
            return false;
        }
        $db->insert('stavba_revize', ['cast' => 'polozka:' . $id, 'datum' => $now, 'kdo' => null,
            'stavba' => (string) json_encode(array_intersect_key($r, array_flip(\Kaleta\Builder\Collections::VERSIONED)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $db->update('kolekce_polozky', ['data' => (string) json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['idp' => $id]);

        return true;
    }

    /**
     * HTML as it should be stored: unchanged when it is safe, written out again when only attribute values hold a raw < or
     * >, through $sanitize when it can run a script.
     *
     * @param callable(string): string $sanitize
     */
    public static function html(string $html, callable $sanitize): string
    {
        return match (self::risk($html)) {
            0 => $html,
            1 => Html::transform($html, static function (): void {
            }),
            default => $sanitize($html),
        };
    }

    /** A build as JSON, through Build::sanitize(…, false) when any of its texts or addresses is risky; null = not a build. */
    public static function build(string $json): ?string
    {
        $build = Build::fromJson($json);
        if ($build === null) {
            return null;
        }
        if (self::buildRisk($build['deti'] ?? []) === 0) {
            return $json;
        }
        [$clean] = Build::sanitize($build, false, $build); // $previous = the build itself: Custom HTML stays as the administrator wrote it

        return Build::toJson($clean);
    }

    /**
     * 0 = safe; 1 = an attribute value holds a raw < or > (old serialization); 2 = it can run a script – a script element,
     * an object or embed, a base or refresh meta, an on… attribute, an iframe with srcdoc, a javascript:, vbscript: or
     * (outside an image) data: address.
     */
    public static function risk(string $html): int
    {
        if (!str_contains($html, '<')) {
            return 0;
        }
        $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
        $risk = 0;
        foreach ($doc->querySelectorAll('*') as $element) {
            $name = strtolower($element->localName);
            if (in_array($name, self::SCRIPT_ELEMENTS, true) || ($name === 'meta' && $element->hasAttribute('http-equiv')) || (in_array($name, ['iframe', 'frame'], true) && $element->hasAttribute('srcdoc'))) {
                return 2;
            }
            foreach ($element->attributes as $a) {
                $attribute = strtolower($a->nodeName);
                if (str_starts_with($attribute, 'on')) {
                    return 2;
                }
                if (in_array($attribute, self::ADDRESS_ATTRIBUTES, true)) {
                    foreach ($attribute === 'srcset' || $attribute === 'values' ? (preg_split('/[\s,;]+/', $a->value, -1, PREG_SPLIT_NO_EMPTY) ?: []) : [$a->value] as $address) {
                        if (self::scriptAddress($address, $name === 'img' && $attribute !== 'href')) {
                            return 2;
                        }
                    }
                }
                if (strpbrk($a->value, '<>') !== false) {
                    $risk = 1;
                }
            }
        }

        return $risk;
    }

    /** @param list<mixed> $nodes */
    private static function buildRisk(array $nodes): int
    {
        $risk = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $class = Build::className((string) ($node['typ'] ?? ''));
            if ($class !== null && $class::ADMIN_ONLY) {
                continue; // Custom HTML is the administrator's own code
            }
            $values = $node;
            unset($values['deti']);
            array_walk_recursive($values, function (mixed $value) use (&$risk): void {
                if (is_string($value) && $risk < 2) {
                    $risk = max($risk, self::scriptAddress($value, false) ? 2 : (str_contains($value, '<') ? self::risk($value) : 0));
                }
            });
            if ($risk === 2) {
                return 2;
            }
            $risk = max($risk, self::buildRisk(is_array($node['deti'] ?? null) ? $node['deti'] : []));
        }

        return $risk;
    }

    /** An address that runs a script: javascript:, vbscript:, livescript: or data: (an image's data:image/… is fine where $image). */
    private static function scriptAddress(string $value, bool $image): bool
    {
        $value = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
        if (preg_match('/^(javascript|vbscript|livescript):/', $value) === 1) {
            return true;
        }

        return str_starts_with($value, 'data:') && !($image && preg_match('#^data:image/(png|jpe?g|gif|webp|avif);#', $value) === 1);
    }
}
