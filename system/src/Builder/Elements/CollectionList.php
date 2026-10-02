<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Language;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Collection list: the inside of the element is the pattern of one item and repeats for each collection item (references, team, products…).
 * In texts, images and links inside, {{field}} is replaced by the item's value: {{nazev}}, {{url}}, {{datum}} and custom fields.
 */
final class CollectionList extends Element
{
    public const string TYPE = 'kolekce';
    public const string NAME = 'Collection list';
    public const string DESCRIPTION = 'Cards from a collection (testimonials, team, products…) – the inside is the template for one item, {{fields}} fill in automatically.';
    public const string ICON = 'kolekce';
    public const string GROUP = 'Dynamic';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'ul'];

    public static function properties(): array
    {
        return [
            'kolekce' => ['typ' => 'text', 'popisek' => 'Collections', 'vychozi' => '', 'max' => 110],
            'pocet' => ['typ' => 'cislo', 'popisek' => 'Maximum items', 'vychozi' => 12, 'min' => 1, 'max' => 100],
            'razeni' => ['typ' => 'vyber', 'popisek' => 'Řazení', 'vychozi' => 'poradi', 'moznosti' => ['poradi' => 'by order in the administration', 'nazev' => 'by name', 'nejnovejsi' => 'newest first',
                'pole' => 'by field – ascending', 'pole_sestupne' => 'by field – descending']],
            'razeni_pole' => ['typ' => 'text', 'popisek' => 'Sort field (key, e.g. price)', 'vychozi' => '', 'max' => 31],
            'filtr_pole' => ['typ' => 'text', 'popisek' => 'Filter by field (key, optional)', 'vychozi' => '', 'max' => 31],
            'filtr_hodnota' => ['typ' => 'text', 'popisek' => 'Only items with the value (on an item page also {{field}} – related content)', 'vychozi' => '', 'max' => 200],
            'bez_aktualni' => ['typ' => 'prepinac', 'popisek' => 'Leave out the item being shown (related content on an item page)', 'vychozi' => false],
            'obdobi' => ['typ' => 'vyber', 'popisek' => 'By date', 'vychozi' => '', 'moznosti' => Collections::PERIODS],
            'obdobi_od' => ['typ' => 'text', 'popisek' => 'Start date field (key, e.g. start)', 'vychozi' => '', 'max' => 31],
            'obdobi_do' => ['typ' => 'text', 'popisek' => 'End date field (key, optional)', 'vychozi' => '', 'max' => 31],
            'filtry' => ['typ' => 'prepinac', 'popisek' => 'Filter buttons for visitors (by the field above)', 'vychozi' => false],
            'strankovani' => ['typ' => 'prepinac', 'popisek' => 'Paginate (by “Maximum items”)', 'vychozi' => false],
            'prazdne' => ['typ' => 'text', 'popisek' => 'Text when the collection has no items', 'vychozi' => '', 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-kolekce-filtry, .ka-kolekce-strany { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); margin: 0 0 var(--ka-mezera-m); padding: 0; list-style: none; }
.ka-kolekce-strany { justify-content: center; margin: var(--ka-mezera-l) 0 0; }
.ka-kolekce-filtry a, .ka-kolekce-strany a { display: block; padding: 0.4em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni-plne); color: inherit; text-decoration: none; }
.ka-kolekce-filtry a:hover, .ka-kolekce-strany a:hover { border-color: var(--ka-barva-primarni); }
.ka-kolekce-filtry a[aria-current], .ka-kolekce-strany a[aria-current] { background: var(--ka-barva-primarni); border-color: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); }';
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:18rem', 'mezera' => 'l']];
    }

    public static function defaultChildren(): array
    {
        // the class karta from the section library (the editor creates it on insert if the site does not have it yet)
        return [['tridy' => ['karta']] + \Kaleta\Builder\Build::fresh('kontejner', [], [
            ['znacka' => 'h3'] + \Kaleta\Builder\Build::fresh('nadpis', ['text' => '{{nazev}}']),
            \Kaleta\Builder\Build::fresh('tlacitko', ['text' => t('More information'), 'odkaz' => '{{url}}', 'varianta' => 'odkaz']),
        ])];
    }

    /** The inside for each item (called by Build when rendering). */
    public static function repeat(array $p, Context $k, callable $inner): string
    {
        $o = $p['obsah'];
        $collection = $o['kolekce'] === '' ? null : Collections::bySlug($k->app->db(), (string) $o['kolekce']);
        if ($collection === null) {
            return $k->editor ? '<p>' . e(t('Choose a collection in the Content panel.')) . '</p>' : '';
        }
        $r = $k->app->request;
        $db = $k->app->db();
        // the visitor's filter and page are in the url under a key by the element id (there can be several lists on a page)
        $filterParam = 'f-' . $p['id'];
        $pageParam = 's-' . $p['id'];
        $filterField = preg_match(Collections::KEY_PATTERN, (string) $o['filtr_pole']) ? (string) $o['filtr_pole'] : '';
        $filterValues = $filterField !== '' && $o['filtry'] ? Collections::fieldValues($db, (int) $collection['idk'], Language::siteColumn(), $filterField) : [];
        // a field linking to another collection (2.10) stores addresses – the buttons show the names of the linked items
        $linkField = array_values(array_filter($collection['pole'], fn (array $f): bool => $f['klic'] === $filterField && $f['typ'] === 'polozka'))[0] ?? null;
        $labels = $linkField !== null ? array_map(fn (array $l): string => $l[0], Collections::linked($db, (string) ($linkField['kolekce'] ?? ''))) : [];
        $selected = in_array($r->get($filterParam), $filterValues, true) ? $r->get($filterParam) : '';
        // related content: the filter value from the displayed item ({{skupina}} on the item page); elsewhere nothing is filtered
        $custom = $k->item;
        $filterValue = (string) $o['filtr_hodnota'];
        if (str_contains($filterValue, '{{')) {
            $filterValue = $custom !== null ? Collections::fill($filterValue, 'text', $custom) : '';
        }
        $filter = $filterField === '' ? null : [$filterField, $selected !== '' ? $selected : $filterValue];
        $pageNumber = $o['strankovani'] ? max(1, $r->getInt($pageParam, 1)) : 1;
        $withoutCurrent = !empty($o['bez_aktualni']) && ($custom['url'][0] ?? '') !== '';
        // by date (2.11): what is upcoming, current or past changes with time, not with an edit – such a page is not cached
        $period = ($o['obdobi'] ?? '') !== '' ? [(string) $o['obdobi'], (string) ($o['obdobi_od'] ?? ''), (string) ($o['obdobi_do'] ?? '')] : null;
        if ($period !== null) {
            $k->withoutCache = true;
        }
        [$items, $total] = Collections::items($db, (int) $collection['idk'], Language::siteColumn(), (int) $o['pocet'] + ($withoutCurrent ? 1 : 0), (string) $o['razeni'], $filter, $pageNumber, (string) $o['razeni_pole'], $period);
        $k->surroundings[$p['id']] = ['pred' => self::filters($filterValues, $selected, $filterParam, $k, $labels), 'za' => $o['strankovani'] ? self::pagination($total, (int) $o['pocet'], $pageNumber, $pageParam, $selected !== '' ? [$filterParam => $selected] : [], $k) : ''];
        // a document library (2.11) adds {{latest}} – the stable address of the current file – to every card
        $values = array_map(fn (array $item): array => Collections::values($collection, $item, $k->url(...), $db) + \Kaleta\Core\Documents::values($k->app, $collection, $item, false), $items);
        if ($withoutCurrent) {
            $values = array_slice(array_values(array_filter($values, fn (array $h): bool => $h['url'][0] !== $custom['url'][0])), 0, (int) $o['pocet']);
        }
        if ($values === []) {
            if (!$k->editor) {
                return $o['prazdne'] !== '' ? '<p>' . e($o['prazdne']) . '</p>' : '';
            }
            $values = [Collections::sample($collection)]; // in the editor a sample with the field labels, so that there is something to design
        }
        [$previousItem, $depth] = [$k->item, $k->inLoop];
        $k->inLoop++;
        $html = '';
        foreach ($values as $h) {
            $k->item = $h;
            $html .= $inner();
        }
        [$k->item, $k->inLoop] = [$previousItem, $depth];

        return $html;
    }

    /** Filter buttons (links – they work without JavaScript and can be shared). */
    /** @param array<string, string> $labels value => what the button says (names of linked items) */
    private static function filters(array $values, string $selected, string $parameter, Context $k, array $labels = []): string
    {
        if ($values === []) {
            return '';
        }
        $link = fn (string $value, string $text): string => '<li><a href="' . e($k->path . ($value !== '' ? '?' . http_build_query([$parameter => $value]) : '')) . '"'
            . ($value === $selected ? ' aria-current="true"' : '') . '>' . e($text) . '</a></li>';

        return '<ul class="ka-kolekce-filtry" aria-label="' . e(t('Filtr')) . '">' . $link('', t('Vše')) . implode('', array_map(fn (string $h): string => $link($h, $labels[$h] ?? $h), $values)) . '</ul>';
    }

    /** @param array<string, string> $keep other url parameters (the selected filter) */
    private static function pagination(int $total, int $perPage, int $pageNumber, string $parameter, array $keep, Context $k): string
    {
        $pageCount = (int) ceil($total / max(1, $perPage));
        if ($pageCount < 2) {
            return '';
        }
        $html = '';
        for ($i = 1; $i <= $pageCount; $i++) {
            $query = http_build_query($keep + ($i > 1 ? [$parameter => $i] : []));
            $html .= '<li><a href="' . e($k->path . ($query !== '' ? '?' . $query : '')) . '"' . ($i === $pageNumber ? ' aria-current="page"' : '') . '>' . $i . '</a></li>';
        }

        return '<ul class="ka-kolekce-strany" aria-label="' . e(t('List pages')) . '">' . $html . '</ul>';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $surroundings = $k->surroundings[$p['id']] ?? ['pred' => '', 'za' => ''];
        unset($k->surroundings[$p['id']]);
        $listing = $children === '' ? '' : '<' . $p['znacka'] . $a . '>' . $children . '</' . $p['znacka'] . '>';

        // filters and pagination are around the grid (not in it, otherwise they would look like another card)
        return $surroundings['pred'] === '' && $surroundings['za'] === '' ? $listing
            : '<div class="ka-kolekce" data-kolekce="' . e((string) $p['id']) . '">' . $surroundings['pred'] . $listing . $surroundings['za'] . '</div>'; // web.js swaps it without a reload (2.10)
    }
}
