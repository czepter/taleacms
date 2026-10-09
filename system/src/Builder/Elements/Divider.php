<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

final class Divider extends Element
{
    public const string TYPE = 'divider';
    public const string NAME = 'Oddělovač';
    public const string DESCRIPTION = 'A thin horizontal line between parts of the content.';
    public const string ICON = 'divider';
    public const array HTML_TAGS = ['hr'];

    public static function baseCss(): string
    {
        return 'hr.ka-oddelovac { border: 0; border-top: 1px solid var(--ka-barva-linka); margin-block: var(--ka-mezera-l); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        return '<hr' . Text::withClass($a, 'ka-oddelovac') . '>';
    }
}
