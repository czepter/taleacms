<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Is the "Created and maintained by" card in use (docs/specs/settings-ux.md 5.5)? One or several websites: only when the switch
 * `agency_enabled` is on or a user has a role flagged "Agency role". Agency mode: always. Hidden means hidden in the UI only: the stored
 * values stay, consumers print nothing.
 */
final class Agency
{
    /** The rule itself, without the database. */
    public static function decide(string $level, bool $switch, bool $role): bool
    {
        return $level === Deployment::AGENCY || $switch || $role;
    }

    public static function visible(Settings $s): bool
    {
        $switch = $s->bool('agency_enabled');

        return self::decide(Deployment::level(), $switch, !$switch && self::roleExists($s->db()));
    }

    /** Does a user (not blocked) have a role with the Agency flag? Then the switch is on automatically. */
    public static function roleExists(Db $db): bool
    {
        return $db->value('SELECT 1 FROM {users} u JOIN {role} r ON r.role_id = u.role WHERE r.is_agency = TRUE AND u.blocked = FALSE LIMIT 1') !== null;
    }

    /** May this person change the card? In agency mode only operators; otherwise every administrator who reaches the settings. @param array<string, mixed>|null $user */
    public static function editable(Db $db, ?array $user): bool
    {
        return Deployment::level() !== Deployment::AGENCY || Owners::isOperator($db, $user);
    }
}
