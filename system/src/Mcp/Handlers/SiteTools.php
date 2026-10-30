<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Core\Sites;

/**
 * MCP tools of the owner's sites (multisite, only with TALEA_MULTISITE, administrators only): the same things the Sites area does
 * (Admin\Modules\Sites) through Core\Sites. A site is named by its public id; prefixes and row numbers never leave the server. These
 * tools work on the owner's registry, so they take no `site` argument. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait SiteTools
{
    /**
     * Definitions of the site tools, in the shape of Tools::definitions() ([name, description, schema]).
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private static function siteDefinitions(\Closure $s, \Closure $text, \Closure $pid): array
    {
        return [
            ['list_owner_sites', 'The sites of this installation (multisite, administrators, read-only): id, name, status (live | paused | archived | trashed), whether it is set up, the host names with the primary one, and where an archived site redirects. Pass a site\'s id as the site argument of any other tool to work on that site instead of the one this connection calls.',
                $s([])],
            ['create_site', 'Creates a further site (multisite, administrators): empty, or a copy of another site with clone_of (its content and look, never its visitors\' data; operators of the installation may copy a site of another owner). It starts paused and is set up in the background within a few minutes; add a host name with add_site_domain and set it live with update_site.',
                $s(['name' => $text('name of the site'), 'clone_of' => $pid('site id from list_owner_sites to copy (omit for an empty site); an operator of the installation may also name a site of another owner, which is copied with its media, components and facts, nothing linked')], ['name'])],
            ['update_site', 'Renames a site, sets its state (live | paused | archived; an archived site may redirect to another with redirect_to) and its hub card (multisite, administrators): card_summary, card_image and show_in_hub decide what the Projects element shows about it on the other sites. Putting a site into the trash is trash_site.',
                $s(['id' => $pid('site id from list_owner_sites'), 'name' => $text('new name'), 'status' => $text('live | paused | archived'), 'redirect_to' => $pid('site id an archived site redirects to; empty = none'),
                    'card_summary' => $text('one or two sentences for the hub card (at most 300 characters)'), 'card_image' => $text('media path of the hub card image, e.g. media/2026/10/studio.jpg; empty = none'),
                    'show_in_hub' => ['type' => 'boolean', 'description' => 'true = the Projects element on the other sites lists this site (when it is live)']], ['id'])],
            ['trash_site', 'Puts a site into the trash (multisite, administrators, only on the user\'s explicit request): it stops answering, and its tables are deleted for good after 30 days; restore_site brings it back until then. Never the first site, nor the site this connection calls.',
                $s(['id' => $pid('site id from list_owner_sites')], ['id'])],
            ['restore_site', 'Takes a site out of the trash (multisite, administrators); it comes back paused.',
                $s(['id' => $pid('trashed site id from list_owner_sites')], ['id'])],
            ['add_site_domain', 'Gives a site a host name such as www.example.com (multisite, administrators; lower-case, no scheme, port or path). The first host of a site is its primary one. With several owners a host added by a client starts unverified and does not answer until it is verified: the result gives verify_with (a token address and a DNS TXT record); once the host points to the installation, call check_site_domain.',
                $s(['id' => $pid('site id from list_owner_sites'), 'host' => $text('host name'), 'primary' => ['type' => 'boolean', 'description' => 'true = make it the primary host name'],
                    'redirect_to_primary' => ['type' => 'boolean', 'description' => 'true = this host answers with a 301 to the primary host (not for the primary one)']], ['id', 'host'])],
            ['check_site_domain', 'Checks that a host name of a site points to this installation (multisite, administrators): it fetches the token address over HTTP, else looks for the DNS TXT record, and marks the host verified when one of them matches. Hosts that are already verified answer at once.',
                $s(['id' => $pid('site id from list_owner_sites'), 'host' => $text('one of the site\'s host names')], ['id', 'host'])],
            ['update_site_domain', 'Makes a host name of a site its primary one, or sets whether it redirects to the primary host (multisite, administrators).',
                $s(['id' => $pid('site id from list_owner_sites'), 'host' => $text('one of the site\'s host names'), 'primary' => ['type' => 'boolean', 'description' => 'true = make it the primary host name'],
                    'redirect_to_primary' => ['type' => 'boolean', 'description' => 'true = 301 to the primary host; false = answer as the site itself']], ['id', 'host'])],
            ['remove_site_domain', 'Removes a host name from a site (multisite, administrators, only on the user\'s explicit request); when it was the primary one, the oldest remaining host takes over. Not the host this connection uses.',
                $s(['id' => $pid('site id from list_owner_sites'), 'host' => $text('one of the site\'s host names')], ['id', 'host'])],
        ];
    }

    /** @return array<string, mixed> */
    private function siteOutput(array $site, bool $withDomains = true): array
    {
        $out = ['id' => (string) $site['public_id'], 'name' => (string) $site['name'], 'slug' => (string) $site['slug'], 'status' => (string) $site['status'],
            'ready' => $site['ready_at'] !== null, 'clone' => (bool) $site['clone'], 'current' => (int) $site['site_id'] === \Talea\Core\Sites::currentId($this->app->db()),
            'redirect_to' => $site['redirect_to_site_id'] === null ? null : (string) $this->app->db()->publicId('sites', (int) $site['redirect_to_site_id']),
            'card' => ['summary' => (string) $site['card_summary'], 'image' => (string) $site['card_image'], 'show_in_hub' => (bool) $site['show_in_hub']]];
        if ($withDomains) {
            $out['domains'] = array_map(fn (array $d): array => ['host' => (string) $d['host'], 'primary' => (bool) $d['is_primary'], 'redirect_to_primary' => (bool) $d['redirect_to_primary'], 'kind' => (string) $d['kind'], 'verified' => $d['verified_at'] !== null]
                + ($d['verified_at'] === null ? ['verify_with' => ['http' => 'http://' . $d['host'] . '/.well-known/talea-domain/' . $d['token'], 'dns_txt' => ['name' => '_talea.' . $d['host'], 'value' => \Talea\Core\Domains::txtValue((string) $d['token'])]]] : []), (array) ($site['domains'] ?? []));
        }

        return $out;
    }

    private function siteAdmin(): void
    {
        if (!Sites::enabled()) {
            throw new \DomainException('Multisite is not switched on for this installation.');
        }
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The sites of the owner are for administrators.');
        }
    }

    /** @return array<string, mixed> the row of a site by its public id; not a trashed one unless $trashed */
    private function siteByPublicId(mixed $id, bool $trashed = false): array
    {
        $site = is_string($id) && $id !== '' ? $this->app->db()->byPublicId('sites', $id) : null;
        if ($site === null || ($site['status'] === 'trashed') !== $trashed) {
            throw new \InvalidArgumentException($site === null ? 'No such site. list_owner_sites gives the ids.' : ($trashed ? 'This site is not in the trash.' : 'This site is in the trash – restore it first (restore_site).'));
        }

        return $site;
    }

    /** @return array<string, mixed> the Sites::overview() row of a site */
    private function siteRow(int $siteId): array
    {
        foreach (Sites::overview($this->app->db(), false) as $s) {
            if ((int) $s['site_id'] === $siteId) {
                return $s;
            }
        }
        throw new \DomainException('The site does not exist.');
    }

    /** list_owner_sites */
    private function toolListOwnerSites(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $sites = array_map(fn (array $s): array => $this->siteOutput($s), Sites::overview($this->app->db(), false));

        return ['sites' => $sites, 'next' => 'Pass a site\'s id as the site argument of any other tool to work on that site. create_site, update_site and add_site_domain manage the sites.'];
    }

    /** create_site */
    private function toolCreateSite(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $db = $this->app->db();
        $title = trim(mb_substr((string) ($a['name'] ?? ''), 0, 120));
        if ($title === '') {
            throw new \InvalidArgumentException('Give the site a name.');
        }
        $source = Sites::currentId($db);
        $clone = isset($a['clone_of']) && $a['clone_of'] !== '';
        $sourceOwner = '';
        if ($clone) {
            $foreign = $db->byPublicId('sites', (string) $a['clone_of']) === null && \Talea\Core\Owners::isOperator($db, $this->app->auth()->user()) ? \Talea\Core\Owners::findSite($db, (string) $a['clone_of']) : null;
            if ($foreign !== null) {
                // an operator copies a site of another owner: files, components and facts are copied, nothing is linked (Core\OwnerClone)
                $from = $foreign['site'];
                $sourceOwner = $foreign['owner'];
            } else {
                $from = $this->siteByPublicId($a['clone_of']);
            }
            if ($from['ready_at'] === null) {
                throw new \InvalidArgumentException('The site to copy is not set up yet.');
            }
            $source = (int) $from['site_id'];
        }
        $prefix = Sites::add($db, $title, Sites::freeSlug($db, $title), $source, $clone, $sourceOwner);
        $site = $db->one('SELECT * FROM {sites} WHERE prefix = ?', [$prefix]) ?? throw new \DomainException('The site could not be created.');

        return ['site' => $this->siteOutput($site + ['domains' => []]),
            'next' => 'The site is set up in the background within a few minutes (ready: true in list_owner_sites) and starts paused. Add a host name with add_site_domain, then set it live with update_site.'];
    }

    /** trash_site */
    private function toolTrashSite(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $db = $this->app->db();
        $site = $this->siteByPublicId($a['id'] ?? null);
        if ((int) $site['site_id'] === Sites::currentId($db) && (int) $site['site_id'] !== 1) {
            throw new \DomainException('This connection works on this site right now – use another site\'s host name or trash it from there.');
        }
        $error = Sites::trash($db, (int) $site['site_id']);
        if ($error !== null) {
            throw new \DomainException($error);
        }

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id'])), 'next' => 'The site is in the trash and is deleted for good after ' . Sites::TRASH_DAYS . ' days; restore_site brings it back.'];
    }

    /** restore_site */
    private function toolRestoreSite(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $site = $this->siteByPublicId($a['id'] ?? null, true);
        Sites::restore($this->app->db(), (int) $site['site_id']);

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id'])), 'next' => 'The site is back, paused.'];
    }

    /** update_site */
    private function toolUpdateSite(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $db = $this->app->db();
        $site = $this->siteByPublicId($a['id'] ?? null);
        $redirect = $site['redirect_to_site_id'] === null ? null : (int) $site['redirect_to_site_id'];
        if (array_key_exists('redirect_to', $a)) {
            $redirect = $a['redirect_to'] === null || $a['redirect_to'] === '' ? null : (int) $this->siteByPublicId($a['redirect_to'])['site_id'];
        }
        $error = Sites::save($db, (int) $site['site_id'], (string) ($a['name'] ?? $site['name']), (string) ($a['status'] ?? $site['status']), $redirect);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        if (isset($a['card_summary']) || isset($a['card_image']) || isset($a['show_in_hub'])) {
            $error = Sites::saveCard($db, (int) $site['site_id'], (string) ($a['card_summary'] ?? $site['card_summary']), (string) ($a['card_image'] ?? $site['card_image']), (bool) ($a['show_in_hub'] ?? $site['show_in_hub']));
            if ($error !== null) {
                throw new \InvalidArgumentException($error);
            }
        }

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id']))];
    }

    /** add_site_domain */
    private function toolAddSiteDomain(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $db = $this->app->db();
        $site = $this->siteByPublicId($a['id'] ?? null);
        $error = Sites::addDomain($db, (int) $site['site_id'], (string) ($a['host'] ?? ''), \Talea\Core\Domains::needsVerification($db, $this->app->auth()->user()));
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
        $this->applyDomainFlags($site, (string) Sites::normalizeHost((string) $a['host']), $a);

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id']))];
    }

    /** check_site_domain */
    private function toolCheckSiteDomain(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $db = $this->app->db();
        $site = $this->siteByPublicId($a['id'] ?? null);
        $domain = $db->one("SELECT * FROM {site_domains} WHERE site_id = ? AND host = ? AND kind = 'domain'", [(int) $site['site_id'], (string) Sites::normalizeHost((string) ($a['host'] ?? ''))]);
        if ($domain === null) {
            throw new \InvalidArgumentException('This site has no such host name. list_owner_sites shows them.');
        }
        $error = \Talea\Core\Domains::check($db, $domain);
        if ($error !== null) {
            throw new \DomainException($error);
        }

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id']))];
    }

    /** update_site_domain */
    private function toolUpdateSiteDomain(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $site = $this->siteByPublicId($a['id'] ?? null);
        $host = (string) Sites::normalizeHost((string) ($a['host'] ?? ''));
        if ($this->app->db()->value('SELECT 1 FROM {site_domains} WHERE site_id = ? AND host = ?', [(int) $site['site_id'], $host]) === null) {
            throw new \InvalidArgumentException('This site has no such host name. list_owner_sites shows them.');
        }
        $this->applyDomainFlags($site, $host, $a);

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id']))];
    }

    /** remove_site_domain */
    private function toolRemoveSiteDomain(string $name, array $a): mixed
    {
        $this->siteAdmin();
        $db = $this->app->db();
        $site = $this->siteByPublicId($a['id'] ?? null);
        $domain = $db->one('SELECT * FROM {site_domains} WHERE site_id = ? AND host = ?', [(int) $site['site_id'], (string) Sites::normalizeHost((string) ($a['host'] ?? ''))]);
        if ($domain === null) {
            throw new \InvalidArgumentException('This site has no such host name. list_owner_sites shows them.');
        }
        $error = Sites::removeDomain($db, (int) $site['site_id'], (int) $domain['domain_id'], Sites::requestHost($this->app->request));
        if ($error !== null) {
            throw new \DomainException($error);
        }

        return ['site' => $this->siteOutput($this->siteRow((int) $site['site_id']))];
    }

    /** primary / redirect_to_primary of add_site_domain and update_site_domain @param array<string, mixed> $site @param array<string, mixed> $a */
    private function applyDomainFlags(array $site, string $host, array $a): void
    {
        $db = $this->app->db();
        $domain = (array) $db->one('SELECT * FROM {site_domains} WHERE site_id = ? AND host = ?', [(int) $site['site_id'], $host]);
        if (($a['primary'] ?? false) === true) {
            Sites::makePrimary($db, (int) $site['site_id'], (int) $domain['domain_id']);
        }
        if (isset($a['redirect_to_primary'])) {
            if (($a['redirect_to_primary'] === true) && (bool) ((array) $db->one('SELECT is_primary FROM {site_domains} WHERE domain_id = ?', [(int) $domain['domain_id']]))['is_primary']) {
                throw new \InvalidArgumentException('The primary host name cannot redirect to itself.');
            }
            Sites::setRedirect($db, (int) $site['site_id'], (int) $domain['domain_id'], $a['redirect_to_primary'] === true);
        }
    }
}
