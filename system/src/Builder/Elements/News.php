<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Front\NewsRepository;
use Talea\Builder\Context;
use Talea\Builder\Element;

/** List of the latest news (dynamic – it changes by itself as news items are added). */
final class News extends Element
{
    public const string TYPE = 'news_list';
    public const string EXTENSION = 'news';
    public const string NAME = 'News';
    public const string DESCRIPTION = 'Latest news as cards – they update themselves.';
    public const string ICON = 'article';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'count' => ['type' => 'number', 'label' => 'Number of news items', 'default' => 3, 'min' => 1, 'max' => 12],
            'category' => ['type' => 'text', 'label' => 'Only from category (address, optional)', 'default' => '', 'max' => 120],
            'images' => ['type' => 'boolean', 'label' => 'Show images', 'default' => true],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['base' => ['display' => 'grid', 'columns' => 'auto:18rem', 'gap' => 'l']];
    }

    public static function baseCss(): string
    {
        return '.tl-news { display: flex; flex-direction: column; gap: var(--tl-space-xs); }
.tl-news img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: var(--tl-radius); margin-block-end: var(--tl-space-xs); }
.tl-news time { font-size: var(--tl-step--1); color: var(--tl-color-muted); }
.tl-news h3 { margin: 0; font-size: var(--tl-step-1); }
.tl-news h3 a { color: inherit; text-decoration: none; }
.tl-news h3 a:hover { color: var(--tl-color-primary); }
.tl-news p { margin: 0; color: var(--tl-color-muted); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $reader = new NewsRepository($k->app->db(), $k->app->settings(), $k->app->request->basePath());
        $idt = $o['category'] === '' ? null : $k->app->db()->value('SELECT category_id FROM {categories} WHERE slug = ? ORDER BY language = ? DESC LIMIT 1', [$o['category'], \Talea\Core\Language::siteColumn()]); // with slugs per language the version's own category first
        [$news] = $idt === null ? $reader->listPublished(1, (int) $o['count']) : $reader->inCategory((int) $idt, 1, (int) $o['count']);
        $html = '';
        foreach ($news as $n) {
            $url = $k->url('news/' . $n['slug']);
            $html .= '<article class="tl-news">'
                . ($o['images'] && $n['image'] !== '' ? '<img src="' . e($n['image']) . '" alt="" loading="lazy">' : '')
                . '<time datetime="' . e(date('c', strtotime($n['published_at']))) . '">' . e(format_date($n['published_at'])) . '</time>'
                . '<h3><a href="' . e($url) . '">' . e($n['title']) . '</a></h3>'
                . '<p>' . e(mb_strimwidth(trim(html_entity_decode(strip_tags($n['intro']), ENT_QUOTES | ENT_HTML5)), 0, 180, '…')) . '</p></article>';
        }
        if ($html === '' && $k->editor) {
            $html = '<p>' . e(t('There is no news yet.')) . '</p>';
        }

        return '<div' . $a . '>' . $html . '</div>';
    }
}
