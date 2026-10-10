<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

final class Divider extends Element
{
    public const string TYPE = 'divider';
    public const string NAME = 'Divider';
    public const string DESCRIPTION = 'A thin horizontal line between parts of the content.';
    public const string ICON = 'divider';
    public const array HTML_TAGS = ['hr'];

    public static function baseCss(): string
    {
        return 'hr.tl-divider { border: 0; border-top: 1px solid var(--tl-color-line); margin-block: var(--tl-space-l); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<hr' . Text::withClass($a, 'tl-divider') . '>';
    }
}
