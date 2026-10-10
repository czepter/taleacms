<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * Pricing table: plans as cards in a responsive grid – name, price with its period, a short description, features and a button.
 * One plan can be highlighted (primary border and background, a label such as "Most popular"). A feature line starting with "-"
 * is not included in the plan: shown crossed out and greyed, with a text for screen readers – the line-through alone says nothing to them.
 */
final class PricingTable extends Element
{
    public const string TYPE = 'pricing_table';
    public const string NAME = 'Pricing table';
    public const string DESCRIPTION = 'Plans side by side – name, price, features and a button; one plan can be highlighted.';
    public const string ICON = 'pricing_table';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        $plan = fn (string $name, string $price, string $description, string $features, bool $highlighted): array => [
            'name' => $name, 'price' => $price, 'period' => t('/ month'), 'description' => $description, 'features' => $features,
            'button_text' => t('Choose'), 'link' => '#', 'highlighted' => $highlighted, 'badge' => t('Most popular'),
        ];

        return ['plans' => ['type' => 'items', 'label' => 'Plans', 'max' => 6, 'fields' => [
            'name' => ['type' => 'text', 'label' => 'Plan name', 'default' => '', 'max' => 80],
            'price' => ['type' => 'text', 'label' => 'Price', 'default' => '', 'max' => 40],
            'period' => ['type' => 'text', 'label' => 'Period (e.g. / month)', 'default' => '', 'max' => 40],
            'description' => ['type' => 'text', 'label' => 'Short description', 'default' => '', 'max' => 300],
            'features' => ['type' => 'lines', 'label' => 'Features – one per line; a line starting with "-" is not included', 'default' => '', 'max' => 2000],
            'button_text' => ['type' => 'text', 'label' => 'Button text', 'default' => '', 'max' => 80],
            'link' => ['type' => 'link', 'label' => 'Button link', 'default' => '#'],
            'highlighted' => ['type' => 'boolean', 'label' => 'Highlighted plan', 'default' => false],
            'badge' => ['type' => 'text', 'label' => 'Highlight label', 'default' => '', 'max' => 60],
        ], 'default' => [
            $plan(t('Basic'), '9', t('For a start.'), t('First feature') . "\n" . t('Second feature') . "\n- " . t('Third feature') . "\n- " . t('Fourth feature'), false),
            $plan(t('Standard'), '29', t('For most customers.'), t('First feature') . "\n" . t('Second feature') . "\n" . t('Third feature') . "\n- " . t('Fourth feature'), true),
            $plan(t('Premium'), '79', t('Everything included.'), t('First feature') . "\n" . t('Second feature') . "\n" . t('Third feature') . "\n" . t('Fourth feature'), false),
        ]]];
    }

    public static function baseCss(): string
    {
        return '.tl-pricing { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(16rem, 100%), 1fr)); gap: var(--tl-space-m); align-items: stretch; }
.tl-pricing-plan { position: relative; display: flex; flex-direction: column; gap: var(--tl-space-s); padding: var(--tl-space-l); border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); }
.tl-pricing-plan--highlighted { border: 2px solid var(--tl-color-primary); background: var(--tl-color-primary-soft); box-shadow: var(--tl-shadow-m); }
.tl-pricing-badge { position: absolute; inset-block-start: 0; inset-inline-start: 50%; margin: 0; padding: 0.25em 0.9em; translate: -50% -50%; border-radius: 999px; background: var(--tl-color-primary); color: var(--tl-color-on-primary); font-size: var(--tl-step--1); font-weight: 600; white-space: nowrap; }
.tl-pricing-plan h3 { margin: 0; font-size: var(--tl-step-1); }
.tl-pricing-price { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.35em; margin: 0; }
.tl-pricing-price strong { font: 800 var(--tl-step-4) / 1 var(--tl-font-heading); }
.tl-pricing-price span { color: var(--tl-color-muted); }
.tl-pricing-description { margin: 0; color: var(--tl-color-muted); }
.tl-pricing-features { display: grid; flex: 1; gap: var(--tl-space-2xs); margin: 0; padding: 0; list-style: none; }
.tl-pricing-features li { display: flex; align-items: flex-start; gap: 0.6em; }
.tl-pricing-features li::before { content: "✓"; content: "✓" / ""; flex: none; color: var(--tl-color-primary); font-weight: 700; }
.tl-pricing-features .tl-pricing-no { color: var(--tl-color-muted); text-decoration: line-through; }
.tl-pricing-features .tl-pricing-no::before { content: "–"; content: "–" / ""; color: var(--tl-color-muted); }
.tl-pricing-plan .tl-button { justify-content: center; margin-block-start: auto; }
.tl-pricing-sr { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }';
    }

    /** @return list<array{0: bool, 1: string}> features as [included, text] – a line starting with "-" is not included */
    public static function features(string $lines): array
    {
        $features = [];
        foreach (array_filter(array_map('trim', explode("\n", $lines)), fn (string $l): bool => $l !== '') as $line) {
            $excluded = str_starts_with($line, '-');
            $text = trim($excluded ? substr($line, 1) : $line);
            if ($text !== '') {
                $features[] = [!$excluded, $text];
            }
        }

        return $features;
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $plans = array_values(array_filter($p['content']['plans'], fn (array $x): bool => $x['name'] !== ''));
        if ($plans === []) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--tl-color-surface)">' . e(t('Add plans in the Content panel.')) . '</div>' : '';
        }
        $k->types['button'] = true; // the plan buttons are Button elements in appearance – their CSS goes to the page too
        $html = '';
        foreach ($plans as $i => $plan) {
            $highlighted = !empty($plan['highlighted']);
            $heading = 'cn-' . $p['id'] . '-' . $i;
            $features = '';
            foreach (self::features((string) $plan['features']) as [$included, $text]) {
                $features .= '<li' . ($included ? '' : ' class="tl-pricing-no"') . '><span class="tl-pricing-sr">' . e($included ? t('Included:') : t('Not included:')) . ' </span>' . e($text) . '</li>';
            }
            $html .= '<article class="tl-pricing-plan' . ($highlighted ? ' tl-pricing-plan--highlighted' : '') . '" aria-labelledby="' . $heading . '">'
                . ($highlighted && $plan['badge'] !== '' ? '<p class="tl-pricing-badge">' . e($plan['badge']) . '</p>' : '')
                . '<h3 id="' . $heading . '">' . e($plan['name']) . '</h3>'
                . ($plan['price'] !== '' ? '<p class="tl-pricing-price"><strong>' . e($plan['price']) . '</strong>' . ($plan['period'] !== '' ? ' <span>' . e($plan['period']) . '</span>' : '') . '</p>' : '')
                . ($plan['description'] !== '' ? '<p class="tl-pricing-description">' . e($plan['description']) . '</p>' : '')
                . ($features !== '' ? '<ul class="tl-pricing-features">' . $features . '</ul>' : '')
                . ($plan['button_text'] !== '' ? '<a class="tl-button tl-button--' . ($highlighted ? 'primary' : 'outline') . '" href="' . e($plan['link'] !== '' ? $plan['link'] : '#') . '">' . e($plan['button_text']) . '</a>' : '')
                . '</article>';
        }

        return '<div' . Text::withClass($a, 'tl-pricing') . '>' . $html . '</div>';
    }
}
