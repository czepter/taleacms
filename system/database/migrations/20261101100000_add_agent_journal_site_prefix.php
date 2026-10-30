<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, MCP (docs/specs/multisite.md): the journal of a Claude session is an owner table, but a call may change a further site
 * (the `site` argument). site_prefix names the tables the entry belongs to, so undo writes the row back on that site; '' = the owner's
 * own site (site 1) and every entry made before multisite. Owner table: changed once, in the owner run.
 */
final class AddAgentJournalSitePrefix extends AbstractMigration
{
    public function change(): void
    {
        $this->table('agent_journal')
            ->addColumn('site_prefix', 'string', ['limit' => 20, 'null' => false, 'default' => '', 'comment' => 'table prefix of the site the row belongs to; empty = the owner\'s own site'])
            ->update();
    }
}
