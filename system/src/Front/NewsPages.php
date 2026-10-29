<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Core\Extensions;
use Talea\Core\View;

/** The news on the public site: the listing, categories, tags, a news item, search, the RSS feed and the Markdown versions. */
final class NewsPages
{
    public function __construct(private readonly App $app, private readonly PageFrame $frame, private readonly NewsRepository $news, private readonly Kernel $kernel)
    {
    }

    public function showNewsList(bool $home = false): Response
    {
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->listPublished($pageNumber);
        if (!$home) {
            $this->frame->breadcrumbs([t('News'), '']);
        }

        return $this->frame->page($home ? '' : t('News'), $this->frame->view->render('list', ['heading' => t('News'), 'description' => ''] + $this->listVariables($news, $total, $pageNumber, $home ? '' : 'news')), [
            'main' => $home,
            // without a site description: the list's own summary (the site name and the latest headlines)
            'description' => $this->app->settings()->get('site_description') !== '' ? $this->app->settings()->get('site_description')
                : mb_strimwidth(t('News') . ' – ' . $this->app->settings()->get('site_name') . ($news !== [] ? ': ' . implode(' · ', array_column(array_slice($news, 0, 3), 'title')) : ''), 0, 160, '…'),
            'part' => 'list',
        ] + $this->frame->seoMeta($home ? 'home' : 'other'));
    }

    public function category(string $seo): Response
    {
        $category = $this->app->db()->one('SELECT * FROM {categories} WHERE slug = ? AND language = ?', [$seo, Language::siteColumn()]);
        if ($category === null) {
            return $this->kernel->notFound();
        }
        $this->frame->counterpart = ['categories', 'category_id', $category, 'news/category/'];
        $this->frame->breadcrumbs([t('News'), $this->app->url('news')], [$category['name'], '']);
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->inCategory((int) $category['category_id'], $pageNumber);

        return $this->frame->page(
            $category['name'],
            $this->frame->view->render('list', ['heading' => $category['name'], 'description' => PageFrame::authored(\Talea\Core\Html::safe((string) $category['description']))] + $this->listVariables($news, $total, $pageNumber, 'news/category/' . $seo)),
            ['description' => \Talea\Core\Html::plain((string) $category['description'], 300), 'part' => 'list', 'noindex' => $total === 0 && $this->app->settings()->bool('seo_hide_empty_terms')]
                + $this->frame->seoMeta('category', [], ['category' => (string) $category['name']]),
        );
    }

    public function tag(string $seo): Response
    {
        $tag = $this->app->db()->one('SELECT * FROM {tags} WHERE slug = ?', [$seo]);
        if ($tag === null) {
            return $this->kernel->notFound();
        }
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->withTag((int) $tag['tag_id'], $pageNumber);
        // a tag with a description is a topic page: intro and its own description for search engines
        $colorScheme = trim((string) $tag['description']) !== '';

        return $this->frame->page($colorScheme ? $tag['name'] : t('Tag') . ' ' . $tag['name'], $this->frame->view->render('list', [
            'heading' => ($colorScheme ? '' : '#') . $tag['name'], 'description' => $colorScheme ? PageFrame::authored(\Talea\Core\Html::safe((string) $tag['description'])) : '',
        ] + $this->listVariables($news, $total, $pageNumber, 'news/tag/' . $seo)), [
            'description' => $colorScheme ? \Talea\Core\Html::plain((string) $tag['description'], 300) : '',
            'part' => 'list',
            'noindex' => $total === 0 && $this->app->settings()->bool('seo_hide_empty_terms'),
        ] + $this->frame->seoMeta('tag'));
    }

