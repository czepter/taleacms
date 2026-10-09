<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Site parts from the builder (table ka_casti): header and footer on all pages and wrappers around the content that the
 * system assembles (news item page, news list, 404 page). A part without a published build = the part from the layout.
 */
final class SiteParts
{
    /** type => [name, description] */
    public const array TYPES = [
        'hlavicka' => ['Header', 'Logo and navigation at the top of every page.'],
        'paticka' => ['Footer', 'Contacts, links and copyright at the bottom of every page.'],
        'novinka' => ['Detail novinky', 'A wrapper around the news item – a call to action or more news below the text, for example.'],
        'vypis' => ['News list', 'A wrapper around the news list, category, tag and search results.'],
        'nenalezeno' => ['Page not found (404)', 'A wrapper around the page-not-found message – e.g. with links onward.'],
    ];

    /** Parts that can have variants for selected pages (a landing page without navigation, a different footer…). */
    public const array WITH_VARIANTS = ['hlavicka', 'paticka'];

    public const string VARIANT_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /** @return array<string, mixed>|null row of the part (variant '' = the default version) */
    public static function row(Db $db, string $type, string $language, string $variant = ''): ?array
    {
        return $db->one('SELECT * FROM {site_parts} WHERE type = ? AND language = ? AND variant = ?', [$type, $language, $variant]);
    }

    /**
     * Saves a header or footer variant (name and the pages it applies to) and returns its key. A new variant starts
     * as a draft copy of the published default version (otherwise of the version from the layout). For the admin and Claude (MCP).
     *
     * @param list<int> $pages
     */
    public static function saveVariant(Db $db, string $type, string $language, string $variant, string $name, array $pages, string $contentLanguage): string
    {
        $items = (string) json_encode(array_values(array_unique(array_map('intval', $pages))));
        if (preg_match(self::VARIANT_PATTERN, $variant) && self::row($db, $type, $language, $variant) !== null) {
            $db->update('site_parts', ['name' => $name, 'pages' => $items, 'updated_at' => date('Y-m-d H:i:s')], ['type' => $type, 'language' => $language, 'variant' => $variant]);

            return $variant;
        }
        $variant = substr(slugify($name, 40), 0, 40);
        for ($i = 2, $base = $variant; self::row($db, $type, $language, $variant) !== null; $i++) {
            $variant = substr($base, 0, 36) . '-' . $i;
        }
        $defaults = self::row($db, $type, $language);
        $db->insert('site_parts', ['type' => $type, 'language' => $language, 'variant' => $variant, 'name' => $name, 'pages' => $items, 'updated_at' => date('Y-m-d H:i:s'),
            'build_draft' => $defaults['build'] ?? Build::toJson(self::defaults($type, $contentLanguage))]);

        return $variant;
    }

    /** Published build of the part (or the work in progress for the preview in the editor); null = the part from the layout. */
    public static function build(Db $db, string $type, string $language, bool $draft = false, string $variant = ''): ?array
    {
        $r = self::row($db, $type, $language, $variant);

        return $r === null ? null : Build::fromJson($draft ? ($r['build_draft'] ?? $r['build']) : $r['build']);
    }

    /** Variant of the part for a page (the first published one that has it in its list), otherwise '' = the default. */
    public static function pageVariant(Db $db, string $type, string $language, ?int $ids): string
    {
        if ($ids === null || !in_array($type, self::WITH_VARIANTS, true)) {
            return '';
        }
        foreach ($db->all("SELECT variant, pages FROM {site_parts} WHERE type = ? AND language = ? AND variant <> '' AND build IS NOT NULL ORDER BY variant", [$type, $language]) as $r) {
            if (in_array($ids, array_map('intval', json_decode((string) $r['pages'], true) ?: []), true)) {
                return (string) $r['variant'];
            }
        }

        return '';
    }

    /**
     * Draft of a new part in the language $column (JSON): another language starts with a copy of the same part in the default
     * language (same appearance, the texts get translated), otherwise with the default build from the layout in the content language $language.
     */
    public static function initialDraft(Db $db, string $type, string $column, string $language): string
    {
        $defaults = $column !== '' ? self::row($db, $type, '') : null;
        $json = $defaults['build_draft'] ?? $defaults['build'] ?? null;

        return $json !== null ? (string) $json : Build::toJson(self::defaults($type, $language));
    }

    /** The build the part first opens with in the builder (it matches what the layout has drawn so far). */
    public static function defaults(string $type, string $language): array
    {
        return \Kaleta\Core\Language::runWith($language, function () use ($type): array {
            $n = Build::fresh(...);
            $s = fn (array $p, array $style): array => ['style' => $style] + $p;
            $z = fn (array $p, string $htmlTag): array => ['znacka' => $htmlTag] + $p;
            $children = match ($type) {
                'hlavicka' => [$s($z($n('sekce', [], [
                    $s($n('kontejner', [], [$n('logo'), $n('navigace')]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'center', 'mezera' => 'm']]),
                ]), 'header'), ['zaklad' => ['odsazeni_y' => 's', 'pozadi' => 'pozadi', 'linka_dole' => '1px solid var(--ka-barva-linka)', 'position' => 'sticky', 'odshora' => '0', 'vrstva' => '10']])],
                'paticka' => [$s($z($n('sekce', [], [
                    $s($n('mrizka', [], [
                        $n('kontejner', [], [$s($z($n('udaje', ['udaj' => 'nazev']), 'p'), ['zaklad' => ['tloustka_pisma' => '700']]), $n('udaje', ['udaj' => 'popis']), $n('udaje', ['udaj' => 'email'])]),
                        $n('kontejner', [], [$n('navigace', ['menu' => 'paticka', 'novinky' => false, 'mobil' => false]), $n('udaje', ['udaj' => 'site'])]), // RSS only in <link rel="alternate">, a company footer does not need it
                    ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
                    $s($n('udaje', ['udaj' => 'copyright']), ['zaklad' => ['okraj_nahore' => 'l', 'velikost_pisma' => '-1', 'color' => 'tlumeny']]),
                ]), 'footer'), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'linka_nahore' => '1px solid var(--ka-barva-linka)']])],
                'novinka' => [$n('obsah', [], []), Library::section('code_challenge', \Kaleta\Core\Language::code())['element']],
                default => [$n('obsah', [], [])],
            };

            return Build::sanitize(['v' => Build::VERSION, 'deti' => $children])[0];
        });
    }

    /**
     * A ready-made template (PartTemplates) into the part's draft: the published version stays until publishing, and a
     * part that does not exist yet is created with the template as its draft. Returns false for an unknown template.
     *
     * @param list<string> $extensions
     */
    public static function applyTemplate(Db $db, string $type, string $language, string $variant, string $key, string $contentLanguage, array $extensions): bool
    {
        $build = PartTemplates::build($type, $key, $contentLanguage, $extensions);
        if ($build === null) {
            return false;
        }
        if (self::row($db, $type, $language, $variant) === null) {
            if ($variant !== '') {
                return false;
            }
            $db->insert('site_parts', ['type' => $type, 'language' => $language, 'build_draft' => Build::toJson($build), 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->update('site_parts', ['build_draft' => Build::toJson($build)], ['type' => $type, 'language' => $language, 'variant' => $variant]);
        }

        return true;
    }

    public static function versionKey(string $type, string $language, string $variant = ''): string
    {
        return $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
    }
}
