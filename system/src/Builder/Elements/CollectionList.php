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
    public const string TYPE = 'collection_list';
    public const string NAME = 'Collection list';
    public const string DESCRIPTION = 'Cards from a collection (testimonials, team, products…) – the inside is the template for one item, {{fields}} fill in automatically.';
    public const string ICON = 'collection';
    public const string GROUP = 'Dynamic';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'ul'];

    public static function properties(): array
    {
        return [
            'collection' => ['type' => 'text', 'label' => 'Collections', 'default' => '', 'max' => 110],
            'count' => ['type' => 'number', 'label' => 'Maximum items', 'default' => 12, 'min' => 1, 'max' => 100],
            'sort' => ['type' => 'choice', 'label' => 'Order', 'default' => 'order', 'options' => ['order' => 'by order in the administration', 'name' => 'by name', 'newest' => 'newest first',
                'field' => 'by field – ascending', 'field_descending' => 'by field – descending']],
            'sort_field' => ['type' => 'text', 'label' => 'Sort field (key, e.g. price)', 'default' => '', 'max' => 31],
            'filter_field' => ['type' => 'text', 'label' => 'Filter by field (key, optional)', 'default' => '', 'max' => 31],
            'filter_value' => ['type' => 'text', 'label' => 'Only items with the value (on an item page also {{field}} – related content)', 'default' => '', 'max' => 200],
            'exclude_current' => ['type' => 'boolean', 'label' => 'Leave out the item being shown (related content on an item page)', 'default' => false],
            'period' => ['type' => 'choice', 'label' => 'By date', 'default' => '', 'options' => Collections::PERIODS],
            'period_start_field' => ['type' => 'text', 'label' => 'Start date field (key, e.g. start)', 'default' => '', 'max' => 31],
            'period_end_field' => ['type' => 'text', 'label' => 'End date field (key, optional)', 'default' => '', 'max' => 31],
            'filters' => ['type' => 'boolean', 'label' => 'Filter buttons for visitors (by the field above)', 'default' => false],
            'pagination' => ['type' => 'boolean', 'label' => 'Paginate (by “Maximum items”)', 'default' => false],
            'empty_text' => ['type' => 'text', 'label' => 'Text when the collection has no items', 'default' => '', 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-collection-filters, .ka-collection-pages { display: flex; flex-wrap: wrap; gap: var(--ka-space-xs); margin: 0 0 var(--ka-space-m); padding: 0; list-style: none; }
.ka-collection-pages { justify-content: center; margin: var(--ka-space-l) 0 0; }
.ka-collection-filters a, .ka-collection-pages a { display: block; padding: 0.4em 0.9em; border: 1px solid var(--ka-color-line); border-radius: var(--ka-radius-full); color: inherit; text-decoration: none; }
.ka-collection-filters a:hover, .ka-collection-pages a:hover { border-color: var(--ka-color-primary); }
.ka-collection-filters a[aria-current], .ka-collection-pages a[aria-current] { background: var(--ka-color-primary); border-color: var(--ka-color-primary); color: var(--ka-color-on-primary); }';
    }

    public static function defaultStyle(): array
    {
        return ['base' => ['display' => 'grid', 'columns' => 'auto:18rem', 'gap' => 'l']];
    }

    public static function defaultChildren(): array
    {
        // the class card from the section library (the editor creates it on insert if the site does not have it yet)
        return [['classes' => ['card']] + \Kaleta\Builder\Build::fresh('container', [], [
            ['tag' => 'h3'] + \Kaleta\Builder\Build::fresh('heading', ['text' => '{{name}}']),
            \Kaleta\Builder\Build::fresh('button', ['text' => t('More information'), 'link' => '{{url}}', 'variant' => 'link']),
        ])];
    }

    /** The inside for each item (called by Build when rendering). */
    public static function repeat(array $p, Context $k, callable $inner): string
    {
        $o = $p['content'];
        $collection = $o['collection'] === '' ? null : Collections::bySlug($k->app->db(), (string) $o['collection']);
        if ($collection === null) {
            return $k->editor ? '<p>' . e(t('Choose a collection in the Content panel.')) . '</p>' : '';
        }
        $r = $k->app->request;
        $db = $k->app->db();
        // the visitor's filter and page are in the url under a key by the element id (there can be several lists on a page)
        $filterParam = 'f-' . $p['id'];
        $pageParam = 's-' . $p['id'];
        $filterField = preg_match(Collections::KEY_PATTERN, (string) $o['filter_field']) ? (string) $o['filter_field'] : '';
        $filterValues = $filterField !== '' && $o['filters'] ? Collections::fieldValues($db, (int) $collection['collection_id'], Language::siteColumn(), $filterField) : [];
        // a field linking to another collection (2.10) stores addresses – the buttons show the names of the linked items
        $linkField = array_values(array_filter($collection['fields'], fn (array $f): bool => $f['key'] === $filterField && $f['type'] === 'item'))[0] ?? null;
        $labels = $linkField !== null ? array_map(fn (array $l): string => $l[0], Collections::linked($db, (string) ($linkField['collection'] ?? ''))) : [];
        $selected = in_array($r->get($filterParam), $filterValues, true) ? $r->get($filterParam) : '';
        // related content: the filter value from the displayed item ({{skupina}} on the item page); elsewhere nothing is filtered
        $custom = $k->item;
        $filterValue = (string) $o['filter_value'];
        if (str_contains($filterValue, '{{')) {
            $filterValue = $custom !== null ? Collections::fill($filterValue, 'text', $custom) : '';
        }
        $filter = $filterField === '' ? null : [$filterField, $selected !== '' ? $selected : $filterValue];
        $pageNumber = $o['pagination'] ? max(1, $r->getInt($pageParam, 1)) : 1;
        $withoutCurrent = !empty($o['exclude_current']) && ($custom['url'][0] ?? '') !== '';
        // by date (2.11): what is upcoming, current or past changes with time, not with an edit – such a page is not cached
        $period = ($o['period'] ?? '') !== '' ? [(string) $o['period'], (string) ($o['period_start_field'] ?? ''), (string) ($o['period_end_field'] ?? '')] : null;
        if ($period !== null) {
            $k->withoutCache = true;
        }
        [$items, $total] = Collections::items($db, (int) $collection['collection_id'], Language::siteColumn(), (int) $o['count'] + ($withoutCurrent ? 1 : 0), (string) $o['sort'], $filter, $pageNumber, (string) $o['sort_field'], $period);
        $k->surroundings[$p['id']] = ['before' => self::filters($filterValues, $selected, $filterParam, $k, $labels), 'after' => $o['pagination'] ? self::pagination($total, (int) $o['count'], $pageNumber, $pageParam, $selected !== '' ? [$filterParam => $selected] : [], $k) : ''];
        // a document library (2.11) adds {{latest}} – the stable address of the current file – to every card
        $values = array_map(fn (array $item): array => Collections::values($collection, $item, $k->url(...), $db) + \Kaleta\Core\Documents::values($k->app, $collection, $item, false), $items);
        if ($withoutCurrent) {
            $values = array_slice(array_values(array_filter($values, fn (array $h): bool => $h['url'][0] !== $custom['url'][0])), 0, (int) $o['count']);
        }
        if ($values === []) {
            if (!$k->editor) {
                return $o['empty_text'] !== '' ? '<p>' . e($o['empty_text']) . '</p>' : '';
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

        return '<ul class="ka-collection-filters" aria-label="' . e(t('Filter')) . '">' . $link('', t('All')) . implode('', array_map(fn (string $h): string => $link($h, $labels[$h] ?? $h), $values)) . '</ul>';
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

        return '<ul class="ka-collection-pages" aria-label="' . e(t('List pages')) . '">' . $html . '</ul>';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $surroundings = $k->surroundings[$p['id']] ?? ['before' => '', 'after' => ''];
        unset($k->surroundings[$p['id']]);
        $listing = $children === '' ? '' : '<' . $p['tag'] . $a . '>' . $children . '</' . $p['tag'] . '>';

        // filters and pagination are around the grid (not in it, otherwise they would look like another card)
        return $surroundings['before'] === '' && $surroundings['after'] === '' ? $listing
            : '<div class="ka-collection" data-collection="' . e((string) $p['id']) . '">' . $surroundings['before'] . $listing . $surroundings['after'] . '</div>'; // web.js swaps it without a reload (2.10)
    }
}
