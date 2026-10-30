<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, handoff tokens (docs/specs/multisite.md): a token is bound to the network of the browser that asked for it, so a link that
 * leaks (a proxy log, a referrer) signs nobody in from elsewhere. Only a hash of the network is kept. Owner table.
 */
final class AddHandoffNetwork extends AbstractMigration
{
    public function change(): void
    {
        $this->table('handoff_tokens')
            ->addColumn('network_hash', 'char', ['limit' => 64, 'null' => true, 'comment' => 'sha256 of the network of the person who asked for the token; the claim must come from the same one'])
            ->update();
    }
}
