<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Where a further owner's (a client's) own backups and site exports live: storage/backups/owners/<the owner's public id>/, one folder per
 * owner, never reachable from another owner's administration. The first owner keeps the install's folder (storage/backups) and is refused here.
 * The folder name is the UUID of the owners row – read from the registry, never from a request – and is checked once more before it is used.
 */
final class OwnerFiles
{
    /** The folder of the owner this connection belongs to. @throws \LogicException for the first owner */
    public static function folder(Db $db): string
    {
        if (Owners::isFirst($db)) {
            throw new \LogicException('The first owner uses the installation\'s own folder.');
        }
        $id = Owners::publicIdOf($db, $db->ownerPrefix);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new \LogicException('This owner has no folder.');
        }

        return Backup::FOLDER . '/owners/' . $id;
    }
}
