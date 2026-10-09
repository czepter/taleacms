<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\App;

/**
 * Change log: who did what in the admin and when, and – for Claude's changes – why (2.15: the reason a write tool was
 * given). Records older than half a year are deleted.
 */
final class ChangeLog
{
    public static function write(App $app, string $module, string $action, string $description = '', string $reason = ''): void
    {
        try {
            $user = $app->auth()->user();
            $app->db()->insert('change_log', [
                'created_at' => date('Y-m-d H:i:s'), 'user_id' => $user['user_id'] ?? null, 'user_name' => (string) ($user['jmeno'] ?? '') ?: (string) ($user['username'] ?? ''),
                'via' => mb_substr((string) ($app->auth()->connection()['name'] ?? ''), 0, 100), // a change made by Claude names its connection
                'module' => mb_substr($module, 0, 30), 'action' => mb_substr($action, 0, 40), 'description' => mb_substr($description, 0, 255),
            ] + ($reason !== '' ? ['reason' => mb_substr($reason, 0, 255)] : []));
            if (random_int(1, 100) === 1) {
                $app->db()->run('DELETE FROM {change_log} WHERE created_at < NOW() - INTERVAL 180 DAY');
            }
        } catch (\Throwable) {
            // the log must not break the action it records (e.g. before the migration runs, the table does not exist yet)
        }
    }
}
