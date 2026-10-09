<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

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
        return '.ka-timeline { --ka-axis-x: 0.75rem; position: relative; display: grid; gap: var(--ka-space-l); margin: 0; padding: 0; list-style: none; }
.ka-timeline::before { content: ""; position: absolute; inset-block: 0; inset-inline-start: var(--ka-axis-x); width: 2px; translate: -50% 0; background: var(--ka-color-line); }
.ka-timeline-item { position: relative; padding-inline-start: calc(var(--ka-axis-x) + var(--ka-space-m)); }
.ka-timeline-item::before { content: ""; position: absolute; inset-block-start: 0.3rem; inset-inline-start: var(--ka-axis-x); width: 1rem; height: 1rem; translate: -50% 0; border: 3px solid var(--ka-color-background); border-radius: 50%; background: var(--ka-color-primary); box-shadow: 0 0 0 2px var(--ka-color-primary); }
.ka-timeline-date { display: block; margin-block-end: var(--ka-space-2xs); color: var(--ka-color-primary); font-size: var(--ka-step--1); font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
.ka-timeline h3 { margin: 0; font-size: var(--ka-step-1); }
.ka-timeline img { display: block; width: 100%; height: auto; border-radius: var(--ka-radius); }
.ka-timeline-card > * + * { margin-block-start: var(--ka-space-xs); }
@media (min-width: 768px) {
	.ka-timeline { --ka-axis-x: 50%; }
	.ka-timeline-item { width: 50%; padding-inline-start: 0; }
	.ka-timeline-item:nth-child(odd) { padding-inline-end: var(--ka-space-l); text-align: end; }
	.ka-timeline-item:nth-child(odd)::before { inset-inline-start: 100%; }
	.ka-timeline-item:nth-child(even) { margin-inline-start: 50%; padding-inline-start: var(--ka-space-l); }
	.ka-timeline-item:nth-child(even)::before { inset-inline-start: 0; }
}';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        foreach ($p['content']['milestones'] as $item) {
            if ($item['name'] === '' && $item['date'] === '') {
                continue;
            }
            $html .= '<li class="ka-timeline-item"><div class="ka-timeline-card">'
                . ($item['date'] !== '' ? '<span class="ka-timeline-date">' . e($item['date']) . '</span>' : '')
                . ($item['name'] !== '' ? '<h3>' . e($item['name']) . '</h3>' : '')
                . ($item['src'] !== '' ? '<img src="' . e($k->image($item['src'])) . '" alt="' . e($item['alt']) . '" loading="lazy">' : '')
                . $item['content'] . '</div></li>';
        }
        if ($html === '') {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-color-surface)">' . e(t('Add milestones in the Content panel.')) . '</div>' : '';
        }

        return '<ol' . Text::withClass($a, 'ka-timeline') . '>' . $html . '</ol>';
    }
}
