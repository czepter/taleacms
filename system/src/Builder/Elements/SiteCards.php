<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Core\Sites;

/**
 * "Projects" (multisite): cards for the owner's OTHER sites that are set up, live and switched on for the hub (Sites area → the site →
 * Card on the other sites): name, summary and image, linking to the primary host name. With one site (or the flag off) it prints nothing.
 */
final class SiteCards extends Element
{
    public const string TYPE = 'site_cards';
    public const string NAME = 'Projects';
    public const string DESCRIPTION = 'Cards of your other sites – name, summary and image, linking to each site (multisite).';
    public const string ICON = 'globe';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div', 'section', 'ul'];
    public const bool MULTISITE = true;

    public static function properties(): array
    {
        return [
            'count' => ['type' => 'number', 'label' => 'Number of sites', 'default' => 6, 'min' => 1, 'max' => 24],
            'link_text' => ['type' => 'text', 'label' => 'Link text', 'default' => t('Visit the site'), 'max' => 60],
        ];
    }

    public static function baseCss(): string
    {
        return ':where(.tl-site-cards) { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); gap: var(--tl-space-m, 1.5rem); margin: 0; padding: 0; list-style: none; }
:where(.tl-site-card) { display: flex; flex-direction: column; gap: var(--tl-space-xs, .5rem); overflow: hidden; border: 1px solid var(--tl-color-line, currentColor); border-radius: var(--tl-radius, .5rem); }
:where(.tl-site-card) img { display: block; width: 100%; aspect-ratio: 16 / 10; object-fit: cover; }
:where(.tl-site-card-body) { display: flex; flex: 1; flex-direction: column; gap: var(--tl-space-xs, .5rem); padding: var(--tl-space-s, 1rem); }
:where(.tl-site-card-body) :is(h3, p) { margin: 0; }
:where(.tl-site-card-body) a:last-child { margin-block-start: auto; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $cards = array_slice(Sites::hubCards($k->app->db(), Sites::currentId($k->app->db())), 0, max(1, (int) $o['count']));
        if ($cards === []) {
            return $k->editor ? '<div' . $a . ' style="padding:1rem;border:2px dashed currentColor;opacity:.6">' . e(t('Projects – the other sites that are live and switched on in the Sites area appear here')) . '</div>' : '';
        }
        $tag = $p['tag'] === 'ul' ? 'ul' : $p['tag'];
        $item = $tag === 'ul' ? 'li' : 'article';
        $html = '';
        foreach ($cards as $c) {
            $href = Sites::originOn($k->app->request, $c['host']) . '/';
            $html .= '<' . $item . ' class="tl-site-card">'
                . ($c['image'] !== '' ? '<img src="' . e($k->image($c['image'])) . '" alt="" loading="lazy">' : '')
                . '<div class="tl-site-card-body"><h3>' . e($c['name']) . '</h3>' . ($c['summary'] !== '' ? '<p>' . e($c['summary']) . '</p>' : '')
                . '<a href="' . e($href) . '">' . e($o['link_text']) . '</a></div></' . $item . '>';
        }

        return '<' . $tag . Text::withClass($a, 'tl-site-cards') . '>' . $html . '</' . $tag . '>';
    }
}
