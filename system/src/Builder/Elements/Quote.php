<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/** A customer testimonial or a quote: text, name and position / company. */
final class Quote extends Element
{
    public const string TYPE = 'testimonial';
    public const string NAME = 'Testimonials';
    public const string DESCRIPTION = 'A quote or customer testimonial with a name.';
    public const string ICON = 'testimonial';
    public const array HTML_TAGS = ['blockquote'];

    public static function properties(): array
    {
        return [
            'text' => ['type' => 'inline_text', 'label' => 'Text', 'default' => t('Working with them was quick and hassle-free. Recommended.'), 'max' => 1500],
            'author' => ['type' => 'text', 'label' => 'Name', 'default' => t('Jane Doe'), 'max' => 120],
            'position' => ['type' => 'text', 'label' => 'Position or company', 'default' => '', 'max' => 160],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-quote { margin: 0; }
.tl-quote p { margin: 0; font-size: var(--tl-step-1); line-height: 1.5; }
.tl-quote footer { margin-block-start: var(--tl-space-s); font-size: var(--tl-step--1); color: var(--tl-color-muted); }
.tl-quote footer strong { color: var(--tl-color-text); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $who = $o['author'] !== '' ? '<strong>' . e($o['author']) . '</strong>' . ($o['position'] !== '' ? ', ' . e($o['position']) : '') : e($o['position']);

        return '<blockquote' . Text::withClass($a, 'tl-quote') . '><p>' . $o['text'] . '</p>' . ($who !== '' ? '<footer>' . $who . '</footer>' : '') . '</blockquote>';
    }
}
