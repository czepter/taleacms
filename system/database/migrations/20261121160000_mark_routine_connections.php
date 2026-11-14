<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A connection that was handed a scheduled run never confirms a two-step call while the hand-out window lasts (#334,
 * Core\AgentSchedules::withhold): api_tokens (an owner table, so a `site` call cannot step around it) carries the end of the
 * window, agent_runs the identity of the connection that holds the run (the name is not unique).
 */
final class MarkRoutineConnections extends AbstractMigration
{
    public function change(): void
    {
        $this->table('api_tokens')
            ->addColumn('routine_until', 'datetime', ['null' => true, 'after' => 'used_at', 'comment' => 'a scheduled run was handed to this connection: no two-step call is confirmed until then (Core\\ClaudeConnection::markRoutine)'])
            ->update();
        $this->table('agent_runs')
            ->addColumn('connection_key', 'string', ['limit' => 100, 'null' => false, 'default' => '', 'after' => 'connection', 'comment' => 'user and token or OAuth client that holds the run (Core\\ClaudeConnection::keyOf); connection is its name for display'])
            ->update();
    }
}
