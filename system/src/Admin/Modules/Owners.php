<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\ChangeLog;
use Talea\Admin\Kernel;
use Talea\Admin\Module;
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

    /** Opens a site of another owner: a one-time token in THAT owner's tables, then the browser goes to the site's primary host (Admin\Kernel signs the operator in there). */
    protected function actionHandoff(): Response
    {
        $owner = $this->owner();
        $user = $this->app->auth()->user();
        if (!$this->request->isPost() || $owner === null || $user === null) {
            return $this->back();
        }
        $result = OwnerRegistry::handoff($this->db, $user, (string) $owner['public_id'], $this->request->post('site'));
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
