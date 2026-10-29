<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Kernel;
use Talea\Admin\Module;
use Talea\Core\Response;
use Talea\Core\Sites as SiteRegistry;

/**
 * The owner's sites (Settings → Sites, only with TALEA_MULTISITE): create an empty site or a copy of one, name it, set its state, give
 * it host names, put it into the trash, and open it with the handoff. The module only writes rows: the tables of a new site are made
 * by cron (Core\Sites::provision), never on this request. Only administrators.
 */
final class Sites extends Module
{
    public const string IDENT = 'sites';
    public const string TABLE = 'sites';
    public const string NAME = 'Sites';
    public const string GROUP = 'Settings';
    public const string ICON = 'site';
    public const bool ADMIN_ONLY = true;

    public static function availableTo(\Talea\Core\App $app): bool
    {
        return SiteRegistry::enabled();
    }

    /**
     * The sidebar switcher: the name of the site you work on and the other sites you can open (set up, not trashed, with a host name).
     * null while the host is not routed to a site.
     *
     * @return array{current: string, others: list<array{public_id: string, name: string}>}|null
     */
    public static function switcher(\Talea\Core\App $app): ?array
    {
        if ($app->site === null) {
            return null;
        }
        $current = (int) $app->site['site_id'];
        $name = fn (array $s): string => $s['name'] !== '' ? (string) $s['name'] : (string) $s['slug'];
        $out = ['current' => '', 'others' => []];
        foreach (SiteRegistry::overview($app->db(), false) as $s) {
            if ((int) $s['site_id'] === $current) {
                $out['current'] = $name($s);
            } elseif ($s['ready'] && $s['status'] !== 'trashed' && $s['domains'] !== []) {
                $out['others'][] = ['public_id' => (string) $s['public_id'], 'name' => $name($s)];
            }
        }

        return $out;
    }

    /**
     * The sites an inbox list (Enquiries, Bookings) can span with "all sites": every set-up, not trashed site, the owner's first.
     * Empty unless multisite is on, the person is an administrator and there is more than one site.
     *
     * @return list<array{site_id: int, prefix: string, public_id: string, name: string, current: bool, can_open: bool}>
     */
    public static function inboxSites(\Talea\Core\App $app): array
    {
        if (!SiteRegistry::enabled() || !$app->auth()->isAdmin()) {
            return [];
        }
        $current = (int) ($app->site['site_id'] ?? 1);
        $out = [];
        foreach (SiteRegistry::overview($app->db(), false) as $s) {
            if ($s['ready'] && $s['status'] !== 'trashed') {
                $out[] = ['site_id' => (int) $s['site_id'], 'prefix' => (string) $s['prefix'], 'public_id' => (string) $s['public_id'], 'name' => $s['name'] !== '' ? (string) $s['name'] : (string) $s['slug'],
                    'current' => (int) $s['site_id'] === $current, 'can_open' => $s['domains'] !== []];
            }
        }

        return count($out) > 1 ? $out : [];
    }

    /** The "this site / all sites" select of an inbox list (null when there is only one site). */
    public static function inboxFilter(\Talea\Core\App $app, bool $all): ?string
    {
        if (self::inboxSites($app) === []) {
            return null;
        }

        return '<select name="sites" aria-label="' . e(t('Sites')) . '" data-submit-on-change><option value="">' . e(t('This site')) . '</option><option value="all"' . ($all ? ' selected' : '') . '>' . e(t('All sites')) . '</option></select>';
    }

    /** The site this request belongs to (1 when the host is not routed). */
    private function currentId(): int
    {
        return (int) ($this->app->site['site_id'] ?? 1);
    }

