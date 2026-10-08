<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Images;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Image from Media: srcset from the prepared variants, lazy loading (except for the page's main image), optionally a caption and a link.
 * Loading (priorita): '' automatic – the first image of the page's first section loads at once with high priority (the usual
 * hero, its LCP), others lazily; '1' at once; '0' lazily. Builds saved before 3.5 hold true (= '1') or false (= automatic).
 */
final class Image extends Element
{
    public const string TYPE = 'obrazek';
    public const string NAME = 'Image';
    public const string DESCRIPTION = 'A photo or illustration from Media, optionally with a caption and link.';
    public const string ICON = 'obrazek';
    public const array HTML_TAGS = ['img'];

    public static function properties(): array
    {
        return [
            'src' => ['typ' => 'obrazek', 'popisek' => 'Image', 'vychozi' => ''],
            'alt' => ['typ' => 'text', 'popisek' => 'Popis pro nevidomé (alt)', 'vychozi' => '', 'max' => 300],
            'popisek' => ['typ' => 'text', 'popisek' => 'Caption below the image', 'vychozi' => '', 'max' => 300],
            'odkaz' => ['typ' => 'odkaz', 'popisek' => 'Link', 'vychozi' => ''],
            'priorita' => ['typ' => 'vyber', 'popisek' => 'When to load the image', 'vychozi' => '', 'moznosti' => [
                '' => 'Automatically – at once if it is the first image of the page', '1' => 'At once – the main image of the page', '0' => 'When scrolled into view']],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['zaklad' => ['sirka' => '100%', 'zaobleni' => 'm']];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        if ($o['src'] === '') {
            return $k->editor ? '<div' . $a . ' style="display:grid;place-items:center;min-height:10rem;background:var(--ka-barva-plocha);color:var(--ka-barva-tlumeny)">' . e(t('Choose an image')) . '</div>' : '';
        }
        $src = $k->image($o['src']);
        $srcset = Images::srcset(ltrim(preg_replace('#^' . preg_quote($k->app->request->basePath(), '#') . '/#', '', $src) ?? $src, '/'), $k->app->request->basePath());
        $labelText = $o['popisek'] !== '';
        $priority = match ($o['priorita']) {
            true, '1' => true,
            false, '' => $k->leadImage !== '' && $k->leadImage === ($p['id'] ?? null) && $k->inLoop === 0,
            default => false,
        };
        $img = '<img' . ($labelText || $o['odkaz'] !== '' ? '' : $a) . ' src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="' . ($priority ? '' : 'auto, ') . '(max-width: 900px) 100vw, 900px"' : '') // a lazy image: the browser knows its laid-out width
            . ' alt="' . e($o['alt']) . '"' . ($priority ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
        if ($o['odkaz'] !== '') {
            $img = '<a' . ($labelText ? '' : $a) . ' href="' . e($o['odkaz']) . '">' . $img . '</a>';
        }

        return $labelText ? '<figure' . Text::withClass($a, 'ka-figura') . '>' . $img . '<figcaption>' . e($o['popisek']) . '</figcaption></figure>' : $img;
    }

    public static function baseCss(): string
    {
        return '.ka-figura { margin: 0; }
.ka-figura img { display: block; width: 100%; height: auto; border-radius: inherit; }
.ka-figura figcaption { margin-block-start: var(--ka-mezera-xs); font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }';
    }
}
