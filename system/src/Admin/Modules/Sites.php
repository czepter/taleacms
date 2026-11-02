<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Kernel;
use Talea\Admin\Module;
use Talea\Admin\ChangeLog;
use Talea\Core\Antispam;
use Talea\Core\Background;
use Talea\Core\Backup;
use Talea\Core\Domains;
use Talea\Core\LookFamily;
use Talea\Core\Owners as OwnerRegistry;
use Talea\Core\Response;
use Talea\Core\SiteHealth;
use Talea\Core\SiteExport;
use Talea\Core\SiteImport;
use Talea\Core\SiteImports;
use Talea\Core\SiteFigures;
use Talea\Core\Sites as SiteRegistry;
use Talea\Core\WpFile;

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
        $agency = \Talea\Core\Deployment::atLeast('agency');
        $operator = $agency && OwnerRegistry::isOperator($this->db, $this->app->auth()->user());
        // an operator walks every set-up owner (filter ?owner=<public id>); everybody else sees the owner they work in
        $owners = [['prefix' => $this->db->ownerPrefix, 'public_id' => '']];
        if ($operator) {
            $owners = array_values(array_filter(OwnerRegistry::all($this->db), fn (array $o): bool => $o['ready_at'] !== null));
        }
        $ownerFilter = $operator ? $this->request->get('owner') : '';
        $search = mb_strtolower(trim($this->request->get('search')));
        $sites = [];
        $ownerNames = [];
        foreach ($owners as $o) {
            $own = (string) $o['prefix'] === $this->db->ownerPrefix;
            $db = $own ? $this->db : $this->db->forOwner((string) $o['prefix']);
            $name = OwnerRegistry::nameOf($this->db, (string) $o['prefix']);
            if ($name === (string) $o['prefix'] && $name === $this->db->installPrefix) {
                $name = t('Main owner'); // an unnamed first owner is not shown by its table prefix
            }
            $ownerNames[(string) $o['public_id']] = $name;
            if ($ownerFilter !== '' && $ownerFilter !== (string) $o['public_id']) {
                continue;
            }
            $backup = SiteHealth::lastBackup($db);
            foreach (SiteRegistry::overview($db) as $s) {
                if ($search !== '' && !str_contains(mb_strtolower($s['name'] . ' ' . $s['slug'] . ' ' . implode(' ', array_column($s['domains'], 'host'))), $search)) {
                    continue;
                }
                $s['health'] = $s['ready'] && $s['status'] !== 'trashed' ? SiteHealth::site($db->forSite((string) $s['prefix'])) : null;
                $s['owner_name'] = $name;
                $s['own'] = $own;
                $s['unverified'] = count(array_filter($s['domains'], fn (array $d): bool => $d['verified_at'] === null));
                $s['backup'] = $backup;
                $sites[] = $s;
            }
        }

        return $this->view('list', 'Sites', ['sites' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] !== 'trashed')),
            'trash' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] === 'trashed' && $s['own'])), 'current' => $this->currentId(),
            'mediaWithoutAlt' => SiteHealth::mediaWithoutAlt($this->db), 'limit' => SiteRegistry::limit('MAX_SITES'),
            'agency' => $agency, 'operator' => $operator, 'ownerNames' => $ownerNames, 'ownerFilter' => $ownerFilter, 'search' => $this->request->get('search'),
            'siteCount' => SiteRegistry::installCount($this->db), 'ownerCount' => $agency ? count(OwnerRegistry::all($this->db)) : 0, 'ownerLimit' => SiteRegistry::limit('MAX_OWNERS')]);
    }

    /** Archives a site from the list's row menu (the name stays; a redirect target is set on the edit page). */
    protected function actionArchive(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        if ((int) $site['site_id'] === $this->currentId()) {
            return $this->back('You are working on this site right now – open another one first.', '', [], 'error');
        }
        $error = SiteRegistry::save($this->db, (int) $site['site_id'], (string) ($site['name'] !== '' ? $site['name'] : $site['slug']), 'archived', null);

        return $error === null ? $this->back('The site has been saved.', '', [], 'ok') : $this->back($error, '', [], 'error');
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
        return $this->form('', $this->request->get('from') === 'file' ? 'file' : '');
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
        if ($from === 'file') {
            try {
                $prefix = self::importArchive($this->db, $this->request, $name, (int) $this->app->auth()->id(), OwnerRegistry::isFirst($this->db));
            } catch (\RuntimeException $e) {
                return $this->form($name, $from, $e->getMessage());
            }
            ChangeLog::write($this->app, 'sites', 'import', $name);
            OwnerRegistry::isFirst($this->db) && Background::start('provision'); // starts now; where that is not possible the next cron run does it

            return $this->back('The archive is being imported in the background. The other sites keep working meanwhile.', 'progress', ['id' => (string) $this->db->value('SELECT public_id FROM {sites} WHERE prefix = ?', [$prefix])]);
        }
        $parent = null;
        if ($this->request->post('look_parent') !== '') {
            [$parent, $error] = LookFamily::checkParent($this->db, $this->request->post('look_parent'), null);
            if ($error !== null) {
                return $this->form($name, $from, $error, $this->request->post('look_parent'));
            }
        }
        $source = $this->currentId();
        if ($from !== '') {
            $row = $this->db->byPublicId('sites', $from); // this owner's sites only: copying across owners is the operators' tool in the Owners area
            if ($row === null || $row['status'] === 'trashed' || $row['ready_at'] === null) {
                return $this->form($name, $from, 'Choose a site that is set up to copy.');
            }
            $source = (int) $row['site_id'];
        }
        SiteRegistry::add($this->db, $name, SiteRegistry::freeSlug($this->db, $name), $source, $from !== '', '', (int) ($parent['site_id'] ?? 0));

        return $this->back('The site is created. It is being set up in the background and appears as ready within a few minutes.');
    }

    /** The old address of the import form: the new-site page with "Import from a file" chosen. */
    protected function actionImport(): Response
    {
        return $this->back('', 'new', ['from' => 'file']);
    }

    /**
     * Makes a site of $owner from the uploaded archive (field `file`) or, where the installation's own import folder is the person's (the first
     * owner), from a file already there (field `existing`).
     *
     * @return string the table prefix of the new site
     * @throws \RuntimeException
     */
    public static function importArchive(\Talea\Core\Db $owner, \Talea\Core\Request $request, string $name, int $admin, bool $fromFolder): string
    {
        $upload = $request->file('file');
        if ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $upload['tmp_name'])) {
            self::requireArchive((string) $upload['tmp_name']);

            return SiteImports::create($owner, $name, (string) $upload['tmp_name'], (string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION), $admin, move_uploaded_file(...));
        }
        $path = $fromFolder ? SiteImport::path($request->post('existing')) : null;
        if ($path === null) {
            throw new \RuntimeException('The file could not be uploaded. If it is larger than the server allows, upload it over FTP into the storage/import/ folder.');
        }
        self::requireArchive($path);

        return SiteImports::create($owner, $name, $path, (string) pathinfo($path, PATHINFO_EXTENSION), $admin, rename(...));
    }

    /** The same detection as Import and export: a new site starts from a Talea archive only. @throws \RuntimeException */
    private static function requireArchive(string $path): void
    {
        if (Transfer::kind($path) !== 'talea') {
            throw new \RuntimeException('This is a WordPress export. Import it into a site under Import and export; a new site starts from a Talea export.');
        }
    }

    /** "8M" from php.ini to bytes. */
    public static function bytes(string $value): int
    {
        $number = (int) $value;

        return $number <= 0 ? PHP_INT_MAX : $number * match (strtolower(substr(trim($value), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }

    /* ---------- a client's own backups and exports (docs/specs/multisite.md, "Bringing existing sites in") ---------- */

    /** The owner's own files exist for a further owner only: the first owner's are the installation's (Settings → Backups, Import and export). */
    private function ownFiles(): ?Response
    {
        return OwnerRegistry::isFirst($this->db) || \Talea\Core\Backup::refusedInDemo() ? $this->error('The page does not exist.', 404) : null;
    }

    /** The owner's backups (all its sites in one file) and the exports of its sites. */
    protected function actionFiles(): Response
    {
        return $this->ownFiles() ?? $this->view('files', 'Backups and exports', ['backups' => Backup::listForOwner($this->db), 'exports' => SiteExport::listAll($this->db), 'hasZip' => class_exists(\ZipArchive::class)]);
    }

    protected function actionBackupCreate(): Response
    {
        if (($refused = $this->ownFiles()) !== null || !$this->request->isPost()) {
            return $refused ?? $this->back();
        }
        try {
            $file = Backup::createForOwner($this->db);
        } catch (\Throwable $e) {
            return $this->back(t('The backup could not be created: %s', t($e->getMessage())), 'files', [], 'error');
        }
        ChangeLog::write($this->app, 'sites', 'backup', $file);

        return $this->back(t('Backup %s is ready.', $file), 'files');
    }

    protected function actionBackupDownload(): Response
    {
        $path = $this->ownFiles() ?? Backup::pathForOwner($this->db, $this->request->get('file'));
        if (!is_string($path)) {
            return $path ?? $this->error('Backup does not exist.', 404);
        }
        $this->sendFile($path, basename($path));
    }

    protected function actionBackupDelete(): Response
    {
        if (($refused = $this->ownFiles()) !== null) {
            return $refused;
        }
        $path = Backup::pathForOwner($this->db, $this->request->post('file'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('Backup deleted.', 'files');
    }

    /** Puts this owner's tables (all its sites) back from one of its backups; the state before is saved as a new backup first, and comes back if the restore fails. */
    protected function actionBackupRestore(): Response
    {
        if (($refused = $this->ownFiles()) !== null || !$this->request->isPost()) {
            return $refused ?? $this->back('', 'files');
        }
        try {
            $safety = Backup::createForOwner($this->db, 'before_restore');
            $count = Backup::restoreForOwner($this->db, $this->request->post('file'));
        } catch (\Throwable $e) {
            $reverted = false;
            if (isset($safety)) {
                try {
                    Backup::restoreForOwner($this->db, $safety);
                    $reverted = true;
                } catch (\Throwable) {
                    // the revert failed – the state before is in the backup $safety
                }
            }
            \Talea\Front\Cache::clearAll();

            return $this->back(t('Restore failed: %s', t($e->getMessage())) . ' ' . ($reverted ? t('The database is back in its state before the restore.') : (isset($safety) ? t('The state before the restore is in backup %s – please restore it.', $safety) : '')), 'files', [], 'error');
        }
        \Talea\Front\Cache::clearAll();

        return $this->back(t('The sites have been restored from the backup (statements: %d). The state before the restore is saved in backup %s.', $count, $safety), 'files');
    }

    /** An open-format export of THIS site (pages, news, media of the owner\'s folder …) that another Talea imports. */
    protected function actionExportCreate(): Response
    {
        if (($refused = $this->ownFiles()) !== null || !$this->request->isPost()) {
            return $refused ?? $this->back();
        }
        try {
            $result = SiteExport::create($this->db, $this->app->settings());
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), 'files', [], 'error');
        }
        if ($result['reason'] !== '') {
            $this->app->session->flash('info', $result['reason']);
        }
        ChangeLog::write($this->app, 'sites', 'export', $result['file']);

        return $this->back('The export is ready – download it from the list below.', 'files');
    }

    protected function actionExportDownload(): Response
    {
        $path = $this->ownFiles() ?? SiteExport::path($this->request->get('file'), $this->db);
        if (!is_string($path)) {
            return $path ?? $this->error('The export does not exist.', 404);
        }
        $this->sendFile($path, basename($path));
    }

    protected function actionExportDelete(): Response
    {
        if (($refused = $this->ownFiles()) !== null) {
            return $refused;
        }
        $path = SiteExport::path($this->request->post('file'), $this->db);
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('The export has been deleted.', 'files');
    }

    protected function actionEdit(): Response
    {
        $site = $this->site();
        if ($site !== null && $site['ready_at'] === null && $site['import_file'] !== '' && $site['import_error'] === '') {
            return $this->back('', 'progress', ['id' => $site['public_id']]); // an import in progress has its own screen
        }

        return $site === null ? $this->error('The site does not exist.', 404) : $this->editForm($site);
    }

    /** What the import of a new site is doing: the steps, the numbers and a bar; the script of the page asks `status` until the site is ready or the import stopped. */
    protected function actionProgress(): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->error('The site does not exist.', 404);
        }
        $status = $this->importStatus($site);
        if ($status['state'] === 'ready' || $status['state'] === 'other') {
            return $this->back($status['state'] === 'ready' ? 'The site is ready.' : '', 'edit', ['id' => $site['public_id']]);
        }

        return $this->view('progress', 'Importing a site', ['site' => $site, 'status' => $status, 'statusUrl' => $this->url('status', ['id' => $site['public_id']])]);
    }

    /** The same as JSON, for the script of the progress screen. */
    protected function actionStatus(): Response
    {
        $site = $this->site();

        return $site === null ? Response::json(['state' => 'failed', 'error' => t('The site does not exist.')], 404) : Response::json($this->importStatus($site) + ['next' => $this->url('edit', ['id' => $site['public_id']])]);
    }

    /**
     * @param array<string, mixed> $site
     * @return array{state: string, percent: int, steps: list<array{label: string, state: string}>, error: string, waiting: string}
     *         state: running | ready | failed | other (not an import: the site is being set up); waiting: '' | starting | stale
     */
    private function importStatus(array $site): array
    {
        $status = ['state' => 'running', 'percent' => 0, 'steps' => [], 'error' => '', 'waiting' => ''];
        if ($site['ready_at'] !== null) {
            return ['state' => 'ready'] + $status;
        }
        $progress = SiteImports::progress($site);
        if ($site['import_error'] !== '' || ($site['import_file'] !== '' && $progress === null)) {
            return ['state' => 'failed', 'error' => $site['import_error'] !== '' ? (string) $site['import_error'] : t('The archive is gone – create the site again from the export.')] + $status;
        }
        if ($progress === null) {
            return ['state' => 'other'] + $status;
        }
        $at = ['queued' => 0, 'preparing' => 0, 'preview' => 0, 'data' => 1, 'media' => 2, 'done' => 3][$progress['phase']] ?? 0;
        $labels = [
            t('Reading the archive'),
            $at >= 1 ? t('Importing pages, news and settings (%d of %d)', $progress['rows_done'], $progress['rows']) : t('Importing pages, news and settings'),
            $at >= 2 && $progress['media'] > 0 ? t('Copying images and files (%d of %d)', $progress['media_done'], $progress['media']) : t('Copying images and files'),
            t('Finishing'),
        ];
        $status['steps'] = array_map(static fn (string $label, int $i): array => ['label' => $label, 'state' => $i < $at ? 'finished' : ($i === $at ? 'active' : '')], $labels, array_keys($labels));
        $status['percent'] = $progress['percent'];
        $status['waiting'] = $at > 0 ? '' : (time() - (int) $this->app->settings()->get('tasks_last_run') > 900 && time() - strtotime((string) $site['created_at']) > 900 ? 'stale' : 'starting');

        return $status;
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

    /** Empties the site you are working on: an export first, then every table of the site; shared media, components and facts stay. */
    protected function actionEmpty(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back();
        }
        $edit = ['id' => $site['public_id']];
        if ((int) $site['site_id'] === 1) {
            return $this->back('The first site cannot be emptied.', 'edit', $edit, 'error');
        }
        if ((int) $site['site_id'] !== $this->currentId()) {
            return $this->back('Open this site first, then empty it from its own administration.', 'edit', $edit, 'error');
        }
        if (trim($this->request->post('confirm_name')) !== (string) ($site['name'] !== '' ? $site['name'] : $site['slug'])) {
            return $this->back('The name does not match. Nothing was emptied.', 'edit', $edit, 'error');
        }
        try {
            $export = SiteExport::create($this->db, $this->app->settings());
        } catch (\RuntimeException) {
            return $this->back('Nothing was emptied: the export before it could not be made.', 'edit', $edit, 'error');
        }
        $settings = $this->request->postBool('reset_settings');
        SiteRegistry::emptyContent($this->db, $settings);
        WpFile::clear();
        ChangeLog::write($this->app, 'sites', 'empty', $export['file']);

        return $this->back($settings ? 'The site is empty and its settings are reset. The export made before is in Backups and exports.' : 'The site is empty. The export made before is in Backups and exports.', 'edit', $edit, 'ok');
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

        return $error === null ? $this->domainBack($verify ? 'The host name is added. It answers once it is verified: follow the steps next to it, then choose Check now.' : 'The host name is added.', $site['public_id']) : $this->domainBack($error, $site['public_id'], 'error');
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

        return $error === null ? $this->domainBack('The host name is verified and answers now.', $site['public_id']) : $this->domainBack($error, $site['public_id'], 'error');
    }

    protected function actionDomainRemove(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if (!$this->request->isPost() || $site === null || $domain === null) {
            return $this->back();
        }
        $error = SiteRegistry::removeDomain($this->db, (int) $site['site_id'], (int) $domain['domain_id'], SiteRegistry::requestHost($this->request));

        return $error === null ? $this->domainBack('The host name is removed.', $site['public_id']) : $this->domainBack($error, $site['public_id'], 'error');
    }

    protected function actionDomainPrimary(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if ($this->request->isPost() && $site !== null && $domain !== null) {
            SiteRegistry::makePrimary($this->db, (int) $site['site_id'], (int) $domain['domain_id']);
        }

        return $this->domainBack('The primary host name is changed.', (string) ($site['public_id'] ?? ''));
    }

    protected function actionDomainRedirect(): Response
    {
        $site = $this->site();
        $domain = $site === null ? null : $this->domain((int) $site['site_id']);
        if ($this->request->isPost() && $site !== null && $domain !== null) {
            SiteRegistry::setRedirect($this->db, (int) $site['site_id'], (int) $domain['domain_id'], $this->request->post('redirect') === '1');
        }

        return $this->domainBack('The host name is saved.', (string) ($site['public_id'] ?? ''));
    }

    /** Back to the site's page, or to Settings → Address and visibility when the form came from there. */
    private function domainBack(string $message, string $id, string $type = 'ok'): Response
    {
        if ($this->request->post('return') !== 'address') {
            return $this->back($message, 'edit', ['id' => $id], $type);
        }
        $this->app->session->flash($type, $message);

        return Response::redirect($this->app->url('admin.php?module=settings&tab=address'));
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
        $site = $this->db->byPublicId('sites', $this->request->post('id') ?: $this->request->get('id'));

        return $site !== null && $site['transfer_by'] === null ? $site : null; // the new site of a move is not the owner's to edit
    }

    /** @return array<string, mixed>|null the host (parameter `host`) of a site, from its own list only */
    private function domain(int $siteId): ?array
    {
        return $this->db->one("SELECT * FROM {site_domains} WHERE site_id = ? AND host = ? AND kind = 'domain'", [$siteId, $this->request->post('host')]);
    }

    private function form(string $name, string $from, string $error = '', string $parent = ''): Response
    {
        $ready = array_values(array_filter(SiteRegistry::overview($this->db), fn (array $s): bool => $s['ready'] && $s['status'] !== 'trashed'));

        return $this->view('form', 'New site', ['files' => OwnerRegistry::isFirst($this->db) ? SiteImport::listAll() : [], 'existing' => $this->request->post('existing'),
            'uploadLimit' => min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))), 'name' => $name, 'from' => $from, 'error' => $error, 'sites' => $ready, 'parent' => $parent, 'parents' => self::familyParents($ready, null)]);
    }

    /**
     * The sites a site can take its look from: set up, not in the trash, not a child themselves, and not the site itself. A site that is a
     * parent cannot become a child, so it is offered none.
     *
     * @param list<array<string, mixed>> $sites
     * @return list<array<string, mixed>>
     */
    private static function familyParents(array $sites, ?array $site): array
    {
        if ($site !== null && array_filter($sites, fn (array $s): bool => (int) $s['look_parent_id'] === (int) $site['site_id']) !== []) {
            return [];
        }

        return array_values(array_filter($sites, fn (array $s): bool => $s['ready'] && $s['status'] !== 'trashed' && $s['look_parent_id'] === null && (int) $s['site_id'] !== (int) ($site['site_id'] ?? 0)));
    }

    /** Join the look family of a parent site, or leave it (empty parent): docs/specs/multisite.md, "Look families". */
    protected function actionLookParent(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null || $site['status'] === 'trashed') {
            return $this->back();
        }
        $back = fn (string $message, string $type = 'ok'): Response => $this->back($message, 'edit', ['id' => $site['public_id']], $type);
        $parent = null;
        if ($this->request->post('parent') !== '') {
            [$parent, $error] = LookFamily::checkParent($this->db, $this->request->post('parent'), (int) $site['site_id']);
            if ($error !== null) {
                return $back($error, 'error');
            }
        }
        $error = LookFamily::set($this->db, $site, $parent, $this->request->postBool('discard_draft'), $this->app->auth()->id() ?: null);
        if ($error !== null) {
            return $back($error, 'error');
        }
        ChangeLog::write($this->app, 'sites', $parent === null ? 'leave look family' : 'join look family', (string) $site['name'] . ($parent === null ? '' : ' → ' . $parent['name']));

        return $back($parent === null ? 'The site left the look family and keeps the look it has now.' : 'The site takes its look from the parent now.');
    }

    /** @param array<string, mixed> $site */
    private function editForm(array $site): Response
    {
        $overview = SiteRegistry::overview($this->db);
        $row = current(array_filter($overview, fn (array $s): bool => (int) $s['site_id'] === (int) $site['site_id']));

        $family = ['parents' => self::familyParents($overview, $row), 'parent' => null, 'children' => array_values(array_filter($overview, fn (array $s): bool => (int) $s['look_parent_id'] === (int) $site['site_id'])),
            'hasDraft' => $row['ready'] && $row['status'] !== 'trashed' && LookFamily::hasDraft($this->db->forSite((string) $row['prefix']))];
        foreach ($overview as $s) {
            if ((int) $s['site_id'] === (int) $row['look_parent_id']) {
                $family['parent'] = $s;
            }
        }

        return $this->view('edit', (string) ($site['name'] !== '' ? $site['name'] : $site['slug']), ['family' => $family, 'site' => $row, 'others' => array_values(array_filter($overview, fn (array $s): bool => $s['ready'] && $s['status'] !== 'trashed' && (int) $s['site_id'] !== (int) $site['site_id'])),
            'current' => $this->currentId(), 'host' => SiteRegistry::requestHost($this->request), 'dnsOnly' => Domains::dnsOnly($this->db)]);
    }
}
