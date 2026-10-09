<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Grid: columns that wrap by themselves when they do not fit (the default is "as many as fit at 16rem"). */
final class Grid extends Element
{
    public const string TYPE = 'grid';
    public const string NAME = 'Grid';
    public const string DESCRIPTION = 'Columns that stack on smaller screens by themselves.';
    public const string ICON = 'grid';
    public const string GROUP = 'Rozložení';
    public const bool CONTAINER = true;
    public const array HTML_TAGS = ['div', 'ul'];

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['zobrazeni' => 'grid', 'columns' => 'auto:16rem', 'mezera' => 'l']];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<' . $p['tag'] . $a . '>' . $children . '</' . $p['tag'] . '>';
    }
}
