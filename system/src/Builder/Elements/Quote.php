<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

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
            'text' => ['type' => 'inline_text', 'popisek' => 'Text', 'vychozi' => t('Working with them was quick and hassle-free. Recommended.'), 'max' => 1500],
            'autor' => ['type' => 'text', 'popisek' => 'Jméno', 'vychozi' => t('Jane Doe'), 'max' => 120],
            'position' => ['type' => 'text', 'popisek' => 'Position or company', 'vychozi' => '', 'max' => 160],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-citat { margin: 0; }
.ka-citat p { margin: 0; font-size: var(--ka-krok-1); line-height: 1.5; }
.ka-citat footer { margin-block-start: var(--ka-mezera-s); font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }
.ka-citat footer strong { color: var(--ka-barva-text); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $who = $o['autor'] !== '' ? '<strong>' . e($o['autor']) . '</strong>' . ($o['position'] !== '' ? ', ' . e($o['position']) : '') : e($o['position']);

        return '<blockquote' . Text::withClass($a, 'ka-citat') . '><p>' . $o['text'] . '</p>' . ($who !== '' ? '<footer>' . $who . '</footer>' : '') . '</blockquote>';
    }
}
