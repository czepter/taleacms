<?php

declare(strict_types=1);

namespace TaleaAddon\DomainWatch;

use Talea\Core\App;
use Talea\Core\Demo;
use Talea\Core\Request;
use Talea\Extension\Api;
use Talea\Extension\ExtensionInterface;
use Talea\Extension\LifecycleInterface;

require_once __DIR__ . '/DomainWatch.php';

/**
 * Domain watch (issue #28): the official add-on that was Core\DomainWatch. Off by default; switched on in Add-ons it adds
 * a daily job, rows in System status, findings before handing over, the event domain_watch.expiring (an alert e-mail),
 * a settings page with "Check now" and two tools for Claude. It is the pilot of the extension API 2.
 */
final class Extension implements ExtensionInterface, LifecycleInterface
{
    /** The job and the "Check now" button share one check. */
    private function check(Api $api): array
    {
        return (new DomainWatch(http: DomainWatch::viaApi($api)))->refresh($api);
    }

    public function register(Api $api): void
    {
        $api->eventType('domain_watch.expiring', 'The site certificate or the domain registration is about to expire, or has expired (what and the days left).', alert: true);

        $api->job('check', 86400, 'Domain, certificate and mail records', function () use ($api): string {
            $result = $this->check($api);

            return !empty($result['local']) ? 'local address, skipped' : 'checked';
        });

        $api->healthRows(fn (App $app): array => DomainWatch::rows(DomainWatch::cached($api), Demo::active(), time()));
        $api->handoverFindings(fn (App $app): array => array_map(
            fn (array $finding): array => $finding + ['edit' => 'admin.php?module=addons&action=page&p=domain_watch.settings'],
            DomainWatch::handoverFindings(DomainWatch::cached($api)),
        ));

        $api->settings(['alerts' => ['label' => 'Send an alert e-mail when the certificate or the domain is about to expire', 'type' => 'flag', 'default' => '1',
            'help' => 'The e-mail goes to the alert address (Settings → System status → Alerts).']], 'Domain watch', fn (Request $request): string => $this->page($api, $request));

        $api->mcpTool('status', 'Domain watch (read-only): the last check of the mail DNS records (SPF, DMARC, DKIM), the site certificate and the domain registration – the rows of System status and the problems to fix before handing over. null = no check yet.',
            ['properties' => []], 'read', fn (array $arguments): array => $this->status($api), 'editor');
        $api->mcpTool('check_now', 'Domain watch: runs the check now (DNS lookups, a TLS handshake with the site, a request to the domain registry) and returns the same as status.',
            ['properties' => []], 'write', function (array $arguments) use ($api): array {
                $this->check($api);

                return $this->status($api);
            }, 'admin');
    }

    /** @return array<string, mixed> */
    private function status(Api $api): array
    {
        $result = DomainWatch::cached($api);

        return ['checked' => $result === null ? null : date('c', (int) $result['checked']), 'local' => !empty($result['local']),
            'rows' => array_map(fn (array $row): array => ['name' => $row['name'], 'status' => $row['status'], 'info' => $row['info']], DomainWatch::rows($result, false, time())),
            'problems' => array_map(fn (array $finding): array => ['key' => $finding['key'], 'message' => $finding['message']], DomainWatch::handoverFindings($result))];
    }

    /** What the settings page shows below the form: the last check, the button, the rows. */
    private function page(Api $api, Request $request): string
    {
        $notice = '';
        if ($request->isPost() && $request->post('check_now') === '1') {
            $result = $this->check($api);
            $notice = '<p class="notice notice-ok">' . e(!empty($result['local'])
                ? t('The site runs on a local address – the certificate, the domain and the mail records are checked once it has its public address.')
                : t('The domain and mail check has run – the results are below.')) . '</p>';
        }
        $result = DomainWatch::cached($api);
        $icons = ['ok' => '✓', 'warning' => '!', 'error' => '✕'];
        $rows = '';
        foreach (DomainWatch::rows($result, false, time()) as $row) {
            $rows .= '<tr><td class="center"><span class="badge badge-' . ['ok' => 'published', 'warning' => 'draft', 'error' => 'error'][$row['status']] . '">' . $icons[$row['status']] . '</span></td><td><strong>' . e($row['name']) . '</strong></td><td>' . e($row['info']) . '</td></tr>';
        }

        return $notice . '<h2>' . e(t('Domain and mail')) . '</h2><p>' . e($result === null
            ? t('The mail DNS records (SPF, DMARC, DKIM), the site certificate and the domain registration have not been checked yet. The check runs once a day on its own.')
            : t('Last checked %s. The check runs once a day on its own.', format_date((new \DateTimeImmutable())->setTimestamp((int) $result['checked']), true)))
            . ' ' . e(t('The results are also in System status.')) . '</p>'
            . '<form method="post">' . $api->app()->session->csrfField() . '<p><button class="navigation" type="submit" name="check_now" value="1">' . e(t('Check now')) . '</button></p></form>'
            . ($rows !== '' ? '<div class="tab-wrap"><table class="listing"><tbody>' . $rows . '</tbody></table></div>' : '');
    }

    public function onEnable(Api $api): void
    {
        // before the add-on the result lived in the core setting "domain_watch": carry it over once, then forget the old key
        $db = $api->app()->db();
        $old = $db->value('SELECT value FROM {settings} WHERE name = ?', ['domain_watch']);
        if (is_string($old) && $old !== '') {
            if ($api->get(DomainWatch::RESULT) === '') {
                $api->set(DomainWatch::RESULT, $old);
            }
            $db->run('DELETE FROM {settings} WHERE name = ?', ['domain_watch']);
        }
    }

    public function onUninstall(Api $api, bool $deleteData): void
    {
        // nothing outside the add-on's own settings (ext.domain_watch.*), which Talea deletes with "delete data"
    }
}
