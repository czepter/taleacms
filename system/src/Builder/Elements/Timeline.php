<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Timeline: milestones one after another – a date or year, a title, formatted text and an optional image. A vertical line with dots
 * on phones; on wide screens the items alternate left and right of the line – CSS only, the markup is one ordered list.
 */
final class Timeline extends Element
{
    public const string TYPE = 'timeline';
    public const string NAME = 'Timeline';
    public const string DESCRIPTION = 'Milestones one after another – the company history, project steps, how a service proceeds.';
    public const string ICON = 'timeline';
    public const array HTML_TAGS = ['ol'];

    public static function properties(): array
    {
        $milestone = fn (string $date, string $title): array => ['date' => $date, 'name' => $title, 'content' => '<p>' . t('What happened and what it changed.') . '</p>', 'src' => '', 'alt' => ''];

        return ['milestones' => ['type' => 'items', 'label' => 'Milestones', 'max' => 30, 'fields' => [
            'date' => ['type' => 'text', 'label' => 'Date or year', 'default' => '', 'max' => 40],
            'name' => ['type' => 'text', 'label' => 'Title', 'default' => '', 'max' => 120],
            'content' => ['type' => 'html', 'label' => 'Text', 'default' => ''],
            'src' => ['type' => 'image', 'label' => 'Image (optional)', 'default' => ''],
            'alt' => ['type' => 'text', 'label' => 'Description for blind users (alt)', 'default' => '', 'max' => 300],
        ], 'default' => [$milestone('2020', t('First milestone')), $milestone('2023', t('Second milestone')), $milestone(t('Today'), t('Third milestone'))]]];
    }

    public static function baseCss(): string
    {
        return '.tl-timeline { --tl-axis-x: 0.75rem; position: relative; display: grid; gap: var(--tl-space-l); margin: 0; padding: 0; list-style: none; }
.tl-timeline::before { content: ""; position: absolute; inset-block: 0; inset-inline-start: var(--tl-axis-x); width: 2px; translate: -50% 0; background: var(--tl-color-line); }
.tl-timeline-item { position: relative; padding-inline-start: calc(var(--tl-axis-x) + var(--tl-space-m)); }
.tl-timeline-item::before { content: ""; position: absolute; inset-block-start: 0.3rem; inset-inline-start: var(--tl-axis-x); width: 1rem; height: 1rem; translate: -50% 0; border: 3px solid var(--tl-color-background); border-radius: 50%; background: var(--tl-color-primary); box-shadow: 0 0 0 2px var(--tl-color-primary); }
.tl-timeline-date { display: block; margin-block-end: var(--tl-space-2xs); color: var(--tl-color-primary); font-size: var(--tl-step--1); font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
.tl-timeline h3 { margin: 0; font-size: var(--tl-step-1); }
.tl-timeline img { display: block; width: 100%; height: auto; border-radius: var(--tl-radius); }
.tl-timeline-card > * + * { margin-block-start: var(--tl-space-xs); }
@media (min-width: 768px) {
	.tl-timeline { --tl-axis-x: 50%; }
	.tl-timeline-item { width: 50%; padding-inline-start: 0; }
	.tl-timeline-item:nth-child(odd) { padding-inline-end: var(--tl-space-l); text-align: end; }
	.tl-timeline-item:nth-child(odd)::before { inset-inline-start: 100%; }
	.tl-timeline-item:nth-child(even) { margin-inline-start: 50%; padding-inline-start: var(--tl-space-l); }
	.tl-timeline-item:nth-child(even)::before { inset-inline-start: 0; }
}';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        foreach ($p['content']['milestones'] as $item) {
            if ($item['name'] === '' && $item['date'] === '') {
                continue;
            }
            $html .= '<li class="tl-timeline-item"><div class="tl-timeline-card">'
                . ($item['date'] !== '' ? '<span class="tl-timeline-date">' . e($item['date']) . '</span>' : '')
                . ($item['name'] !== '' ? '<h3>' . e($item['name']) . '</h3>' : '')
                . ($item['src'] !== '' ? '<img src="' . e($k->image($item['src'])) . '" alt="' . e($item['alt']) . '" loading="lazy">' : '')
                . $item['content'] . '</div></li>';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--tl-color-surface)">' . e(t('Add milestones in the Content panel.')) . '</div>' : '';
        }

        return '<ol' . Text::withClass($a, 'tl-timeline') . '>' . $html . '</ol>';
    }
}
