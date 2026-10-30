<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\ChangeLog;
use Talea\Admin\Kernel;
use Talea\Admin\Module;
use Talea\Core\Antispam;
use Talea\Core\MediaFolder;
use Talea\Core\Owners as OwnerRegistry;
use Talea\Core\Response;
use Talea\Core\SiteHealth;
use Talea\Core\Sites as SiteRegistry;

/**
 * The owners of the installation (Settings → Owners, only with TALEA_OWNERS, only for the operators: the administrators of owner 1): list
 * them, create one with the e-mail of its first administrator, pause or archive it, give it a host name, invite its administrator and
 * open one of its sites. The module only writes rows (the tables of a new owner are made by cron, Core\Owners::provisionPending); the
 * opening is a one-time handoff written in the OTHER owner's table (Core\Owners::handoff). Docs: "Several owners", stage B.
 */
final class Owners extends Module
{
    public const string IDENT = 'owners';
    public const string TABLE = 'owners';
    public const string NAME = 'Owners';
    public const string GROUP = 'Settings';
    public const string ICON = 'users';
    public const bool ADMIN_ONLY = true;

    public static function availableTo(\Talea\Core\App $app): bool
    {
        return OwnerRegistry::enabled() && OwnerRegistry::isFirst($app->db());
    }

    protected function actionList(): Response
    {
        $rows = [];
        foreach (OwnerRegistry::all($this->db) as $o) {
            $ready = $o['ready_at'] !== null;
            $sites = 0;
            $bytes = 0;
            if ($ready) {
                $ownerDb = $this->db->forOwner((string) $o['prefix']);
                foreach (SiteRegistry::prefixes($ownerDb) as $prefix) {
                    $bytes += $ownerDb->forSite($prefix)->tablesSize();
                }
                $sites = (int) $ownerDb->value("SELECT COUNT(*) FROM {sites} WHERE status <> 'trashed'");
                $bytes += MediaFolder::bytes(MediaFolder::dirOf($o['prefix'] === $this->db->installPrefix ? '' : (string) $o['public_id']));
            }
            $rows[] = $o + ['ready' => $ready, 'sites' => $sites, 'bytes' => $bytes, 'first' => $o['prefix'] === $this->db->installPrefix,
                'health' => $ready ? SiteHealth::owner($this->db->forOwner((string) $o['prefix'])) : null];
        }

        return $this->view('list', 'Owners', ['owners' => $rows, 'limit' => SiteRegistry::limit('MAX_OWNERS')]);
    }

