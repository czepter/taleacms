<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Db;

/**
 * Site parts from the builder (table tl_site_parts): header and footer on all pages and wrappers around the content that the
 * system assembles (news item page, news list, 404 page). A part without a published build = the part from the layout.
 */
final class SiteParts
{
    /** type => [name, description] */
    public const array TYPES = [
        'header' => ['Header', 'Logo and navigation at the top of every page.'],
        'footer' => ['Footer', 'Contacts, links and copyright at the bottom of every page.'],
        'news_item' => ['News item', 'A wrapper around the news item – a call to action or more news below the text, for example.'],
        'list' => ['News list', 'A wrapper around the news list, category, tag and search results.'],
        'not_found' => ['Page not found (404)', 'A wrapper around the page-not-found message – e.g. with links onward.'],
    ];

    /** Parts that can have variants for selected pages (a landing page without navigation, a different footer…). */
    public const array WITH_VARIANTS = ['header', 'footer'];

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
        return \Talea\Core\Language::runWith($language, function () use ($type): array {
            $n = Build::fresh(...);
            $s = fn (array $p, array $style): array => ['style' => $style] + $p;
            $z = fn (array $p, string $htmlTag): array => ['tag' => $htmlTag] + $p;
            $children = match ($type) {
                'header' => [$s($z($n('section', [], [
                    $s($n('container', [], [$n('logo'), $n('navigation')]), ['base' => ['display' => 'flex', 'direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center', 'gap' => 'm']]),
                ]), 'header'), ['base' => ['padding_y' => 's', 'background' => 'background', 'border_bottom' => '1px solid var(--tl-color-line)', 'position' => 'sticky', 'top' => '0', 'z_index' => '10']])],
                'footer' => [$s($z($n('section', [], [
                    $s($n('grid', [], [
                        $n('container', [], [$s($z($n('company_details', ['detail' => 'name']), 'p'), ['base' => ['font_weight' => '700']]), $n('company_details', ['detail' => 'description']), $n('company_details', ['detail' => 'email'])]),
                        $n('container', [], [$n('navigation', ['menu' => 'footer', 'news_link' => false, 'phone_menu' => false]), $n('company_details', ['detail' => 'social'])]), // RSS only in <link rel="alternate">, a company footer does not need it
                    ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => 'l'], 'mobile' => ['columns' => '1']]),
                    $s($n('company_details', ['detail' => 'copyright']), ['base' => ['margin_top' => 'l', 'font_size' => '-1', 'color' => 'muted']]),
                ]), 'footer'), ['base' => ['padding_y' => 'xl', 'background' => 'surface', 'border_top' => '1px solid var(--tl-color-line)']])],
                'news_item' => [$n('page_content', [], []), Library::section('call-to-action', \Talea\Core\Language::code())['element']],
                default => [$n('page_content', [], [])],
            };

            return Build::sanitize(['v' => Build::VERSION, 'children' => $children])[0];
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
