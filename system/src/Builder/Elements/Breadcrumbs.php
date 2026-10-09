<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Breadcrumbs: Home › News › Category › News item. The site assembles the path from the displayed page (Context::$breadcrumbs),
 * so it is enough to insert it once into the news item wrapper or the collection item template. Search engines also get a BreadcrumbList.
 */
final class Breadcrumbs extends Element
{
    public const string TYPE = 'breadcrumbs';
    public const string NAME = 'Breadcrumbs';
    public const string DESCRIPTION = 'The path to the page (Home › News › …) – built automatically for the page shown.';
    public const string ICON = 'breadcrumbs';
    public const array HTML_TAGS = ['nav'];

    public static function baseCss(): string
    {
        return '.ka-breadcrumbs ol { display: flex; flex-wrap: wrap; gap: 0.35em; margin: 0; padding: 0; list-style: none; color: var(--ka-color-muted); font-size: var(--ka-step--1); }
.ka-breadcrumbs li + li::before { content: "›"; margin-inline-end: 0.35em; }
.ka-breadcrumbs a { color: inherit; }
.ka-breadcrumbs [aria-current] { color: var(--ka-color-text); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $path = $k->breadcrumbs !== [] ? $k->breadcrumbs : ($k->editor ? [[t('Home'), '#'], [t('This page'), '']] : []);
        if (count($path) < 2) {
            return ''; // breadcrumbs make no sense on the home page
        }
        $html = '';
        foreach ($path as $i => [$text, $url]) {
            // only the last link is current; a level without its own page (a collection without an overview page) is plain text
            $html .= match (true) {
                $i === array_key_last($path) => '<li><span aria-current="page">' . e($text) . '</span></li>',
                $url === '' => '<li><span>' . e($text) . '</span></li>',
                default => '<li><a href="' . e($url) . '">' . e($text) . '</a></li>',
            };
        }

        return '<nav' . Text::withClass($a, 'ka-breadcrumbs') . ' aria-label="' . e(t('Breadcrumbs')) . '"><ol>' . $html . '</ol></nav>';
    }
}
