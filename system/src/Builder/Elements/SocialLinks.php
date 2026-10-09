<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Social networks as icons. The profile urls are entered once in „Nastavení → Firma“ (Business details); the element outputs them the same everywhere.
 * The icons are simplified custom drawings (currentColor stroke), no third-party scripts or tracking buttons.
 */
final class SocialLinks extends Element
{
    public const string TYPE = 'social_links';
    public const string NAME = 'Follow us';
    public const string DESCRIPTION = 'Icons linking to the company profiles (addresses from Settings).';
    public const string ICON = 'social_links';
    public const array HTML_TAGS = ['ul'];

    /** settings key => [name, inner SVG 24×24] */
    private const array SOCIAL_NETWORKS = [
        'social_facebook' => ['Facebook', '<path d="M14 8.5h2.5V5H14a4 4 0 0 0-4 4v2.5H7.5V15H10v6h3.5v-6H16l.5-3.5h-3V9a.5.5 0 0 1 .5-.5z"/>'],
        'social_instagram' => ['Instagram', '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="0.6"/>'],
        'social_linkedin' => ['LinkedIn', '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M8 10.5V16M8 7.8v.01M11.5 16v-5.5M11.5 13c0-1.6 1-2.6 2.3-2.6s2.2.9 2.2 2.6v3"/>'],
        'social_youtube' => ['YouTube', '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="m10 9.2 5 2.8-5 2.8z"/>'],
        'social_x' => ['X', '<path d="M4.5 4.5 19.5 19.5M19.5 4.5l-6.2 6.9M10.7 12.6 4.5 19.5"/>'],
    ];

    public static function properties(): array
    {
        return ['show_names' => ['type' => 'boolean', 'label' => 'Show network names too', 'default' => false]];
    }

    public static function baseCss(): string
    {
        return '.ka-socialni { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); margin: 0; padding: 0; list-style: none; }
.ka-socialni a { display: inline-flex; align-items: center; gap: 0.4em; min-width: 2.5rem; min-height: 2.5rem; justify-content: center; border-radius: var(--ka-zaobleni); color: inherit; text-decoration: none; }
.ka-socialni a:hover { background: var(--ka-barva-plocha); }
.ka-socialni svg { width: 1.35em; height: 1.35em; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $html = '';
        foreach (self::SOCIAL_NETWORKS as $key => [$name, $svg]) {
            $url = $siteSettings->get($key);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $svg . '</svg>';
            $html .= '<li><a href="' . e($url) . '" rel="me noopener" target="_blank"' . ($p['content']['show_names'] ? '' : ' aria-label="' . e($name) . '" title="' . e($name) . '"') . '>' . $icon
                . ($p['content']['show_names'] ? '<span>' . e($name) . '</span>' : '') . '</a></li>';
        }
        if ($html === '') {
            return $k->editor ? '<p' . $a . '>' . e(t('Add social networks under Settings.')) . '</p>' : '';
        }

        return '<ul' . Text::withClass($a, 'ka-socialni') . '>' . $html . '</ul>';
    }
}
