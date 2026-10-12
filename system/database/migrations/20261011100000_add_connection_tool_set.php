<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Which tools a Claude connection lists: all (every tool it may use, as before) or essentials (the core tools plus find_tools
 * and the runners, which reach the rest on demand). Existing connections keep all. The OAuth code carries the choice from the
 * consent screen to the tokens.
 */
final class AddConnectionToolSet extends AbstractMigration
{
    public function change(): void
    {
        foreach (['api_tokens', 'oauth_codes'] as $table) {
            $this->table($table)
                ->addColumn('tool_set', 'string', ['limit' => 16, 'null' => false, 'default' => 'all', 'after' => 'access', 'comment' => 'all | essentials – the tools tools/list shows'])
                ->update();
        }
    }
}
