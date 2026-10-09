<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Map with the company's location. The third-party map (Google) loads only after a click – until then the site sends nothing to a third party
 * and does not need cookie consent. Next to it there is always a plain link to the map.
 */
final class Map extends Element
{
    public const string TYPE = 'mapa';
    public const string NAME = 'Map';
    public const string DESCRIPTION = 'A map with the company address – loads only after a click (privacy, speed).';
    public const string ICON = 'mapa';
    public const array HTML_TAGS = ['figure', 'div'];

    public static function properties(): array
    {
        return [
            'adresa' => ['type' => 'text', 'popisek' => 'Address or coordinates (empty = company address from Settings)', 'vychozi' => '', 'max' => 200],
            'zoom' => ['type' => 'vyber', 'popisek' => 'Zoom', 'vychozi' => '15', 'options' => ['11' => 'město', '13' => 'čtvrť', '15' => 'ulice', '17' => 'dům']],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['aspect_ratio' => '16/9', 'radius' => 'm', 'overflow' => 'hidden']];
    }

    public static function baseCss(): string
    {
        return '.ka-mapa { position: relative; margin: 0; min-height: 16rem; background: var(--ka-barva-plocha); }
.ka-mapa > button, .ka-mapa > iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
.ka-mapa > button { display: grid; place-content: center; gap: var(--ka-mezera-xs); padding: var(--ka-mezera-m); background: var(--ka-barva-plocha); color: var(--ka-barva-text); font: inherit; text-align: center; cursor: pointer; }
.ka-mapa > button strong { font-size: var(--ka-krok-1); }
.ka-mapa > button small { color: var(--ka-barva-tlumeny); }
.ka-mapa > button:hover strong { color: var(--ka-barva-primarni); }
.ka-mapa figcaption { position: absolute; inset: auto 0 0 auto; padding: 0.3em 0.7em; background: var(--ka-barva-pozadi); font-size: var(--ka-krok--1); border-radius: var(--ka-zaobleni-s) 0 0 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $url = $p['obsah']['adresa'] !== '' ? $p['obsah']['adresa']
            : ($siteSettings->get('company_gps') !== '' ? $siteSettings->get('company_gps') : trim(implode(', ', array_filter([$siteSettings->get('company_street'), $siteSettings->get('company_postcode') . ' ' . $siteSettings->get('company_city')])), ', '));
        if (trim($url) === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('Fill in the address in the Content panel or in Business details.')) . '</div>' : '';
        }
        $q = rawurlencode($url);
        $embedUrl = 'https://maps.google.com/maps?q=' . $q . '&z=' . (int) $p['obsah']['zoom'] . '&output=embed';
        $link = $siteSettings->get('company_map') !== '' && $p['obsah']['adresa'] === '' ? $siteSettings->get('company_map') : 'https://www.google.com/maps/search/?api=1&query=' . $q;
        $button = '<button type="button" data-vlozit="' . e($embedUrl) . '" data-titulek="' . e(t('Map: %s', $url)) . '">'
            . '<strong>' . e(t('Show map')) . '</strong><span>' . e($url) . '</span><small>' . e(t('Loads from Google Maps after a click.')) . '</small></button>';
        $labelText = '<figcaption><a href="' . e($link) . '" target="_blank" rel="noopener">' . e(t('Open in maps')) . '</a></figcaption>';

        return $p['tag'] === 'figure'
            ? '<figure' . Text::withClass($a, 'ka-mapa') . '>' . $button . $labelText . '</figure>'
            : '<div' . Text::withClass($a, 'ka-mapa') . '>' . $button . '</div>';
    }
}
