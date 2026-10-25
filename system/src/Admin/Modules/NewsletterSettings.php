<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

/** The newsletter's connection to a mailing service (formerly folded into Features – the settings keys stay). Shown while the feature is on. */
final class NewsletterSettings extends Settings
{
    public const string IDENT = 'newsletter_settings';
    public const string NAME = 'Newsletter';
    public const string EXTENSION = 'newsletter_signup';

    protected function tab(string $tab): string
    {
        return 'newsletter';
    }
}