    /** The usage of every owner's sites per month as a CSV (operators; an owner's administrators get their own from the Sites area). */
    protected function actionUsage(): Response
    {
        $csv = SiteHealth::usageCsvAll($this->db, $this->request->getInt('months'));
        ChangeLog::write($this->app, 'owners', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="usage-all-owners-' . date('Y-m-d') . '.csv"']);
    }

    protected function actionNew(): Response
    {
        return $this->form('', 'organisation', '');
    }

    protected function actionCreate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $name = trim(mb_substr($this->request->post('name'), 0, 120));
        $kind = $this->request->post('kind');
        $email = trim($this->request->post('admin_email'));
        if (($limit = OwnerRegistry::limitRefusal($this->db)) !== null) {
            return $this->form($name, $kind, $email, $limit);
        }
        if ($name === '') {
            return $this->form($name, $kind, $email, 'Fill in the name of the owner.');
        }
        if (!in_array($kind, OwnerRegistry::KINDS, true)) {
            return $this->form($name, 'organisation', $email, 'Choose whether the owner is a person or an organisation.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->form($name, $kind, $email, 'Enter the e-mail address of the first administrator.');
        }
        $prefix = OwnerRegistry::add($this->db, $name, $kind, $email);
        ChangeLog::write($this->app, 'owners', 'create', $name);
        $owner = $this->db->one('SELECT public_id FROM {owners} WHERE prefix = ?', [$prefix]);

        return $this->back('The owner is created. It is set up in the background within a few minutes; then give it a host name and send the invitation.', 'edit', ['id' => (string) $owner['public_id']]);
    }

    protected function actionEdit(): Response
    {
        $owner = $this->owner();

        return $owner === null || $owner['prefix'] === $this->db->installPrefix ? $this->error('The owner does not exist.', 404) : $this->editForm($owner);
    }

    protected function actionSave(): Response
    {
        $owner = $this->owner();
        if (!$this->request->isPost() || $owner === null) {
            return $this->back();
        }
        $name = trim(mb_substr($this->request->post('name'), 0, 120));
        $email = trim($this->request->post('admin_email'));
        if ($name === '' || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            return $this->back('Fill in the name and a valid e-mail address.', 'edit', ['id' => $owner['public_id']], 'error');
        }
        $this->db->update('owners', ['name' => $name, 'admin_email' => mb_substr($email, 0, 190)], ['owner_id' => (int) $owner['owner_id']]);

        return $this->back('The owner has been saved.', 'edit', ['id' => $owner['public_id']]);
    }

    protected function actionStatus(): Response
    {
        $owner = $this->owner();
        if (!$this->request->isPost() || $owner === null) {
            return $this->back();
        }
        $error = OwnerRegistry::setStatus($this->db, (string) $owner['prefix'], $this->request->post('status'));
        if ($error === null) {
            ChangeLog::write($this->app, 'owners', 'status', $owner['name'] . ': ' . $this->request->post('status'));
        }

        return $error === null ? $this->back('The owner has been saved.', 'edit', ['id' => $owner['public_id']]) : $this->back($error, 'edit', ['id' => $owner['public_id']], 'error');
    }

    /** The first host name of an owner's first site (the owner has no site to open and no link to send before it). */
    protected function actionDomainAdd(): Response
    {
        $owner = $this->owner();
        if (!$this->request->isPost() || $owner === null || $owner['ready_at'] === null || $owner['prefix'] === $this->db->installPrefix) {
            return $this->back();
        }
        $error = SiteRegistry::addDomain($this->db->forOwner((string) $owner['prefix']), 1, $this->request->post('host'));
        if ($error === null) {
            ChangeLog::write($this->app, 'owners', 'domain', $owner['name'] . ': ' . SiteRegistry::normalizeHost($this->request->post('host')));
        }

        return $error === null ? $this->back('The host name is added.', 'edit', ['id' => $owner['public_id']]) : $this->back($error, 'edit', ['id' => $owner['public_id']], 'error');
    }

    protected function actionInvite(): Response
    {
        $owner = $this->owner();
        if (!$this->request->isPost() || $owner === null || $owner['prefix'] === $this->db->installPrefix) {
            return $this->back();
        }
        $error = OwnerRegistry::invite($this->db, $owner, $this->app->settings(), $this->request);
        if ($error === null) {
            ChangeLog::write($this->app, 'owners', 'invite', (string) $owner['name']);
        }

        return $error === null ? $this->back(t('The invitation has been sent to %s.', $owner['admin_email']), 'edit', ['id' => $owner['public_id']]) : $this->back($error, 'edit', ['id' => $owner['public_id']], 'error');
    }

    /**
     * The operators' tool for starting a site from another site: any set-up site of any owner (the target's own too) is copied into the
     * chosen owner. Across owners nothing stays linked (Core\OwnerClone: files, components and facts are copied); the row is all this
     * request writes, cron sets the copy up. The target owner counts the new site against the installation's limit like any other.
     */
    protected function actionClone(): Response
    {
        $sources = OwnerRegistry::allSites($this->db);
        $owners = array_values(array_filter(OwnerRegistry::all($this->db), fn (array $o): bool => $o['ready_at'] !== null && $o['status'] !== 'archived'));
        if (!$this->request->isPost()) {
            return $this->cloneForm($sources, $owners, '', '', '', '');
        }
        $name = trim(mb_substr($this->request->post('name'), 0, 120));
        $from = $this->request->post('from');
        $to = $this->request->post('to');
        $source = current(array_filter($sources, fn (array $f): bool => $f['site']['public_id'] === $from)) ?: null;
        $target = current(array_filter($owners, fn (array $o): bool => (string) $o['public_id'] === $to)) ?: null;
        $error = ($limit = SiteRegistry::limitRefusal($this->db)) !== null ? $limit : match (true) {
            $name === '' => 'Fill in the site name.',
            $source === null => 'Choose a site that is set up to copy.',
            $target === null => 'Choose the owner that gets the copy.',
            default => null,
        };
        if ($error !== null) {
            return $this->cloneForm($sources, $owners, $name, $from, $to, $error);
        }
        $targetDb = $this->db->forOwner((string) $target['prefix']);
        $sourceOwner = $source['owner'] === $target['prefix'] ? '' : $source['owner'];
        SiteRegistry::add($targetDb, $name, SiteRegistry::freeSlug($targetDb, $name), (int) $source['site']['site_id'], true, $sourceOwner);
        ChangeLog::write($this->app, 'owners', 'clone', $source['owner_name'] . ' / ' . ($source['site']['name'] !== '' ? $source['site']['name'] : $source['site']['slug']) . ' → ' . ($target['name'] !== '' ? $target['name'] : $target['prefix']) . ' / ' . $name);

        return $this->back('The copy is created. It is being set up in the background and appears as ready within a few minutes.');
    }

    /**
     * The operators' way to bring an old site to a client: an archive from an older single-site install becomes a new site of the chosen owner
     * (Core\SiteImports). The row and the file are all this request writes; the owner's own cron imports it, and the other sites keep working.
     * The news keep no author unless the owner is the operator's own (the operator is not a user of the client).
     */
    protected function actionImport(): Response
    {
        $owners = array_values(array_filter(OwnerRegistry::all($this->db), fn (array $o): bool => $o['ready_at'] !== null && $o['status'] !== 'archived'));
        if (!$this->request->isPost()) {
            return $this->importForm($owners, '', '', '');
        }
        $name = trim(mb_substr($this->request->post('name'), 0, 120));
        $to = $this->request->post('to');
        $target = current(array_filter($owners, fn (array $o): bool => (string) $o['public_id'] === $to)) ?: null;
        $error = SiteRegistry::limitRefusal($this->db) ?? match (true) {
            $name === '' => 'Fill in the site name.',
            $target === null => 'Choose the owner that gets the site.',
            default => null,
        };
        if ($error === null) {
            try {
                $first = $target['prefix'] === $this->db->installPrefix;
                Sites::importArchive($this->db->forOwner((string) $target['prefix']), $this->request, $name, $first ? (int) $this->app->auth()->id() : 0, true);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        if ($error !== null) {
            return $this->importForm($owners, $name, $to, $error);
        }
        ChangeLog::write($this->app, 'owners', 'import', ($target['name'] !== '' ? $target['name'] : $target['prefix']) . ' / ' . $name);

        return $this->back('The archive is being imported in the background. The site appears as ready when it is done.');
    }

    /** @param list<array<string, mixed>> $owners */
    private function importForm(array $owners, string $name, string $to, string $error): Response
    {
        return $this->view('import', 'Import a Talea archive', ['name' => $name, 'to' => $to, 'error' => $error, 'owners' => $owners, 'files' => \Talea\Core\SiteImport::listAll(),
            'uploadLimit' => min(Sites::bytes((string) ini_get('upload_max_filesize')), Sites::bytes((string) ini_get('post_max_size')))]);
    }

    /**
     * @param list<array{owner: string, owner_name: string, owner_public_id: string, site: array<string, mixed>}> $sources
     * @param list<array<string, mixed>> $owners
     */
    private function cloneForm(array $sources, array $owners, string $name, string $from, string $to, string $error = ''): Response
    {
        return $this->view('clone', 'Copy a site', ['name' => $name, 'from' => $from, 'to' => $to, 'error' => $error, 'sources' => $sources, 'owners' => $owners]);
    }

    /** An operator vouches for a host name of a client without a check (the owner cannot reach its DNS): verified, and the same name unverified at other owners goes. */
    protected function actionDomainVerify(): Response
    {
        $owner = $this->owner();
        if (!$this->request->isPost() || $owner === null || $owner['ready_at'] === null || $owner['prefix'] === $this->db->installPrefix) {
            return $this->back();
        }
        $ownerDb = $this->db->forOwner((string) $owner['prefix']);
        $domain = $ownerDb->one("SELECT * FROM {site_domains} WHERE host = ? AND kind = 'domain'", [$this->request->post('host')]);
        if ($domain === null) {
            return $this->back('The host name does not exist.', 'edit', ['id' => $owner['public_id']], 'error');
        }
        $error = \Talea\Core\Domains::markVerified($ownerDb, (int) $domain['site_id'], (int) $domain['domain_id']);
        if ($error === null) {
            ChangeLog::write($this->app, 'owners', 'domain', $owner['name'] . ': ' . $domain['host'] . ' verified by an operator');
        }

        return $error === null ? $this->back('The host name is marked as verified.', 'edit', ['id' => $owner['public_id']]) : $this->back($error, 'edit', ['id' => $owner['public_id']], 'error');
    }

    /** Opens a site of another owner: a one-time token in THAT owner's tables, then the browser goes to the site's primary host (Admin\Kernel signs the operator in there). */
    protected function actionHandoff(): Response
    {
        $owner = $this->owner();
        $user = $this->app->auth()->user();
        if (!$this->request->isPost() || $owner === null || $user === null) {
            return $this->back();
        }
        $result = OwnerRegistry::handoff($this->db, $user, (string) $owner['public_id'], $this->request->post('site'), Antispam::visitorKey($this->request, $this->app->settings()));
        if (is_string($result)) {
            return $this->back($result, 'edit', ['id' => $owner['public_id']], 'error');
        }
        [$token, $host] = $result;
        ChangeLog::write($this->app, 'owners', 'open', (string) $owner['name']);

        return Response::redirect(SiteRegistry::originOn($this->request, $host) . $this->request->basePath() . '/' . Kernel::path('handoff=' . $token), 303);
    }

    /** @return array<string, mixed>|null the owner the id parameter names */
    private function owner(): ?array
    {
        return OwnerRegistry::byPublicId($this->db, $this->request->post('id') ?: $this->request->get('id'));
    }

    private function form(string $name, string $kind, string $email, string $error = ''): Response
    {
        return $this->view('form', 'New owner', ['name' => $name, 'kind' => $kind, 'email' => $email, 'error' => $error]);
    }

    /** @param array<string, mixed> $owner */
    private function editForm(array $owner): Response
    {
        $sites = [];
        $users = 0;
        if ($owner['ready_at'] !== null) {
            $ownerDb = $this->db->forOwner((string) $owner['prefix']);
            $sites = SiteRegistry::overview($ownerDb, false);
            $users = (int) $ownerDb->value('SELECT COUNT(*) FROM {users} WHERE operator_user_id IS NULL');
        }

        return $this->view('edit', (string) ($owner['name'] !== '' ? $owner['name'] : $owner['prefix']), ['owner' => $owner, 'sites' => array_values(array_filter($sites, fn (array $s): bool => $s['status'] !== 'trashed')), 'users' => $users]);
    }
}
