<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\ChangeLog;
use Talea\Admin\Module;
use Talea\Core\Response;
use Talea\Fleet\Console;
use Talea\Fleet\Kit;

/**
 * The fleet console (2.9, extension "fleet", Fleet\Console): every paired site on one screen, the ones that need attention
 * first; a site's last report; pairing keys; the update ring of each site and allowing a new version by hand. Since 2.16
 * also the shared design kit (Fleet\Kit) the sites receive as drafts.
 */
final class Fleet extends Module
{
    public const string IDENT = 'fleet';
    public const string NAME = 'Fleet';
    public const string GROUP = 'Administration';
    public const string ICON = 'site';
    public const string TABLE = 'fleet_sites';
    public const string EXTENSION = 'fleet';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $sites = Console::overview($this->db, time());
        $show = $this->request->get('show') === 'all' ? 'all' : 'attention';
        $query = mb_strtolower(trim($this->request->get('q')));
        $needing = count(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings']));
        $listed = array_values(array_filter($sites, fn (array $s): bool => ($show === 'all' || $s['score'] >= Console::REASONS['warnings'] || $query !== '')
            && ($query === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['url']), $query))));
        $key = $this->app->session->get('fleet_pairing_key');
        $this->app->session->set('fleet_pairing_key', null); // shown once

        return $this->view('list', 'Fleet', ['sites' => $listed, 'total' => count($sites), 'needing' => $needing, 'show' => $show, 'query' => $query,
            'latest' => Console::latest($this->app)[0], 'pairingKey' => is_string($key) ? $key : '']);
    }

    protected function actionDetail(): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->back('The site is not in the console.', '', [], 'error');
        }
        $events = $this->db->all("SELECT created_at, type, severity, message FROM {events} WHERE type LIKE 'fleet.%' AND JSON_EXTRACT(data, '$.site') = ? ORDER BY id DESC LIMIT 20", [(int) $site['id']]);

        return $this->view('detail', (string) $site['name'], ['site' => $site, 'events' => $events, 'latest' => Console::latest($this->app)[0]]);
    }

    protected function actionPairingKey(): Response
    {
        if ($this->request->isPost()) {
            if ($this->app->settings()->get('site_url') === '') {
                return $this->back('Fill in the site address in Settings → General first.', '', [], 'error');
            }
            $this->app->session->set('fleet_pairing_key', Console::newPairingKey($this->app));
            ChangeLog::write($this->app, 'fleet', 'pairing_key');
        }

        return $this->back();
    }

    protected function actionRing(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            $ring = $this->request->post('ring') === 'canary' ? 'canary' : 'normal';
            $this->db->update('fleet_sites', ['ring' => $ring], ['id' => (int) $site['id']]);
            ChangeLog::write($this->app, 'fleet', 'ring', $site['url'] . ': ' . $ring);
        }

        return $this->back('Saved.', $site !== null ? 'detail' : '', $site !== null ? ['id' => (string) $site['public_id']] : []);
    }

    /** Allows the newest version now – one site (id) or every site that lets the console decide. */
    protected function actionAllow(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->idParam();
        $count = Console::allowNow($this->app, $id > 0 ? $id : null);
        ChangeLog::write($this->app, 'fleet', 'allow_update', ($id > 0 ? (string) $this->db->value('SELECT url FROM {fleet_sites} WHERE id = ?', [$id]) : 'all') . ': ' . $count);

        return $this->back($count > 0 ? t('The new version is allowed on %d sites; each installs it with its next report (within an hour).', $count) : 'No site waits for a new version it may install.',
            $id > 0 ? 'detail' : '', $id > 0 ? ['id' => $this->publicId($id)] : []);
    }

    protected function actionCheck(): Response
    {
        if ($this->request->isPost()) {
            $id = $this->idParam();
            Console::checkUptime($this->app, $id > 0 ? $id : null);
        }

        $uuid = $this->request->post('id');

        return $this->back('Checked.', $this->idParam() > 0 ? 'detail' : '', $this->idParam() > 0 ? ['id' => $uuid] : []);
    }

    protected function actionRemove(): Response
    {
        $site = $this->site();
        if ($this->request->isPost() && $site !== null) {
            Console::remove($this->app, (int) $site['id']);
            ChangeLog::write($this->app, 'fleet', 'remove', (string) $site['url']);
        }

        return $this->back('The site was removed from the console. It stops reporting with its next report; pair it again to bring it back.');
    }

    /** The shared design kit (2.16, Fleet\Kit): what the console offers the sites, and the versions published so far. */
    protected function actionKit(): Response
    {
        $s = $this->app->settings();

        return $this->view('kit', 'Shared kit', [
            'designSystem' => \Talea\Builder\DesignSystem::load($s), 'classes' => \Talea\Core\Look::classes($this->db, $s, false),
            'components' => \Talea\Builder\Components::all($this->db), 'sections' => $this->db->all('SELECT section_id, public_id, name FROM {sections} ORDER BY name'),
            'kits' => Kit::history($this->db), 'sites' => Console::sites($this->db), 'applied' => Kit::appliedVersions($this->db),
        ]);
    }

    /** Publishes a new kit version from the chosen parts; the sites that opted in pick it up with their next report, as drafts. */
    protected function actionKitPublish(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', 'kit');
        }
        $ids = fn (string $key, string $table): array => array_values(array_filter(array_map(fn (string $uuid): int => $this->db->internalId($table, $uuid), $this->request->postList($key))));
        $classes = $this->request->postBool('classes_all') ? null : array_values(array_filter(is_array($_POST['classes'] ?? null) ? $_POST['classes'] : [], 'is_string'));
        try {
            $kit = Kit::publish($this->app, ['design_system' => $this->request->postBool('design_system'), 'classes' => $classes, 'components' => $ids('components', 'components'), 'sections' => $ids('sections', 'sections')]);
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), 'kit', [], 'error');
        }
        ChangeLog::write($this->app, 'fleet', 'kit_publish', 'version ' . $kit['version'] . ': ' . $kit['summary']);

        return $this->back(t('Kit version %d is published (%s). Every site that receives the kit picks it up with its next report, within an hour – as drafts for its people to publish.', $kit['version'], $kit['summary']), 'kit');
    }

    /** @return array<string, mixed>|null the site from ?id= or the posted id, with its attention and decoded report */
    private function site(): ?array
    {
        $id = $this->idParam();
        $row = $this->db->one('SELECT * FROM {fleet_sites} WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $beat = json_decode((string) ($row['heartbeat'] ?? ''), true);

        return $row + Console::attention($row, time()) + ['beat' => is_array($beat) ? $beat : []];
    }
}
