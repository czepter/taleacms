<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;

/**
 * The pages of collections on the public site: an item page by the collection's template, the product comparison, the calendar of an
 * events collection, a document's latest file.
 */
final class CollectionPages
{
    public function __construct(private readonly App $app, private readonly PageFrame $frame, private readonly Kernel $kernel)
    {
    }

    /**
     * Collection item page (/<collection>/<item>) by the item template from the builder. In the editor the administrator
     * sees the template draft (?build=draft&editor=1), and when the collection has no items yet, a sample with field
     * labels (/<collection>/_sample).
     */
    public function showCollectionItem(string $collectionSlug, string $seo): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $collection = \Talea\Builder\Collections::bySlug($db, $collectionSlug);
        // item template in the shown site version's language; a language without its own template uses the default language's
        $template = $collection !== null ? \Talea\Builder\Collections::inLanguage($db, $collection, Language::siteColumn()) : null;
        // template draft: the administrator, or a signed preview of exactly this template (Core\Preview, target
        // collection:<idk>[:<language>])
        $draft = $template !== null && $this->kernel->wantsDraft() && ($this->app->auth()->isAdmin() || $this->kernel->canSeeDraft(\Talea\Builder\Collections::templateKey($template)));
        if ($collection === null || (!$collection['detail'] && !$draft)) {
            return $this->kernel->notFound();
        }
        $item = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND deleted_at IS NULL' . ($draft ? '' : ' AND visible = TRUE'), [$collection['collection_id'], $seo, Language::siteColumn()]);
        if ($item === null && !$draft && ($collection['hidden_redirect'] ?? '') !== '' && !$this->kernel->hasExactRedirect()
            && $db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND slug = ?', [$collection['collection_id'], $seo]) !== null) {
            // a hidden or deleted item (a person who left, 2.10): its old address leads to the chosen page instead of a 404
            $to = (string) $collection['hidden_redirect'];

            return Response::redirect(str_starts_with($to, 'https://') ? $to : $this->app->url(ltrim($to, '/')), 301);
        }
        if ($item === null && !($draft && $seo === '_sample')) {
            return $this->kernel->notFound();
        }
        if ($item !== null && ($gate = $this->kernel->gate('item', (int) $item['item_id'], (string) $item['name'])) !== null) {
            return $gate;
        }
        if ($item !== null) {
            $item['data'] = json_decode((string) $item['data'], true) ?: [];
        }
        $build = \Talea\Builder\Build::fromJson($draft ? ($template['build_draft'] ?? $template['build']) : $template['build'])
            ?? \Talea\Builder\Build::fromJson($draft ? ($collection['build_draft'] ?? $collection['build']) : $collection['build'])
            ?? \Talea\Builder\Collections::defaultTemplate($collection);
        // the collection level links to the page with the same slug (e.g. /navod above /navod/<article>) when it exists on
        // the site – with its title; in another language version to its translation (page slugs are unique across
        // languages: /de/vergleich)
        $parentPage = null;
        // with slugs per language the page of the version itself comes first
        $main = $db->one('SELECT page_id, translation_of, language, slug, title, visible FROM {pages} WHERE slug = ? AND deleted_at IS NULL ORDER BY language = ? DESC LIMIT 1', [$collection['slug'], Language::siteColumn()]);
        if ($main !== null && $main['language'] === Language::siteColumn()) {
            $parentPage = $main['visible'] ? $main : null;
        } elseif ($main !== null) {
            $original = (int) ($main['translation_of'] ?: $main['page_id']);
            $parentPage = $db->one('SELECT slug, title FROM {pages} WHERE (page_id = ? OR translation_of = ?) AND language = ? AND visible = TRUE AND deleted_at IS NULL LIMIT 1', [$original, $original, Language::siteColumn()]);
        }
        $this->frame->breadcrumbs([$parentPage !== null && $parentPage['title'] !== '' ? (string) $parentPage['title'] : $collection['name'], $parentPage !== null ? $this->app->url((string) $parentPage['slug']) : ''],
            [($item['breadcrumb_title'] ?? '') !== '' ? $item['breadcrumb_title'] : ($item['name'] ?? t('Sample item')), '']);
        $this->frame->collectionItem = $item !== null ? [(int) $collection['collection_id'], (string) $collection['slug'], (string) $item['slug']] : null;
        $k = $this->frame->context();
        if (\Talea\Core\Notices::isBoard($collection)) {
            $k->withoutCache = true; // {{notice_status}} changes with the day, not with an edit (2.11)
        }
        $k->item = $item !== null ? \Talea\Builder\Collections::values($collection, $item, $this->app->url(...), $this->app->db()) + \Talea\Core\Documents::values($this->app, $collection, $item) : \Talea\Builder\Collections::sample($collection);
        if (isset($k->item['_registration'])) {
            $k->withoutCache = true; // an event's page says whether it is full or over – that changes without an edit (2.11)
        }
        $k->editor = $draft && $r->get('editor') === '1';
        $k->source = 'collection:' . $this->app->db()->publicId('collections', (int) $collection['collection_id']);
        $this->frame->pageCollection = (string) $collection['slug'];
        $html = \Talea\Builder\Renderer::html($build, $k);
        [$k->item, $k->editor] = [null, false];

