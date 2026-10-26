<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Redirects: match types (Core\RedirectRules), switching a rule off, when it last answered. The type also accepts 307, 410 and 451. */
final class ExtendRedirects extends AbstractMigration
{
    public function change(): void
    {
        $this->table('redirects')
            ->addColumn('match_type', 'string', ['limit' => 10, 'null' => false, 'default' => 'exact', 'comment' => 'exact | contains | starts | ends | regex'])
            ->addColumn('active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('last_hit_at', 'datetime', ['null' => true])
            ->update();
    }
}
