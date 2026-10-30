<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Kernel;
use Talea\Admin\Module;
use Talea\Admin\ChangeLog;
use Talea\Core\Antispam;
use Talea\Core\Domains;
use Talea\Core\Owners as OwnerRegistry;
use Talea\Core\Response;
use Talea\Core\SiteHealth;
use Talea\Core\SiteImport;
use Talea\Core\SiteImports;
use Talea\Core\SiteFigures;
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
        $user = $app->auth()->user();
        foreach (SiteRegistry::overview($app->db(), false) as $s) {
            if ((int) $s['site_id'] === $current) {
                $out['current'] = $name($s);
            } elseif ($s['ready'] && $s['status'] !== 'trashed' && $s['domains'] !== [] && SiteRegistry::allows($app->db(), $user, (int) $s['site_id'])) {
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
        $user = $app->auth()->user();
        foreach (SiteRegistry::overview($app->db(), false) as $s) {
            if ($s['ready'] && $s['status'] !== 'trashed' && SiteRegistry::allows($app->db(), $user, (int) $s['site_id'])) {
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
        foreach ($sites as &$s) {
            $s['health'] = $s['ready'] && $s['status'] !== 'trashed' ? SiteHealth::site($this->db->forSite((string) $s['prefix'])) : null;
        }
        unset($s);

        return $this->view('list', 'Sites', ['sites' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] !== 'trashed')),
            'trash' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] === 'trashed')), 'current' => $this->currentId(),
            'mediaWithoutAlt' => SiteHealth::mediaWithoutAlt($this->db), 'limit' => SiteRegistry::limit('MAX_SITES')]);
    }

    /** The usage of this owner's sites per month as a CSV (administrators; an operator gets every owner's in the Owners area). */
    protected function actionUsage(): Response
    {
        $csv = SiteHealth::usageCsv($this->db, $this->request->getInt('months'));
        ChangeLog::write($this->app, 'sites', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="usage-' . date('Y-m-d') . '.csv"']);
    }

    /** The Overview tab: the figures of every set-up site for 7 and 30 days, one row each, and the totals. */
    protected function actionOverview(): Response
    {
        return $this->view('overview', 'Sites', ['figures' => SiteFigures::overview($this->db, $this->app->auth()->user()), 'periods' => SiteFigures::PERIODS]);
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
        if (($limit = SiteRegistry::limitRefusal($this->db)) !== null) {
            return $this->form($name, $from, $limit);
        }
        if ($name === '') {
            return $this->form($name, $from, 'Fill in the site name.');
        }
        $source = $this->currentId();
        if ($from !== '') {
            $row = $this->db->byPublicId('sites', $from); // this owner's sites only: copying across owners is the operators' tool in the Owners area
            if ($row === null || $row['status'] === 'trashed' || $row['ready_at'] === null) {
                return $this->form($name, $from, 'Choose a site that is set up to copy.');
            }
            $source = (int) $row['site_id'];
        }
        SiteRegistry::add($this->db, $name, SiteRegistry::freeSlug($this->db, $name), $source, $from !== '');

        return $this->back('The site is created. It is being set up in the background and appears as ready within a few minutes.');
    }

    /** Import a Talea archive (an export of an older single-site install) as a NEW site: the row and the file are all this request writes, cron imports it. */
    protected function actionImport(): Response
    {
        if (!$this->request->isPost()) {
            return $this->importForm('', '');
        }
        $name = trim(mb_substr($this->request->post('name'), 0, 120));
        $existing = $this->request->post('existing');
        $refusal = SiteRegistry::limitRefusal($this->db) ?? ($name === '' ? 'Fill in the site name.' : null);
        if ($refusal !== null) {
            return $this->importForm($name, $existing, $refusal);
        }
        try {
            self::importArchive($this->db, $this->request, $name, (int) $this->app->auth()->id(), OwnerRegistry::isFirst($this->db));
        } catch (\RuntimeException $e) {
            return $this->importForm($name, $existing, $e->getMessage());
        }
        ChangeLog::write($this->app, 'sites', 'import', $name);

        return $this->back('The archive is being imported in the background. The site appears as ready when it is done; the other sites keep working meanwhile.');
    }

    /**
     * Makes a site of $owner from the uploaded archive (field `file`) or, where the installation's own import folder is the person's (the first
     * owner), from a file already there (field `existing`).
     *
     * @throws \RuntimeException
     */
    public static function importArchive(\Talea\Core\Db $owner, \Talea\Core\Request $request, string $name, int $admin, bool $fromFolder): void
    {
        $upload = $request->file('file');
        if ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $upload['tmp_name'])) {
            SiteImports::create($owner, $name, (string) $upload['tmp_name'], (string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION), $admin, move_uploaded_file(...));

            return;
        }
        $path = $fromFolder ? SiteImport::path($request->post('existing')) : null;
        if ($path === null) {
            throw new \RuntimeException('The file could not be uploaded. If it is larger than the server allows, upload it over FTP into the storage/import/ folder.');
        }
        SiteImports::create($owner, $name, $path, (string) pathinfo($path, PATHINFO_EXTENSION), $admin, rename(...));
    }

    private function importForm(string $name, string $existing, string $error = ''): Response
    {
        return $this->view('import', 'Import a Talea archive', ['name' => $name, 'existing' => $existing, 'error' => $error, 'files' => OwnerRegistry::isFirst($this->db) ? SiteImport::listAll() : [],
            'uploadLimit' => min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')))]);
    }

    /** "8M" from php.ini to bytes. */
    public static function bytes(string $value): int
    {
        $number = (int) $value;

        return $number <= 0 ? PHP_INT_MAX : $number * match (strtolower(substr(trim($value), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
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
        $error = SiteRegistry::save($this->db, (int) $site['site_id'], $this->request->post('name'), $this->request->post('status'), $targetRow === null ? null : (int) $targetRow['site_id'])
            ?? SiteRegistry::saveCard($this->db, (int) $site['site_id'], $this->request->post('card_summary'), $this->request->post('card_image'), $this->request->postBool('show_in_hub'));

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
        $error = $this->request->isPost() && $site !== null ? SiteRegistry::restore($this->db, (int) $site['site_id']) : null;

        return $error !== null ? $this->back($error, '', [], 'error') : $this->back('The site is restored. It is paused until you set it live.');
    }

    protected function actionDomainAdd(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        $verify = Domains::needsVerification($this->db, $this->app->auth()->user());
        $error = SiteRegistry::addDomain($this->db, (int) $site['site_id'], $this->request->post('host'), $verify);

        return $error === null ? $this->back($verify ? 'The host name is added. It answers once it is verified: follow the steps next to it, then choose Check now.' : 'The host name is added.', 'edit', ['id' => $site['public_id']]) : $this->back($error, 'edit', ['id' => $site['public_id']], 'error');
    }

    /** Checks a host that is not verified yet (the token file over HTTP, else the DNS record); the request leaves through the safe outbound path. */
    protected function actionDomainCheck(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if (!$this->request->isPost() || $site === null || $domain === null) {
            return $this->back();
        }
        $error = Domains::check($this->db, $domain);

        return $error === null ? $this->back('The host name is verified and answers now.', 'edit', ['id' => $site['public_id']]) : $this->back($error, 'edit', ['id' => $site['public_id']], 'error');
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
        $token = SiteRegistry::handoffToken($this->db, $this->app->auth()->id(), (int) $site['site_id'], Antispam::visitorKey($this->request, $this->app->settings()));

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
        return $this->db->one("SELECT * FROM {site_domains} WHERE site_id = ? AND host = ? AND kind = 'domain'", [$siteId, $this->request->post('host')]);
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
            'current' => $this->currentId(), 'host' => SiteRegistry::requestHost($this->request), 'dnsOnly' => Domains::dnsOnly($this->db)]);
    }
}