        // description and image for search engines and sharing: the item's first longer text and first image
        $description = '';
        $image = '';
        foreach ($collection['fields'] as $field) {
            $h = (string) ($item['data'][$field['key']] ?? '');
            if ($description === '' && in_array($field['type'], ['lines', 'html'], true) && $h !== '') {
                $description = \Talea\Core\Html::plain($h, 300);
            }
            if ($image === '' && $field['type'] === 'gallery' && $h !== '') {
                $h = \Talea\Builder\Collections::galleryPhotos($h)[0]['src'] ?? ''; // no image field before it: the gallery's first photo
            }
            if ($image === '' && in_array($field['type'], ['image', 'gallery'], true) && $h !== '') {
                $image = preg_match('#^https?://#', $h) ? $h : $this->app->request->origin() . $this->app->url(ltrim($h, '/'));
            }
        }

        // the item's own SEO fields (1.9) win over what is derived from its fields
        if ($item !== null && $item['description'] !== '') {
            $description = (string) $item['description'];
        }
        if ($item !== null && $item['image'] !== '') {
            $image = preg_match('#^https?://#', $item['image']) ? (string) $item['image'] : $this->app->request->origin() . $this->app->url(ltrim((string) $item['image'], '/'));
        }
        $title = $item !== null && $item['seo_title'] !== '' ? (string) $item['seo_title'] : (string) ($item['name'] ?? $collection['name']);

