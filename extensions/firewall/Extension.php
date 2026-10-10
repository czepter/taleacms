<?php

declare(strict_types=1);

namespace TaleaAddon\Firewall;

use Talea\Core\Antispam;
use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\Response;
use Talea\Extension\Api;
use Talea\Extension\ExtensionInterface;
use Talea\Extension\LifecycleInterface;

require_once __DIR__ . '/Firewall.php';

/**
 * Firewall (issue #29): the official add-on that was Core\Firewall. Off by default; switched on in Add-ons it refuses blocked requests
 * before routing, sessions and the page cache (an early request hook), counts probes for other systems on addresses that end in 404, and
 * adds a settings page with the blocks and the log, a job that cleans up, a row in System status and a read-only tool for Claude.
 * What every site needs – the client address behind a proxy and the limits for sign-in, password reset, the Claude connection and page
 * locks – stays in core (Core\Antispam). If the add-on throws or is slow, Talea switches it off and the request goes on (fail-open).
 */
final class Extension implements ExtensionInterface, LifecycleInterface
{
    public function register(Api $api): void
    {
        $api->earlyRequest(fn (Request $request): ?Response => Firewall::check($api, $request->path()));
        $api->notFound(fn (Request $request, string $path): ?Response => Firewall::notFound($api, $path));

        $api->eventType('firewall.blocked', 'An address was blocked for a while (it probed for other systems).');
        $api->job('cleanup', 3600, 'Firewall: old log rows and expired blocks', function () use ($api): string {
            Firewall::cleanUp($api->app()->db());

            return 'ok';
        });

        $api->healthRows(function (App $app): array {
            $db = $app->db();
            $blocked = (int) $db->value('SELECT COUNT(*) FROM {ext_firewall_blocks} WHERE blocked_until > NOW()');
            $refused = (int) $db->value('SELECT COUNT(*) FROM {ext_firewall_log} WHERE created_at > NOW() - ' . $db->dialect()->interval(1, 'DAY'));

            return [['group' => 'Firewall', 'name' => t('Firewall'), 'status' => 'ok',
                'info' => t('On: %d address(es) blocked now, %d request(s) refused in the last 24 hours.', $blocked, $refused)]];
        });

        $api->settings([
            'probes' => ['label' => 'Block probing', 'type' => 'flag', 'default' => '1',
                'help' => 'An address that asks for /wp-login.php, /.env and similar addresses of other systems 5 times in an hour is blocked for 24 hours.'],
            'rate' => ['label' => 'Requests per minute from one address', 'type' => 'number:0:10000', 'default' => '0',
                'help' => '0 = no limit. 120 is plenty for people; it stops aggressive scrapers.'],
            'ips' => ['label' => 'Blocked addresses and networks', 'type' => 'lines',
                'help' => 'One per line, e.g. 203.0.113.7 or 198.51.100.0/24; a comment after #.'],
            'countries' => ['label' => 'Blocked countries', 'type' => 'text',
                'help' => 'Two-letter codes separated by commas, e.g. RU, CN. Only when the country is known (see above).'],
        ], 'Firewall', fn (Request $request): string => $this->page($api, $request));

        $api->mcpTool('blocks', 'Firewall (read-only): the addresses blocked right now (with the reason and until when), the last refused requests and the settings. Use it to explain why a visitor was blocked.',
            ['properties' => []], 'read', fn (array $arguments): array => $this->blocks($api), 'admin');
    }

    /** @return array<string, mixed> */
    private function blocks(Api $api): array
    {
        $db = $api->app()->db();

        return [
            'blocked' => array_map(fn (array $b): array => ['ip' => $b['ip'], 'reason' => $b['reason'], 'until' => date('c', (int) strtotime((string) $b['blocked_until']))], Firewall::blocks($db)),
            'refused' => array_map(fn (array $l): array => ['at' => date('c', (int) strtotime((string) $l['created_at'])), 'ip' => $l['ip'], 'reason' => $l['reason'], 'path' => $l['path']], Firewall::log($db)),
            'settings' => ['probes' => $api->get('probes') === '1', 'rate' => (int) $api->get('rate'), 'networks' => Firewall::parseList($api->get('ips'))[0], 'countries' => Firewall::countries($api->get('countries'))],
        ];
    }

