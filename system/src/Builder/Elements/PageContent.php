<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * The place where the system inserts the page content in a wrapper: the news item text, the news list, the 404 message. The wrapper
 * adds sections around it (call to action, news, contact) – the content itself stays from the layout, so it looks the same as without the wrapper.
 */
final class PageContent extends Element
{
    public const string TYPE = 'obsah';
    public const string NAME = 'Page content';
    public const string DESCRIPTION = 'The system inserts the news item, news list or 404 message here. Use it exactly once in a wrapper.';
    public const string ICON = 'article';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['div', 'article'];
    public const bool PARTS_ONLY = true;

    public static function baseCss(): string
    {
        // in the wrapper, more sections follow right below the content – the layout's "at least full screen" height does not belong here
        return ':where(.stavba) > .obsah { min-height: 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $content = $k->content !== '' ? $k->content : ($k->editor ? '<p>' . e(t('The page content goes here (news item, news list, 404 message).')) . '</p>' : '');

        // the layout classes „obal obsah“: the content looks the same as without the wrapper
        return '<' . $p['tag'] . Text::withClass($a, 'obal obsah') . '>' . $content . '</' . $p['tag'] . '>';
    }
}