        return $this->frame->page($title, $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), [
            'description' => $description, 'image' => $image, 'build' => true, 'noindex' => $draft || !empty($item['noindex']),
            'item' => $item !== null ? ['collection' => $collection, 'item' => $item] : null,
            'markdown' => $item !== null && $collection['detail'] && $item['visible'] && !$this->frame->gated ? $collection['slug'] . '/' . $item['slug'] : null,
        ] + $this->frame->seoMeta('item', $item ?? [], ['category' => (string) $collection['name']]));
    }

    /**
     * Comparison of up to four products (2.11, Builder\Products): /<collection>/_compare?i=a,b,c – their pictures, names,
     * prices and every parameter side by side. Not indexed; an unknown collection or no known item is a 404.
     */
    public function compareProducts(string $collectionSlug): Response
    {
        $db = $this->app->db();
        $collection = \Talea\Builder\Collections::bySlug($db, $collectionSlug);
        $fields = $collection !== null ? \Talea\Builder\Products::fields($collection) : null;
        $slugs = array_slice(array_values(array_unique(array_filter(explode(',', $this->app->request->get('i')), fn (string $s): bool => preg_match('/^[a-z0-9-]{1,160}$/', $s) === 1))), 0, \Talea\Builder\Products::MAX_COMPARE);
        if ($collection === null || $fields === null || $slugs === []) {
            return $this->kernel->notFound();
        }
        $items = [];
        foreach ($slugs as $slug) {
            $item = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('item', 'item_id'), [$collection['collection_id'], $slug, Language::siteColumn()]);
            if ($item !== null) {
                $item['data'] = json_decode((string) $item['data'], true) ?: [];
                $items[] = $item;
            }
        }
        if ($items === []) {
            return $this->kernel->notFound();
        }
        $k = $this->frame->context();
        $k->types[\Talea\Builder\Elements\EnquiryButton::TYPE] = true; // its CSS has the comparison table
        $head = '';
        foreach ($items as $item) {
            $image = $fields['image'] !== '' ? (string) ($item['data'][$fields['image']] ?? '') : '';
            $price = $fields['price'] !== '' ? trim((string) ($item['data'][$fields['price']] ?? '') . ' ' . ($fields['price_note'] !== '' ? (string) ($item['data'][$fields['price_note']] ?? '') : '')) : '';
            $head .= '<th scope="col">' . ($image !== '' ? '<img src="' . e($k->image($image)) . '" alt="" loading="lazy">' : '')
                . ($collection['detail'] ? '<a href="' . e($this->app->url($collection['slug'] . '/' . $item['slug'])) . '">' . e($item['name']) . '</a>' : e($item['name']))
                . ($price !== '' ? '<br><small>' . e($price) . '</small>' : '') . '</th>';
        }
        $rows = '';
        foreach (\Talea\Builder\Products::comparison($fields['parameters'], $items) as [$name, $values]) {
            $rows .= '<tr><th scope="row">' . e($name) . '</th>' . implode('', array_map(fn (string $v): string => '<td>' . e($v) . '</td>', $values)) . '</tr>';
        }
        $title = t('Comparison') . ': ' . $collection['name'];
        $html = '<div class="tl-system-page"><h1>' . e($title) . '</h1><div class="tl-compare-wrap"><table class="tl-compare"><thead><tr><td></td>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p><a href="' . e($this->app->url($collection['slug'])) . '">' . e(t('Back to %s', (string) $collection['name'])) . '</a></p></div>';

        return $this->frame->page($title, $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    /**
     * iCalendar of an events collection (2.11, Core\Calendar): /<collection>.ics – upcoming events and those of the last
     * 30 days, to subscribe to; /<collection>/<item>.ics – one event to add. null = not an events collection.
     */
    public function calendarFile(string $collectionSlug, string $itemSlug = ''): ?Response
    {
        $db = $this->app->db();
        $collection = \Talea\Builder\Collections::bySlug($db, $collectionSlug);
        $fields = $collection !== null ? \Talea\Core\Calendar::fields($collection) : null;
        if ($collection === null || $fields === null) {
            return null;
        }
        $start = $db->dialect()->jsonExtract('data', '$.' . $fields['start']);
        $rows = $itemSlug !== ''
            ? $db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('item', 'item_id'), [$collection['collection_id'], $itemSlug, Language::siteColumn()])
            : $db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('item', 'item_id') . ' AND ' . $start . ' >= ? ORDER BY ' . $start . ' LIMIT 500',
                [$collection['collection_id'], Language::siteColumn(), date('Y-m-d', strtotime('-30 days'))]);
        if ($itemSlug !== '' && $rows === []) {
            return $this->kernel->notFound();
        }
        $events = array_map(function (array $r) use ($collection): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return [$r, $collection['detail'] ? \Talea\Core\Mailing::absolute($this->app, $collection['slug'] . '/' . $r['slug']) : ''];
        }, $rows);
        $name = $this->app->settings()->get('site_name') . ' – ' . $collection['name'];
        $ics = \Talea\Core\Calendar::ics($collection, $events, $name, (string) (parse_url(\Talea\Core\Mailing::absolute($this->app, ''), PHP_URL_HOST) ?: 'talea'));

        return new Response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'public, max-age=900']
            + ($itemSlug !== '' ? ['Content-Disposition' => 'attachment; filename="' . $itemSlug . '.ics"'] : []));
    }

    /** The stable address of a document's current file (2.11, Core\Documents): /<collection>/<item>/latest. */
    public function latest(string $collectionSlug, string $itemSlug): Response
    {
        return \Talea\Core\Documents::latest($this->app, $collectionSlug, $itemSlug) ?? $this->kernel->notFound();
    }
}
