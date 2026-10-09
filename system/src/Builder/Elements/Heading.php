<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

final class Heading extends Element
{
    public const string TYPE = 'heading';
    public const string NAME = 'Heading';
    public const string DESCRIPTION = 'An H1–H6 heading or a highlighted line.';
    public const string ICON = 'heading';
    public const array HTML_TAGS = ['h2', 'h1', 'h3', 'h4', 'h5', 'h6', 'p'];

    public static function properties(): array
    {
        return ['text' => ['type' => 'inline_text', 'popisek' => 'Text', 'vychozi' => t('Heading'), 'max' => 400]];
    }

    /** Highlighting part of a heading (<mark>): the accent color without a background – a dot after the title, a keyword. */
    public static function baseCss(): string
    {
        return ':where(.stavba) mark { background: none; color: var(--ka-barva-sekundarni); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<' . $p['tag'] . $a . '>' . $p['obsah']['text'] . '</' . $p['tag'] . '>';
    }
}
