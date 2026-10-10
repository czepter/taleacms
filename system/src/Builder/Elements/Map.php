<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Map with the company's location. The third-party map (Google) loads only after a click – until then the site sends nothing to a third party
 * and does not need cookie consent. Next to it there is always a plain link to the map.
 */
final class Map extends Element
{
    public const string TYPE = 'map';
    public const string NAME = 'Map';
    public const string DESCRIPTION = 'A map with the company address – loads only after a click (privacy, speed).';
    public const string ICON = 'map';
    public const array HTML_TAGS = ['figure', 'div'];

    public static function properties(): array
    {
        return [
            'address' => ['type' => 'text', 'label' => 'Address or coordinates (empty = company address from Settings)', 'default' => '', 'max' => 200],
            'zoom' => ['type' => 'choice', 'label' => 'Zoom', 'default' => '15', 'options' => ['11' => 'city', '13' => 'district', '15' => 'street', '17' => 'building']],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['base' => ['aspect_ratio' => '16/9', 'radius' => 'm', 'overflow' => 'hidden']];
    }

    public static function baseCss(): string
    {
        return '.tl-map { position: relative; margin: 0; min-height: 16rem; background: var(--tl-color-surface); }
.tl-map > button, .tl-map > iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
.tl-map > button { display: grid; place-content: center; gap: var(--tl-space-xs); padding: var(--tl-space-m); background: var(--tl-color-surface); color: var(--tl-color-text); font: inherit; text-align: center; cursor: pointer; }
.tl-map > button strong { font-size: var(--tl-step-1); }
.tl-map > button small { color: var(--tl-color-muted); }
.tl-map > button:hover strong { color: var(--tl-color-primary); }
.tl-map figcaption { position: absolute; inset: auto 0 0 auto; padding: 0.3em 0.7em; background: var(--tl-color-background); font-size: var(--tl-step--1); border-radius: var(--tl-radius-s) 0 0 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $url = $p['content']['address'] !== '' ? $p['content']['address']
            : ($siteSettings->get('company_gps') !== '' ? $siteSettings->get('company_gps') : trim(implode(', ', array_filter([$siteSettings->get('company_street'), $siteSettings->get('company_postcode') . ' ' . $siteSettings->get('company_city')])), ', '));
        if (trim($url) === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--tl-color-surface)">' . e(t('Fill in the address in the Content panel or in Business details.')) . '</div>' : '';
        }
        $q = rawurlencode($url);
        $embedUrl = 'https://maps.google.com/maps?q=' . $q . '&z=' . (int) $p['content']['zoom'] . '&output=embed';
        $link = $siteSettings->get('company_map') !== '' && $p['content']['address'] === '' ? $siteSettings->get('company_map') : 'https://www.google.com/maps/search/?api=1&query=' . $q;
        $button = '<button type="button" data-insert="' . e($embedUrl) . '" data-title="' . e(t('Map: %s', $url)) . '">'
            . '<strong>' . e(t('Show map')) . '</strong><span>' . e($url) . '</span><small>' . e(t('Loads from Google Maps after a click.')) . '</small></button>';
        $labelText = '<figcaption><a href="' . e($link) . '" target="_blank" rel="noopener">' . e(t('Open in maps')) . '</a></figcaption>';

        return $p['tag'] === 'figure'
            ? '<figure' . Text::withClass($a, 'tl-map') . '>' . $button . $labelText . '</figure>'
            : '<div' . Text::withClass($a, 'tl-map') . '>' . $button . '</div>';
    }
}
