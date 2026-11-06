<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\App;

/**
 * Change log: who did what in the admin and when, and – for Claude's changes – why (2.15: the reason a write tool was
 * given). Records older than half a year are deleted.
 */
final class ChangeLog
{
    /**
     * A restore or a deletion of a backup: the change log and the events (type backup.restored | backup.deleted). A restore replaces the
     * tables, so this is written AFTER it – into the restored change log, where the person can still find it.
     */
    public static function backup(App $app, string $module, string $action, string $file): void
    {
        self::write($app, $module, $action, basename($file));
        try {
            \Talea\Core\Events::record($app->db(), $action === 'backup_restore' ? 'backup.restored' : 'backup.deleted', 'info', $action === 'backup_restore' ? t('Backup %s was restored.', basename($file)) : t('Backup %s was deleted.', basename($file)), ['file' => basename($file)]);
        } catch (\Throwable) {
            // like the change log: recording must not break the action
        }
    }

    /** $detail: what does not fit the description (#149: the whole instructions for Claude before and after, as JSON). */
    public static function write(App $app, string $module, string $action, string $description = '', string $reason = '', string $detail = ''): void
    {
        try {
            $user = $app->auth()->user();
            $app->db()->insert('change_log', [
                'created_at' => date('Y-m-d H:i:s'), 'user_id' => $user['user_id'] ?? null, 'user_name' => (string) ($user['name'] ?? '') ?: (string) ($user['username'] ?? ''),
                'via' => mb_substr((string) ($app->auth()->connection()['name'] ?? ''), 0, 100), // a change made by Claude names its connection
                'module' => mb_substr($module, 0, 30), 'action' => mb_substr($action, 0, 40), 'description' => mb_substr($description, 0, 255),
            ] + ($reason !== '' ? ['reason' => mb_substr($reason, 0, 255)] : []) + ($detail !== '' ? ['detail' => $detail] : []));
            if (random_int(1, 100) === 1) {
                $app->db()->run('DELETE FROM {change_log} WHERE created_at < NOW() - INTERVAL 180 DAY');
            }
        } catch (\Throwable) {
            // the log must not break the action it records (e.g. before the migration runs, the table does not exist yet)
        }
    }
}
