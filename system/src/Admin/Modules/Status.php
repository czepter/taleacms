<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

/**
 * System status (3.2: its own item under Site care, formerly a Settings tab – the same screen, checks and actions).
 */
final class Status extends Settings
{
    public const string IDENT = 'status';
    public const string NAME = 'System status';
    public const string GROUP = 'Site care';
    public const string ICON = 'pulse';

    protected function tab(string $tab): string
    {
        return 'health';
    }
}