    protected function actionList(): Response
    {
        $sites = SiteRegistry::overview($this->db);

        return $this->view('list', 'Sites', ['sites' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] !== 'trashed')),
            'trash' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] === 'trashed')), 'current' => $this->currentId()]);
    }

    protected function actionNew(): Response
    {
        return $this->form('', '');
    }

    protected function actionCreate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $name = trim(mb_substr($this->request->post('name'), 0, 120));
        $from = $this->request->post('from');
        if ($name === '') {
            return $this->form($name, $from, 'Fill in the site name.');
        }
        $source = $this->currentId();
        if ($from !== '') {
            $row = $this->db->byPublicId('sites', $from);
            if ($row === null || $row['status'] === 'trashed' || $row['ready_at'] === null) {
                return $this->form($name, $from, 'Choose a site that is set up to copy.');
            }
            $source = (int) $row['site_id'];
        }
        SiteRegistry::add($this->db, $name, SiteRegistry::freeSlug($this->db, $name), $source, $from !== '');

        return $this->back('The site is created. It is being set up in the background and appears as ready within a few minutes.');
    }

    protected function actionEdit(): Response
    {
        $site = $this->site();

        return $site === null ? $this->error('The site does not exist.', 404) : $this->editForm($site);
    }

    protected function actionSave(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        $target = $this->request->post('redirect_to');
        $targetRow = $target === '' ? null : $this->db->byPublicId('sites', $target);
        $error = SiteRegistry::save($this->db, (int) $site['site_id'], $this->request->post('name'), $this->request->post('status'), $targetRow === null ? null : (int) $targetRow['site_id']);

        return $error === null ? $this->back('The site has been saved.', 'edit', ['id' => $site['public_id']]) : $this->back($error, 'edit', ['id' => $site['public_id']], 'error');
    }

    protected function actionTrash(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        if ((int) $site['site_id'] === $this->currentId()) {
            return $this->back('You are working on this site right now – open another one first.', '', [], 'error');
        }
        $error = SiteRegistry::trash($this->db, (int) $site['site_id']);

        return $error === null ? $this->back('The site is in the trash. It is deleted for good after 30 days.', '', [], 'ok') : $this->back($error, '', [], 'error');
    }

    protected function actionRestore(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            SiteRegistry::restore($this->db, (int) $site['site_id']);
        }

        return $this->back('The site is restored. It is paused until you set it live.');
    }

    protected function actionDomainAdd(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        $error = SiteRegistry::addDomain($this->db, (int) $site['site_id'], $this->request->post('host'));

        return $error === null ? $this->back('The host name is added.', 'edit', ['id' => $site['public_id']]) : $this->back($error, 'edit', ['id' => $site['public_id']], 'error');
    }

    protected function actionDomainRemove(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if (!$this->request->isPost() || $site === null || $domain === null) {
            return $this->back();
        }
        $error = SiteRegistry::removeDomain($this->db, (int) $site['site_id'], (int) $domain['domain_id'], SiteRegistry::requestHost($this->request));

        return $error === null ? $this->back('The host name is removed.', 'edit', ['id' => $site['public_id']]) : $this->back($error, 'edit', ['id' => $site['public_id']], 'error');
    }

    protected function actionDomainPrimary(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if ($this->request->isPost() && $site !== null && $domain !== null) {
            SiteRegistry::makePrimary($this->db, (int) $site['site_id'], (int) $domain['domain_id']);
        }

        return $this->back('The primary host name is changed.', 'edit', ['id' => (string) ($site['public_id'] ?? '')]);
    }

    protected function actionDomainRedirect(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if ($this->request->isPost() && $site !== null && $domain !== null) {
            SiteRegistry::setRedirect($this->db, (int) $site['site_id'], (int) $domain['domain_id'], $this->request->post('redirect') === '1');
        }

        return $this->back('The host name is saved.', 'edit', ['id' => (string) ($site['public_id'] ?? '')]);
    }

    /** Opens a site: a one-time token, then the browser goes to the primary host of that site, where Admin\Kernel signs the administrator in. */
    protected function actionHandoff(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        $host = SiteRegistry::primaryHost($this->db, (int) $site['site_id']);
        if ($site['status'] === 'trashed' || $site['ready_at'] === null || (int) $site['site_id'] === $this->currentId()) {
            return $this->back('This site cannot be opened now.', '', [], 'error');
        }
        if ($host === null) {
            return $this->back('Add a host name to this site first.', 'edit', ['id' => $site['public_id']], 'error');
        }
        $token = SiteRegistry::handoffToken($this->db, $this->app->auth()->id(), (int) $site['site_id']);

        // `to`: an administration address on that site to land on (an inbox row of another site), see SiteRegistry::landing()
        $to = SiteRegistry::landing($this->request->post('to'));

        return Response::redirect(SiteRegistry::originOn($this->request, $host) . $this->request->basePath() . '/' . Kernel::path('handoff=' . $token . ($to !== null ? '&to=' . rawurlencode($to) : '')), 303);
    }

    /** @return array<string, mixed>|null the site the id parameter names */
    private function site(): ?array
    {
        return $this->db->byPublicId('sites', $this->request->post('id') ?: $this->request->get('id'));
    }

    /** @return array<string, mixed>|null the host (parameter `host`) of a site, from its own list only */
    private function domain(int $siteId): ?array
    {
        return $this->db->one('SELECT * FROM {site_domains} WHERE site_id = ? AND host = ?', [$siteId, $this->request->post('host')]);
    }

    private function form(string $name, string $from, string $error = ''): Response
    {
        $ready = array_values(array_filter(SiteRegistry::overview($this->db), fn (array $s): bool => $s['ready'] && $s['status'] !== 'trashed'));

        return $this->view('form', 'New site', ['name' => $name, 'from' => $from, 'error' => $error, 'sites' => $ready]);
    }

    /** @param array<string, mixed> $site */
    private function editForm(array $site): Response
    {
        $overview = SiteRegistry::overview($this->db);
        $row = current(array_filter($overview, fn (array $s): bool => (int) $s['site_id'] === (int) $site['site_id']));

        return $this->view('edit', (string) ($site['name'] !== '' ? $site['name'] : $site['slug']), ['site' => $row, 'others' => array_values(array_filter($overview, fn (array $s): bool => $s['ready'] && $s['status'] !== 'trashed' && (int) $s['site_id'] !== (int) $site['site_id'])),
            'current' => $this->currentId(), 'host' => SiteRegistry::requestHost($this->request)]);
    }
}
