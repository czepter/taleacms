<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Fleet\Console;
use Talea\Fleet\Expiry;
use Talea\Fleet\Kit;

/**
 * MCP tools of a fleet console (2.9, extension "fleet"): the sites that report to it – read-only. Claude changes a site
 * through that site's own connection; the console holds no access to the sites. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait FleetTools
{
    /** list_sites */
    private function toolListSites(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The fleet console is for administrators.');
        }
        $sites = Console::overview($this->app->db(), time());
        if (($a['attention_only'] ?? false) === true) {
            $sites = array_values(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings']));
        }

        $accepted = fn (array $items): array => array_values(array_map(fn (array $i): array => ['check' => $i['key'], 'note' => $i['accepted']['note'], 'until' => $i['accepted']['until']],
            array_filter($items, fn (array $i): bool => $i['accepted'] !== null)));

        return [
            'sites' => array_map(fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'url' => (string) $s['url'], 'version' => (string) $s['version'],
                'attention' => $s['reasons'], 'accepted' => $accepted($s['items']), 'score' => $s['score'], 'last_report' => $s['last_seen'], 'up' => $s['up'] === null ? null : (int) $s['up'] === 1,
                'certificate_expires' => $s['cert_expires'] ?? null, 'domain_expires' => $s['domain_expires'] ?? null,
                'enquiries_waiting' => $s['beat']['enquiries_unanswered'] ?? null,
                'kit_version' => $s['beat']['kit_version'] ?? null], $sites),
            'console_version' => TALEA_VERSION, // sites update by pulling a new image; a site behind this version has not been updated yet
            // the shared design kit (2.16, Fleet\Kit): the newest version the console published; kit_version says which one each site applied
            'kit' => ($kit = Kit::latest($this->app->db())) !== null ? ['version' => $kit['version'], 'published' => $kit['created_at'], 'contents' => $kit['summary']] : null,
            'next' => 'get_site for the full report of one site. To change something on a site, use that site\'s own Claude connection – the console has no access to the sites.',
        ];
    }

    /** get_site */
    private function toolGetSite(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The fleet console is for administrators.');
        }
        $site = $this->app->db()->one('SELECT * FROM {fleet_sites} WHERE id = ?', [(int) ($a['id'] ?? 0)]);
        if ($site === null) {
            throw new \DomainException('The site is not in the console. Use list_sites.');
        }
        $attention = Console::attention($site, time());
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);

        return ['id' => (int) $site['id'], 'name' => (string) $site['name'], 'url' => (string) $site['url'], 'attention' => $attention['reasons'], 'score' => $attention['score'],
            'checks' => array_map(fn (array $i): array => ['check' => $i['key'], 'group' => $i['group'], 'severity' => $i['severity'], 'title' => $i['title'], 'detail' => $i['detail'],
                'accepted' => $i['accepted'] === null ? null : ['note' => $i['accepted']['note'], 'until' => $i['accepted']['until'], 'by' => $i['accepted']['by'] ?? '', 'at' => $i['accepted']['at'] ?? '']], $attention['items']),
            'accepted' => array_values(array_map(fn (array $i): array => ['check' => $i['key'], 'note' => $i['accepted']['note'], 'until' => $i['accepted']['until']], array_filter($attention['items'], fn (array $i): bool => $i['accepted'] !== null))),
            'up' => $site['up'] === null ? null : (int) $site['up'] === 1, 'up_checked' => $site['up_checked'], 'last_report' => $site['last_seen'], 'paired' => $site['paired_at'],
            'certificate_expires' => $site['cert_expires'] ?? null, 'domain_expires' => $site['domain_expires'] ?? null,
            'kit_version' => is_array($beat) ? ($beat['kit_version'] ?? null) : null, 'report' => is_array($beat) ? $beat : null];
    }

    /** fleet_calendar */
    private function toolFleetCalendar(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The fleet console is for administrators.');
        }
        $days = max(1, min(730, (int) ($a['days'] ?? 365)));

        return [
            'dates' => array_values(array_map(fn (array $d): array => ['date' => $d['date'], 'kind' => $d['kind'], 'days_left' => $d['days'], 'site_id' => (int) $d['site']['id'],
                'site_name' => (string) $d['site']['name'], 'url' => (string) $d['site']['url']],
                array_filter(Expiry::dates(Console::sites($this->app->db()), time()), fn (array $d): bool => $d['days'] <= $days))),
            'certificate_reminder_days' => Expiry::CERTIFICATE_DAYS,
            'domain_reminder_days' => Expiry::DOMAIN_DAYS,
        ];
    }
}
