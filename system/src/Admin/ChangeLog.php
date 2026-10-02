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
            $app->db()->insert('protokol', [
                'cas' => date('Y-m-d H:i:s'), 'kdo' => $user['idu'] ?? null, 'jmeno' => (string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? ''),
                'via' => mb_substr((string) ($app->auth()->connection()['name'] ?? ''), 0, 100), // a change made by Claude names its connection
                'modul' => mb_substr($module, 0, 30), 'akce' => mb_substr($action, 0, 40), 'popis' => mb_substr($description, 0, 255),
            ] + ($reason !== '' ? ['duvod' => mb_substr($reason, 0, 255)] : []));
            if (random_int(1, 100) === 1) {
                $app->db()->run('DELETE FROM {protokol} WHERE cas < NOW() - INTERVAL 180 DAY');
            }
        } catch (\Throwable) {
            // the log must not break the action it records (e.g. before the migration runs, the table does not exist yet)
        }
    }
}
