<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Missing pages: where the last visitor came from (host and path, no query) and when the address was first seen. */
final class AddNotFoundReferrer extends AbstractMigration
{
    public function change(): void
    {
        $this->table('not_found')
            ->addColumn('referrer', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'host and path of the last referrer; an own-site referrer is just its /path'])
            ->addColumn('first_seen_at', 'datetime', ['null' => true])
            ->update();
    }
}
