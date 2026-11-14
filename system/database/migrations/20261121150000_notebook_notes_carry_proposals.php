<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A note written through a drafts-only Claude connection is a proposal until a person saves it in the administration
 * (Core\Notebook, #334): the flag marks it, so it is never pinned and read_notebook tells Claude to treat it as a suggestion.
 */
final class NotebookNotesCarryProposals extends AbstractMigration
{
    public function change(): void
    {
        $this->table('notebook')
            ->addColumn('proposal', 'boolean', ['null' => false, 'default' => false, 'after' => 'pinned', 'comment' => 'written through a drafts-only Claude connection (Core\\Notebook): a suggestion, never pinned, until a person saves it in the admin'])
            ->update();
    }
}
