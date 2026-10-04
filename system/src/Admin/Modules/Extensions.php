<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

/**
 * Features – the built-in extensions – as a separate item of the main menu (formerly a Settings tab; "Features" since 3.2,
 * the ident stays). Add-ons share its hub.
 *
 * The screen and saving are the same as for the Settings tabs - the module only keeps a fixed "tab" extensions,
 * renders the templates from the config/ folder and returns to its own URL (admin.php?module=extensions).
 */
final class Extensions extends Settings
{
    public const string IDENT = 'extensions';
    public const string NAME = 'Features';
    public const string HUB = 'features';
    public const string ICON = 'prepinace';

    protected function tab(string $tab): string
    {
        return 'extensions';
    }
}
