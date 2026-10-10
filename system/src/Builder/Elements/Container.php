<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/** A group of elements (flex): a card, a row of buttons, a column of text. With a link, the whole group becomes a link. */
final class Container extends Element
{
    public const string TYPE = 'container';
    public const string NAME = 'Container';
    public const string DESCRIPTION = 'A group of elements stacked or side by side – a card, a row of buttons.';
    public const string ICON = 'container';
    public const string GROUP = 'Layout';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'article', 'aside', 'nav', 'header', 'footer', 'ul', 'li'];

    public static function properties(): array
    {
        return ['link' => ['type' => 'link', 'label' => 'Whole container as a link (optional)', 'default' => '']];
    }

    public static function defaultStyle(): array
    {
        return ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']];
    }

    public static function baseCss(): string
    {
        // card as a link: the text keeps the card's colors, not the link color; hovering lifts it slightly
        return '.tl-card-link { display: block; color: inherit; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
.tl-card-link:hover { transform: translateY(-2px); box-shadow: var(--tl-shadow-m); }
.tl-card-link:focus-visible { outline: 2px solid var(--tl-color-primary); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .tl-card-link { transition: none; } .tl-card-link:hover { transform: none; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        if (str_contains($children, CompanyDetails::EMPTY_HOURS)) {
            // an "Opening hours" card without hours filled in: only a heading in an empty frame would remain on the site
            $children = str_replace(CompanyDetails::EMPTY_HOURS, '', $children);
            if (trim(strip_tags((string) preg_replace('#<(h[1-6])\b.*?</\1>#s', '', $children))) === '') {
                return '';
            }
        }
        $link = (string) ($p['content']['link'] ?? '');
        if ($link !== '') {
            // HTML does not allow a link inside a link (the browser would break the card apart): buttons and links inside stay only as a look
            $children = (string) preg_replace_callback('#<a\b([^>]*)>#', fn (array $m): string => '<span' . preg_replace('#\s(?:href|target|rel|download|hreflang|aria-current)="[^"]*"#', '', $m[1]) . '>', $children);
            $children = str_replace('</a>', '</span>', $children);

            return '<a' . Text::withClass($a, 'tl-card-link') . ' href="' . e($link) . '">' . $children . '</a>';
        }

        return '<' . $p['tag'] . $a . '>' . $children . '</' . $p['tag'] . '>';
    }
}
