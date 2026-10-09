<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Switcher of the site's language versions as a separate element – e.g. in the footer, when the Navigation element does not have it
 * (the „Přepínač jazyků“ (language switcher) option off). The menu is the Popover API without a script; in the footer it opens upwards.
 * A site with a single language outputs nothing.
 */
final class LanguageSwitcher extends Element
{
    public const string TYPE = 'language_switcher';
    public const string NAME = 'Language switcher';
    public const string DESCRIPTION = 'Choose the language version of the site – a row of codes or a dropdown (for example in the footer).';
    public const string ICON = 'globe';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['nav'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return [
            'style' => ['type' => 'choice', 'label' => 'Style', 'default' => 'dropdown', 'options' => ['dropdown' => 'dropdown', 'row' => 'codes in a row']],
            'direction' => ['type' => 'choice', 'label' => 'The dropdown opens', 'default' => 'up', 'options' => ['up' => 'upwards (footer)', 'down' => 'downwards (header)']],
        ];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        if ($k->languageList === []) {
            // a single language: nothing on the site, in the builder only a notice where languages are enabled
            return $k->editor ? '<span' . $a . ' style="display:inline-block;padding:.4rem .8rem;border:1px dashed currentColor;border-radius:999px;font-size:.85rem">'
                . e(t('Language switcher – it shows when the site has more language versions')) . '</span>' : '';
        }

        return $k->app->view->render('front/jazyky', ['languages' => $k->languageList, 'style' => $p['content']['style'] ?? 'dropdown', 'direction' => $p['content']['direction'] ?? 'nahoru', 'attributes' => Text::withClass($a, 'ka-languages-element')]);
    }
}
