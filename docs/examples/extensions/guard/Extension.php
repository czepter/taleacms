<?php

declare(strict_types=1);

namespace TaleaExample\Guard;

use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\Response;
use Talea\Extension\Api;
use Talea\Extension\ExtensionInterface;
use Talea\Extension\LifecycleInterface;

/**
 * An example add-on for extension API 2 (docs/EXTENSIONS.md): copy the folder to extensions/guard/ and switch it on in Add-ons.
 * It refuses requests to one path, counts them in its own table and shows what API 2 adds to API 1.
 */
final class Extension implements ExtensionInterface, LifecycleInterface
{
    public function register(Api $api): void
    {
        // 1. an early request hook: runs before routing, sessions and the page cache; null = go on, a Response = the answer
        $api->earlyRequest(function (Request $request) use ($api): ?Response {
            $blocked = $api->get('blocked_path');
            if ($blocked === '' || $request->path() !== $blocked) {
                return null;
            }
            $api->app()->db()->run('INSERT INTO {ext_guard_log} (path) VALUES (?)', [$request->path()]);

            return new Response('Refused by the guard add-on.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        });

        // 2. a row in System status (only while the add-on is on)
        $api->healthRows(fn (App $app): array => [['group' => 'Guard', 'name' => 'Refused requests', 'status' => 'ok',
            'info' => (int) $app->db()->value('SELECT COUNT(*) FROM {ext_guard_log}') . ' in the log']]);

        // 3. a finding for "Before handing over"
        $api->handoverFindings(fn (App $app): array => (int) $app->db()->value('SELECT COUNT(*) FROM {ext_guard_log}') > 0
            ? [['key' => 'refused', 'message' => 'The guard refused requests – look at its log.', 'edit' => 'admin.php?module=addons&action=page&p=guard.settings']] : []);

        // 4. an event type; an event of it with severity warning sends the alert e-mail ($alert = true)
        $api->eventType('guard.refused', 'The guard refused many requests (the count).', alert: true);

        // 5. declared settings and their page (Add-ons → Guard settings); values are validated
        $api->settings([
            'blocked_path' => ['label' => 'Path to refuse', 'type' => 'text', 'default' => '/guard-blocked', 'help' => 'Requests to exactly this path get a 403.'],
            'keep_days' => ['label' => 'Keep the log for (days)', 'type' => 'number:1:365', 'default' => '30'],
        ], 'Guard settings');

        // 6. a job that runs only when cron calls /tasks, never during a visit
        $api->job('cleanup', 3600, 'Guard: delete old log rows', function () use ($api): string {
            $db = $api->app()->db();
            $db->run('DELETE FROM {ext_guard_log} WHERE created_at < NOW() - ' . $db->dialect()->interval((int) $api->get('keep_days'), 'DAY'));

            return 'ok';
        }, 'cron');
    }

    public function onEnable(Api $api): void
    {
        // one-time work after the migrations (migrations/NNNN-name.sql ran already); must be safe to repeat
    }

    public function onUninstall(Api $api, bool $deleteData): void
    {
        // Talea drops the tables and settings of the add-on when $deleteData is true; remove anything else here
    }
}