    public function newsItem(string $seo): Response
    {
        // the preview of unpublished news follows the admin policy: the News section, and authors only their own items (Auth::managedAuthors)
        $auth = $this->app->auth();
        $preview = $this->app->request->get('preview') === '1' && $auth->hasModule('news');
        $newsItem = $this->news->bySlug($seo, $preview);
        if ($newsItem !== null && $preview && ($authors = $auth->managedAuthors()) !== null && !in_array((int) $newsItem['author_id'], $authors, true)) {
            $preview = false;
            $newsItem = $this->news->bySlug($seo); // someone else's item: only if it is published
        }
        if ($newsItem === null) {
            return $this->kernel->notFound();
        }
        if ($newsItem['language'] !== Language::siteColumn()) {
            // the news item belongs to a different language version than the one the request came from
            if ($newsItem['language'] !== '' && !in_array($newsItem['language'], Language::additional($this->app->settings()), true)) {
                return $this->kernel->notFound(); // its language version is disabled: a redirect would lead back to the same URL
            }
            $this->app->languagePrefix = $newsItem['language'];

            return Response::redirect($this->app->url('news/' . $newsItem['slug']) . ($preview ? '?preview=1' : ''), 301);
        }
        if (($gate = $this->kernel->gate('news', (int) $newsItem['news_id'], (string) $newsItem['title'])) !== null) {
            return $gate;
        }
        // editing directly on the site works with the raw text from the database (without the outline and embedded players)
        $raw = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {news} WHERE news_id = ?', [$newsItem['news_id']]);
        if ($raw !== null && ($form = $this->frame->editInPlace('news', $raw, 'news/' . $newsItem['slug'])) !== null) {
            return $this->frame->page($newsItem['title'], $form, ['noindex' => true]);
        }
        if (!$preview && $this->app->auth()->user() === null && ($_SERVER['HTTP_USER_AGENT'] ?? '') !== '') { // the cache warm-up sends no user agent
            $this->app->db()->run('UPDATE {news} SET visit = visit + 1 WHERE news_id = ?', [$newsItem['news_id']]);
        }

        $this->frame->breadcrumbs([t('News'), $this->app->url('news')], [$newsItem['category_name'], $this->app->url('news/category/' . $newsItem['category_slug'])], [(string) ($newsItem['breadcrumb_title'] ?? '') !== '' ? $newsItem['breadcrumb_title'] : $newsItem['title'], '']);
        $newsItem['faq_html'] = (new View([TALEA_SYSTEM . '/views/front']))->render('faq', ['faq' => Seo::faq($newsItem['faq'])]);
        $newsItem = (new NewsText($this->app))->complete($newsItem);
        $newsItem['tags'] = $this->app->db()->all('SELECT s.name, s.slug FROM {tags} s JOIN {news_tags} cs ON cs.tag_id = s.tag_id WHERE cs.news_id = ? ORDER BY s.name', [$newsItem['news_id']]);

        $content = $this->frame->view->render('news-item', [
            // what the editor wrote; the item itself stays as it is for the description and structured data
            'newsItem' => array_map(PageFrame::authored(...), array_filter(array_intersect_key($newsItem, ['intro' => 1, 'text' => 1, 'faq_html' => 1, 'image_caption_html' => 1]), is_string(...))) + $newsItem,
            'url' => $this->app->url(...),
            'souvisejici' => $this->app->settings()->bool('related_news_auto') ? $this->news->similar($newsItem) : [],
        ]);

        return $this->frame->page($newsItem['seo_title'] !== '' ? $newsItem['seo_title'] : $newsItem['title'], $content, [
            'article' => $newsItem,
            'description' => $newsItem['seo_description'] !== '' ? $newsItem['seo_description'] : \Talea\Core\Html::plain($newsItem['intro'], 300),
            'keywords' => $newsItem['keywords'],
            'image' => $newsItem['image'],
            'noindex' => (bool) $newsItem['noindex'],
            'type' => 'article',
            'part' => 'news_item',
        ] + $this->frame->seoMeta('news', $newsItem, ['category' => (string) $newsItem['category_name']]));
    }

