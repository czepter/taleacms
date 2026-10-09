<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Build;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Custom HTML (a map, a booking system, embed code of a service). Only an administrator inserts and changes it; scripts are not let through. */
final class Html extends Element
{
    public const string TYPE = 'html';
    public const string NAME = 'Custom HTML';
    public const string DESCRIPTION = 'Embedded code of another service (map, booking). Administrators only.';
    public const string ICON = 'kod';
    public const string GROUP = 'Pokročilé';
    public const array HTML_TAGS = ['div'];
    public const bool ADMIN_ONLY = true;

    public static function properties(): array
    {
        return ['kod' => ['type' => 'kod', 'popisek' => 'HTML', 'vychozi' => '', 'max' => 20000]];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        // filtered again when rendering: builds saved before the filter changed, and values of {{placeholders}} filled in just now
        return '<div' . $a . '>' . Build::code((string) $p['obsah']['kod']) . '</div>';
    }
}
