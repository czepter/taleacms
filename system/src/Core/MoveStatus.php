<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Where a move of a site to this one stands (Site audit, MCP site_audit): pages imported, layouts still to rebuild, starter pages left,
 * redirects, forms and the latest migration report. Not a finding of the audit, so the score does not change; it reads the report's
 * stored summary and never goes to the network.
 */
final class MoveStatus
{
    public static function of(App $app): ?array
    {
        return self::status($app->db(), $app->settings(), MigrationReport::listAll()[0] ?? null);
    }

    /**
     * @param array<string, mixed>|null $report the latest saved migration report (Core\MigrationReport state)
     * @return array<string, mixed>|null null = nothing was imported and no report was made
     */
    public static function status(Db $db, Settings $settings, ?array $report): ?array
    {
        $imported = (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE import_source IS NOT NULL AND deleted_at IS NULL');
        if ($imported === 0 && $report === null) {
            return null;
        }
        $latest = null;
        if ($report !== null) {
            $summary = (array) ($report['summary'] ?? []);
            $done = ($report['phase'] ?? '') === 'done';
            $errors = (int) ($summary['failed'] ?? 0);
            $warnings = (int) ($summary['warnings'] ?? 0);
            $reachable = !($summary['unreachable'] ?? false);
            $latest = ['old_site' => (string) ($report['web'] ?? ''), 'checked_at' => (string) ($report['completed'] ?? $report['created'] ?? ''), 'done' => $done,
                'errors' => $errors, 'warnings' => $warnings, 'reachable' => $reachable, 'clean' => $done && $reachable && $errors === 0 && $warnings === 0];
        }

        return [
            'pages_imported' => $imported,
            'layout_pending' => LayoutQueue::count($db),
            'starter_pages_left' => count(array_filter(StarterPages::untouched($db, $settings), fn (array $p): bool => !$p['home'])),
            'redirects' => (int) $db->value('SELECT COUNT(*) FROM {redirects}'),
            'forms' => (int) $db->value('SELECT COUNT(*) FROM {forms}'),
            'report' => $latest,
        ];
    }
}