    public function search(): Response
    {
        $q = mb_substr($this->app->request->get('q'), 0, 100);
        // search is the site's most expensive query and is not cached: at most 30 searches per minute from one address
        $antispam = new \Talea\Core\Antispam($this->app->db(), $this->app->settings());
        if (mb_strlen($q) >= 3) {
            if ($antispam->count($this->app->request->ip(), 'search', 0, 1) >= 30) {
                return new Response(t('Too many searches in a row. Please try again in a moment.'), 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60']);
            }
            $antispam->write($this->app->request->ip(), 'search', 0);
        }
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = mb_strlen($q) >= 3 && Extensions::isEnabled($this->app->settings(), 'news') ? $this->news->search($q, $pageNumber) : [[], 0];
        // pages and collection items with their own page – regardless of diacritics, with a snippet (news are found by the
        // fulltext above)
        $pages = [];
        if (mb_strlen($q) >= 3 && $pageNumber === 1) {
            $db = $this->app->db();
            $home = $this->frame->homePageId();
            $candidates = array_map(fn (array $s): array => ['title' => $s['title'], 'url' => (int) $s['page_id'] === $home ? '' : $s['slug'], 'text' => (string) $s['text']],
                $db->all('SELECT page_id, title, slug, text FROM {pages} WHERE visible = TRUE AND noindex = FALSE AND password_hash IS NULL AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('page', 'page_id') . ' AND language = ? ORDER BY sort_order LIMIT 500', [Language::siteColumn()]));
            foreach ($db->all('SELECT p.name, p.slug, p.data, k.slug AS collection, k.fields FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.detail = TRUE AND p.visible = TRUE AND p.noindex = FALSE AND ' . \Talea\Core\Members::notGated('item', 'p.item_id') . ' AND p.language = ? ORDER BY p.sort_order LIMIT 2000', [Language::siteColumn()]) as $p) {
                $data = json_decode((string) $p['data'], true);
                // only text fields are searched – image paths and link URLs would add noise to the results and snippets
                $textFields = array_column(array_filter(json_decode((string) $p['fields'], true) ?: [], fn (array $f): bool => in_array($f['type'] ?? '', ['text', 'lines', 'html'], true)), 'key');
                $candidates[] = ['title' => $p['name'], 'url' => $p['collection'] . '/' . $p['slug'],
                    'text' => implode(' ', array_filter(array_intersect_key(is_array($data) ? $data : [], array_flip($textFields)), 'is_string'))];
            }
            $pages = array_map(fn (array $v): array => ['title' => $v['title'], 'slug' => $v['url'], 'snippet' => $v['snippet']], \Talea\Core\Search::find($q, $candidates));
        }

        return $this->frame->page(
            t('Search'),
            $this->frame->view->render('list', ['heading' => t('Search'), 'description' => '', 'searched' => $q, 'foundPages' => $pages] + $this->listVariables($news, $total, $pageNumber, 'search', ['q' => $q])),
            ['noindex' => true],
        );
    }

    public function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Language::siteColumn(), fn (): string => $this->frame->view->render('rss', [
            'web' => $this->app->settings(),
            'news' => $this->news->listPublished(1, 20)[0],
            'url' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    /** A news item as Markdown for language models (/news/<slug>.md, setting markdown_versions); gated news has none. */
    public function newsMarkdown(string $slug): Response
    {
        $newsItem = $this->news->bySlug($slug);

        return $newsItem === null || \Talea\Core\Members::isGated($this->app->db(), 'news', (int) $newsItem['news_id']) // gated news has no Markdown version
            ? $this->kernel->notFound()
            : new Response((new Seo($this->app))->newsItemMarkdown($newsItem), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
    }

    /** A page or a collection item as Markdown for language models (/<slug>.md, /<collection>/<item>.md). */
    public function markdown(string $slug): Response
    {
        $markdown = $this->markdownVersion($slug);

        return $markdown === null ? $this->kernel->notFound() : new Response($markdown, 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
    }

    /**
     * Markdown version of a published page or collection item (/<slug>.md, /<collection>/<item>.md); null for a hidden,
     * password-protected or gated one, which has none – like gated news.
     */
    private function markdownVersion(string $slug): ?string
    {
        $db = $this->app->db();
        $seo = new Seo($this->app);
        $page = $db->one('SELECT * FROM {pages} WHERE slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL', [$slug, Language::siteColumn()]);
        if ($page !== null) {
            return \Talea\Core\PageLock::isProtected($page) || \Talea\Core\Members::isGated($db, 'page', (int) $page['page_id'])
                ? null : $seo->pageMarkdown($page, (int) $page['page_id'] === $this->frame->homePageId());
        }
        if (!preg_match('#^([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $slug, $m) || $m[1] === 'news') {
            return null;
        }
        $collection = \Talea\Builder\Collections::bySlug($db, $m[1]);
        $item = $collection !== null && $collection['detail']
            ? $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL', [$collection['collection_id'], $m[2], Language::siteColumn()])
            : null;

        return $item === null || \Talea\Core\Members::isGated($db, 'item', (int) $item['item_id']) ? null : $seo->itemMarkdown($collection, $item);
    }

    /**
     * @param list<array<string, mixed>> $news
     * @param array<string, string> $params extra parameters of the pagination links
     * @return array<string, mixed>
     */
    private function listVariables(array $news, int $total, int $pageNumber, string $path, array $params = []): array
    {
        $this->frame->pastEnd = $pageNumber > 1 && $news === [];

        return [
            'news' => array_map(fn (array $n): array => ['intro' => PageFrame::authored((string) $n['intro'])] + $n, $news),
            'total' => $total,
            'page' => $pageNumber,
            'pagesCount' => max(1, (int) ceil($total / $this->news->perPage())),
            'pageUrl' => fn (int $s): string => $this->app->url($path) . (($query = http_build_query($params + ($s > 1 ? ['page' => $s] : []))) !== '' ? '?' . $query : ''),
            'searched' => null,
            'foundPages' => [],
            'url' => $this->app->url(...),
        ];
    }
}
