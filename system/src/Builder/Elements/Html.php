<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Build;
use Talea\Builder\Context;
use Talea\Builder\Element;

/** Custom HTML (a map, a booking system, embed code of a service). Only an administrator inserts and changes it; scripts are not let through. */
final class Html extends Element
{
    public const string TYPE = 'custom_html';
    public const string NAME = 'Custom HTML';
    public const string DESCRIPTION = 'Embedded code of another service (map, booking). Administrators only.';
    public const string ICON = 'code';
    public const string GROUP = 'Advanced';
    public const array HTML_TAGS = ['div'];
    public const bool ADMIN_ONLY = true;

    public static function properties(): array
    {
        return ['code' => ['type' => 'code', 'label' => 'HTML', 'default' => '', 'max' => 20000]];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        // filtered again when rendering: builds saved before the filter changed, and values of {{placeholders}} filled in just now
        return '<div' . $a . '>' . Build::code((string) $p['content']['code']) . '</div>';
    }
}
