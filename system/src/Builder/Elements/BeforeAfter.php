<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Before and after: two photos of the same thing with a draggable divider. The control is a plain <input type="range"> laid over the
 * photos (keyboard, touch and screen readers for free); the after image is clipped at the divider (image/web.js moves it).
 * Without the script the two photos stand side by side with their labels – nothing is lost.
 */
final class BeforeAfter extends Element
{
    public const string TYPE = 'before_after';
    public const string NAME = 'Before and after';
    public const string DESCRIPTION = 'Two photos with a draggable divider – a renovation, a cleaning, a makeover.';
    public const string ICON = 'before-after';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'before_image' => ['type' => 'image', 'label' => 'Image before', 'default' => ''],
            'before_alt' => ['type' => 'text', 'label' => 'Description of the before image (alt)', 'default' => '', 'max' => 300],
            'before_label' => ['type' => 'text', 'label' => 'Label of the before image', 'default' => t('Before'), 'max' => 40],
            'after_image' => ['type' => 'image', 'label' => 'Image after', 'default' => ''],
            'after_alt' => ['type' => 'text', 'label' => 'Description of the after image (alt)', 'default' => '', 'max' => 300],
            'after_label' => ['type' => 'text', 'label' => 'Label of the after image', 'default' => t('After'), 'max' => 40],
            'divider_position' => ['type' => 'number', 'label' => 'Divider position (%)', 'default' => 50, 'min' => 0, 'max' => 100],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-before-after { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(14rem, 100%), 1fr)); gap: var(--ka-space-s); }
.ka-before-after figure { position: relative; margin: 0; }
.ka-before-after img { display: block; width: 100%; height: auto; border-radius: var(--ka-radius); }
.ka-before-after figcaption { position: absolute; inset-block-start: var(--ka-space-xs); padding: 0.2em 0.75em; border-radius: 999px; background: color-mix(in oklch, var(--ka-color-black) 60%, transparent); color: var(--ka-color-white); font-size: var(--ka-step--1); font-weight: 600; }
.ka-before-after-before figcaption { inset-inline-start: var(--ka-space-xs); }
.ka-before-after-after figcaption { inset-inline-end: var(--ka-space-xs); }
.ka-before-after:not([data-enabled]) .ka-before-after-handle { display: none; }
.ka-before-after[data-enabled] { position: relative; grid-template-columns: 1fr; gap: 0; overflow: hidden; border-radius: var(--ka-radius); }
.ka-before-after[data-enabled] figure { grid-area: 1 / 1; }
.ka-before-after[data-enabled] .ka-before-after-after { clip-path: inset(0 0 0 var(--ka-split)); }
.ka-before-after[data-enabled] .ka-before-after-handle { grid-area: 1 / 1; z-index: 2; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: ew-resize; appearance: none; background: transparent; }
.ka-before-after[data-enabled]::before { content: ""; position: absolute; z-index: 1; inset-block: 0; inset-inline-start: var(--ka-split); width: 2px; translate: -50% 0; background: var(--ka-color-white); box-shadow: 0 0 0 1px color-mix(in oklch, var(--ka-color-black) 25%, transparent); pointer-events: none; }
.ka-before-after[data-enabled]::after { content: "↔"; position: absolute; z-index: 1; inset-block-start: 50%; inset-inline-start: var(--ka-split); display: grid; place-items: center; width: 2.75rem; height: 2.75rem; translate: -50% -50%; border-radius: 50%; background: var(--ka-color-white); color: var(--ka-color-black); font-size: 1.25rem; box-shadow: var(--ka-shadow-m); pointer-events: none; }
.ka-before-after[data-enabled]:has(.ka-before-after-handle:focus-visible)::after { outline: 3px solid var(--ka-color-secondary); outline-offset: 2px; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        if ($o['before_image'] === '' || $o['after_image'] === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-color-surface)">' . e(t('Choose the before and after images in the Content panel.')) . '</div>' : '';
        }
        $figure = fn (string $className, string $src, string $alt, string $label): string => '<figure class="' . $className . '"><img src="' . e($k->image($src)) . '" alt="' . e($alt) . '" loading="lazy">'
            . ($label !== '' ? '<figcaption>' . e($label) . '</figcaption>' : '') . '</figure>';
        $position = (int) $o['divider_position'];

        return '<div' . Text::withClass($a, 'ka-before-after') . ' data-before-after' . ($k->editor ? ' data-enabled' : '') . ' style="--ka-split:' . $position . '%">'
            . $figure('ka-before-after-before', $o['before_image'], $o['before_alt'], $o['before_label'])
            . $figure('ka-before-after-after', $o['after_image'], $o['after_alt'], $o['after_label'])
            . '<input type="range" class="ka-before-after-handle" min="0" max="100" value="' . $position . '" aria-label="' . e(t('Compare before and after')) . '">'
            . '</div>';
    }
}
