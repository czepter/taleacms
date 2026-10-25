<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A change log row can carry what does not fit its description: the whole text of the instructions for Claude before and
 * after a change (#149, Core\ClaudeInstructions), so a rewrite leaves a full trace.
 */
final class AddChangeLogDetail extends AbstractMigration
{
    public function change(): void
    {
        $this->table('change_log')
            ->addColumn('detail', 'text', ['null' => true, 'after' => 'reason', 'comment' => 'JSON with what the description cannot hold, e.g. {"before":…,"after":…}'])
            ->update();
    }
}
