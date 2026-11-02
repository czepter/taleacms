<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * How this installation is run (docs/specs/settings-ux.md): one website, several websites of one owner, or agency mode.
 * Views and modules ask this, never the two environment flags directly.
 */
final class Deployment
{
    public const string SINGLE = 'single';
    public const string MULTISITE = 'multisite';
    public const string AGENCY = 'agency';

    /** Minimum level at which a thing is offered: single < multisite < agency. */
    private const array RANK = [self::SINGLE => 0, self::MULTISITE => 1, self::AGENCY => 2];

    public static function level(): string
    {
        return Owners::enabled() ? self::AGENCY : (Sites::enabled() ? self::MULTISITE : self::SINGLE);
    }

    /** Is the installation at least at $level (agency includes multisite)? */
    public static function atLeast(string $level): bool
    {
        return self::RANK[self::level()] >= self::RANK[$level];
    }
}
