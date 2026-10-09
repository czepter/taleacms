<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Expandable items (accordion) as <details> – without JavaScript. Optionally only one open at a time (the name attribute)
 * and FAQPage structured data – only in a page, not in the header and footer (otherwise every page of the site would be an FAQ).
 */
final class Faq extends Element
{
    public const string TYPE = 'faq';
    public const string NAME = 'Questions and answers (accordion)';
    public const string DESCRIPTION = 'Expandable items – questions (FAQ for search engines) or any content that need not be visible straight away.';
    public const string ICON = 'faq';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['items' => ['type' => 'items', 'popisek' => 'Questions', 'max' => 30, 'pole' => [
            'otazka' => ['type' => 'text', 'popisek' => 'Question', 'vychozi' => '', 'max' => 300],
            'odpoved' => ['type' => 'html', 'popisek' => 'Answer', 'vychozi' => ''],
        ], 'vychozi' => [['otazka' => t('How long does a project take?'), 'odpoved' => '<p>' . t('Usually two to four weeks, depending on scope.') . '</p>'], ['otazka' => t('How much does it cost?'), 'odpoved' => '<p>' . t('We will prepare a tailored quote – just get in touch.') . '</p>']]],
            'jedna' => ['type' => 'prepinac', 'popisek' => 'Only one item open at a time', 'vychozi' => false],
            'faq' => ['type' => 'prepinac', 'popisek' => 'These are questions and answers (FAQ for search engines)', 'vychozi' => true]];
    }

    public static function baseCss(): string
    {
        return '.ka-faq details { border-block-end: 1px solid var(--ka-barva-linka); }
.ka-faq summary { display: flex; justify-content: space-between; gap: 1em; padding-block: var(--ka-mezera-s); font-weight: 600; cursor: pointer; list-style: none; }
.ka-faq summary::-webkit-details-marker { display: none; }
.ka-faq summary::after { content: "+"; font-size: 1.4em; line-height: 1; color: var(--ka-barva-primarni); transition: rotate 0.2s; }
.ka-faq details[open] summary::after { rotate: 45deg; }
.ka-faq details > div { padding-block-end: var(--ka-mezera-s); color: var(--ka-barva-tlumeny); }
.ka-faq details > div > :last-child { margin-block-end: 0; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = '';
        $faq = $p['obsah']['faq'] && !str_starts_with($k->source, 'cast:') && !str_starts_with($k->source, 'popup:'); // a pop-up is not the page's content
        $group = $p['obsah']['jedna'] ? ' name="faq-' . e($p['id']) . '"' : '';
        foreach ($p['obsah']['items'] as $i => $item) {
            if ($item['otazka'] === '') {
                continue;
            }
            if ($faq) {
                $k->faq[] = [$item['otazka'], trim(strip_tags($item['odpoved']))];
            }
            $html .= '<details' . $group . ($i === 0 && $k->editor ? ' open' : '') . '><summary>' . e($item['otazka']) . '</summary><div>' . $item['odpoved'] . '</div></details>';
        }

        return '<div' . Text::withClass($a, 'ka-faq') . '>' . $html . '</div>';
    }
}
