<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Core\Images;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Image from Media: srcset from the prepared variants, lazy loading (except for the page's main image), optionally a caption and a link. */
final class Image extends Element
{
    public const string TYPE = 'image';
    public const string NAME = 'Image';
    public const string DESCRIPTION = 'A photo or illustration from Media, optionally with a caption and link.';
    public const string ICON = 'image';
    public const array HTML_TAGS = ['img'];

    public static function properties(): array
    {
        return [
            'src' => ['type' => 'image', 'label' => 'Image', 'default' => ''],
            'alt' => ['type' => 'text', 'label' => 'Description for blind users (alt)', 'default' => '', 'max' => 300],
            'caption' => ['type' => 'text', 'label' => 'Caption below the image', 'default' => '', 'max' => 300],
            'link' => ['type' => 'link', 'label' => 'Link', 'default' => ''],
            'priority' => ['type' => 'boolean', 'label' => 'Main image of the page (load immediately)', 'default' => false],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['base' => ['width' => '100%', 'radius' => 'm']];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        if ($o['src'] === '') {
            return $k->editor ? '<div' . $a . ' style="display:grid;place-items:center;min-height:10rem;background:var(--ka-barva-plocha);color:var(--ka-barva-tlumeny)">' . e(t('Choose an image')) . '</div>' : '';
        }
        $src = $k->image($o['src']);
        $srcset = Images::srcset(ltrim(preg_replace('#^' . preg_quote($k->app->request->basePath(), '#') . '/#', '', $src) ?? $src, '/'), $k->app->request->basePath());
        $labelText = $o['caption'] !== '';
        $img = '<img' . ($labelText || $o['link'] !== '' ? '' : $a) . ' src="' . e($src) . '"' . ($srcset !== '' ? ' srcset="' . e($srcset) . '" sizes="' . ($o['priority'] ? '' : 'auto, ') . '(max-width: 900px) 100vw, 900px"' : '') // a lazy image: the browser knows its laid-out width
            . ' alt="' . e($o['alt']) . '"' . ($o['priority'] ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
        if ($o['link'] !== '') {
            $img = '<a' . ($labelText ? '' : $a) . ' href="' . e($o['link']) . '">' . $img . '</a>';
        }

        return $labelText ? '<figure' . Text::withClass($a, 'ka-figura') . '>' . $img . '<figcaption>' . e($o['caption']) . '</figcaption></figure>' : $img;
    }

    public static function baseCss(): string
    {
        return '.ka-figura { margin: 0; }
.ka-figura img { display: block; width: 100%; height: auto; border-radius: inherit; }
.ka-figura figcaption { margin-block-start: var(--ka-mezera-xs); font-size: var(--ka-krok--1); color: var(--ka-barva-tlumeny); }';
    }
}
