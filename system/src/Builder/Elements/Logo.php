<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/** Site logo from Appearance (without it, the site name) as a link to the home page. The height is changed by the "Height" style. */
final class Logo extends Element
{
    public const string TYPE = 'logo';
    public const string NAME = 'Logo';
    public const string DESCRIPTION = 'The site logo from Appearance, otherwise the site name – a link to the home page.';
    public const string ICON = 'logo';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['a'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return ['show_name' => ['type' => 'boolean', 'label' => 'Site name next to the logo', 'default' => false]];
    }

    public static function baseCss(): string
    {
        return '.tl-logo { display: inline-flex; align-items: center; gap: var(--tl-space-xs); height: 2.75rem; color: inherit; font-family: var(--tl-font-heading); font-size: var(--tl-step-1); font-weight: 800; line-height: 1.1; text-decoration: none; }
.tl-logo img { display: block; width: auto; height: 100%; max-width: none; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $name = $siteSettings->get('site_name');
        $logo = $siteSettings->get('logo');
        $home = $k->url('');
        $content = $logo !== ''
            ? '<img src="' . e($k->image($logo)) . '" alt="' . e($p['content']['name'] ? '' : $name) . '">' . ($p['content']['name'] ? '<span>' . e($name) . '</span>' : '')
            : e($name);

        return '<a' . Text::withClass($a, 'tl-logo') . ' href="' . e($home) . '"' . ($k->path === $home ? ' aria-current="page"' : '') . '>' . $content . '</a>';
    }
}