    /** What the settings page shows around the form: the visitor's own address, the blocks with "Unblock", the log. */
    private function page(Api $api, Request $request): string
    {
        $app = $api->app();
        $db = $app->db();
        $notice = '';
        if ($request->isPost() && $request->post('unblock') !== '') {
            $db->run('DELETE FROM {ext_firewall_blocks} WHERE ip = ?', [mb_substr($request->post('unblock'), 0, 45)]);
            $notice = '<p class="notice notice-ok">' . e(t('The address is no longer blocked.')) . '</p>';
        }
        $proxy = $app->settings()->get('trusted_proxy');
        $country = Firewall::country($request->serverValues(), $proxy);
        $invalid = Firewall::parseList($api->get('ips'))[1];
        $reasons = Firewall::reasons();
        $date = fn (string $value): string => format_date(new \DateTimeImmutable($value), true);

        $html = $notice . '<p class="notice">' . e(t('The firewall guards the public site and the Claude connection – never the administration, so you cannot lock yourself out. Addresses of your local network are never blocked.')) . '</p>'
            . '<p>' . e(t('Your address as the site sees it: %s.', Antispam::visitorIp($request->serverValues(), $proxy))) . ' '
            . e($country !== '' ? t('Country: %s.', $country) : t('The country of visitors is not known here – blocking countries works only behind Cloudflare or when the hosting sends the country.'))
            . ' ' . e(t('Behind Cloudflare, say so in Settings → General (The site runs behind).')) . '</p>'
            . ($invalid !== [] ? '<p class="notice notice-warning">' . e(t('These lines are not addresses and are ignored: %s', implode(', ', $invalid))) . '</p>' : '');

        $html .= '<h2>' . e(t('Blocked for a while')) . '</h2>';
        $blocks = Firewall::blocks($db);
        if ($blocks === []) {
            $html .= '<p>' . e(t('No address is blocked right now.')) . '</p>';
        } else {
            $html .= '<form method="post">' . $app->session->csrfField() . '<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col">' . e(t('Address')) . '</th><th scope="col">' . e(t('Why')) . '</th><th scope="col">' . e(t('Until')) . '</th><th scope="col"></th></tr></thead><tbody>';
            foreach ($blocks as $b) {
                $html .= '<tr><td>' . e($b['ip']) . '</td><td>' . e($reasons[$b['reason']] ?? $b['reason']) . '</td><td>' . e($date((string) $b['blocked_until'])) . '</td>'
                    . '<td><button class="navigation" type="submit" name="unblock" value="' . e($b['ip']) . '">' . e(t('Unblock')) . '</button></td></tr>';
            }
            $html .= '</tbody></table></div></form>';
        }

        $html .= '<h2>' . e(t('Refused requests')) . '</h2>';
        $log = Firewall::log($db);
        if ($log === []) {
            return $html . '<p>' . e(t('Nothing refused yet.')) . '</p>';
        }
        $html .= '<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col">' . e(t('When')) . '</th><th scope="col">' . e(t('Address')) . '</th><th scope="col">' . e(t('Why')) . '</th><th scope="col">' . e(t('Page')) . '</th></tr></thead><tbody>';
        foreach ($log as $l) {
            $html .= '<tr><td>' . e($date((string) $l['created_at'])) . '</td><td>' . e($l['ip']) . '</td><td>' . e($reasons[$l['reason']] ?? $l['reason']) . '</td><td>' . e($l['path']) . '</td></tr>';
        }

        return $html . '</tbody></table></div><p class="small-text">' . e(t('The last 50; the log keeps 30 days.')) . '</p>';
    }

    public function onEnable(Api $api): void
    {
        // the tables come from migrations/; nothing else to prepare
    }

    public function onUninstall(Api $api, bool $deleteData): void
    {
        // the tables and settings (ext.firewall.*) are removed by Talea with "delete data"; the request counters in storage/cache/limits expire on their own
    }
}
