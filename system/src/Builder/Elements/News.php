<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Front\NewsRepository;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** List of the latest news (dynamic – it changes by itself as news items are added). */
final class News extends Element
{
    public const string TYPE = 'novinky';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Novinky';
    public const string DESCRIPTION = 'Latest news as cards – they update themselves.';
    public const string ICON = 'clanek';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'pocet' => ['type' => 'cislo', 'popisek' => 'Number of news items', 'vychozi' => 3, 'min' => 1, 'max' => 12],
            'kategorie' => ['type' => 'text', 'popisek' => 'Only from category (address, optional)', 'vychozi' => '', 'max' => 120],
            'obrazky' => ['type' => 'prepinac', 'popisek' => 'Show images', 'vychozi' => true],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => 'auto:18rem', 'mezera' => 'l']];
    }

    public static function baseCss(): string
    {
        return '.ka-novinka { display: flex; flex-direction: column; gap: var(--ka-mezera-xs); }
.ka-novinka img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: var(--ka-zaobleni); margin-block-end: var(--ka-mezera-xs); }
.ka-novinka time { font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }
.ka-novinka h3 { margin: 0; font-size: var(--ka-krok-1); }
.ka-novinka h3 a { color: inherit; text-decoration: none; }
.ka-novinka h3 a:hover { color: var(--ka-barva-primarni); }
.ka-novinka p { margin: 0; color: var(--ka-barva-tlumeny); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $reader = new NewsRepository($k->app->db(), $k->app->settings(), $k->app->request->basePath());
        $idt = $o['kategorie'] === '' ? null : $k->app->db()->value('SELECT category_id FROM {categories} WHERE slug = ?', [$o['kategorie']]);
        [$news] = $idt === null ? $reader->listPublished(1, (int) $o['pocet']) : $reader->inCategory((int) $idt, 1, (int) $o['pocet']);
        $html = '';
        foreach ($news as $n) {
            $url = $k->url('novinky/' . $n['slug']);
            $html .= '<article class="ka-novinka">'
                . ($o['obrazky'] && $n['image'] !== '' ? '<img src="' . e($n['image']) . '" alt="" loading="lazy">' : '')
                . '<time datetime="' . e(date('c', strtotime($n['datum']))) . '">' . e(format_date($n['datum'])) . '</time>'
                . '<h3><a href="' . e($url) . '">' . e($n['title']) . '</a></h3>'
                . '<p>' . e(mb_strimwidth(trim(html_entity_decode(strip_tags($n['intro']), ENT_QUOTES | ENT_HTML5)), 0, 180, '…')) . '</p></article>';
        }
        if ($html === '' && $k->editor) {
            $html = '<p>' . e(t('There is no news yet.')) . '</p>';
        }

        return '<div' . $a . '>' . $html . '</div>';
    }
}
