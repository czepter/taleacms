<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\ChangeLog;
use Talea\Admin\Module;
use Talea\Core\Response;
use Talea\Fleet\Console;
use Talea\Fleet\Expiry;
use Talea\Fleet\Inventory;
use Talea\Fleet\Kit;
use Talea\Fleet\Today;

/**
 * The fleet console (2.9, extension "fleet", Fleet\Console): every paired site on one screen, the ones that need attention
 * first; a site's last report and version; pairing keys; the calendar of certificates and domain registrations (Fleet\Expiry).
 * Since 2.16 also the shared design kit (Fleet\Kit) the sites receive as drafts.
 */
final class Fleet extends Module
{
    public const string IDENT = 'fleet';
    public const string NAME = 'Fleet';
    public const string GROUP = 'Settings';
    public const string ICON = 'site';
    public const string TABLE = 'fleet_sites';
    public const string EXTENSION = 'fleet';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $sites = Console::overview($this->db, time());
        $show = $this->request->get('show') === 'all' ? 'all' : 'today';
        $query = mb_strtolower(trim($this->request->get('q')));
        $needing = count(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings']));
        $matches = fn (array $s): bool => $query === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['url']), $query);
        $listed = array_values(array_filter($sites, fn (array $s): bool => $matches($s)));
        $rows = array_map(Inventory::row(...), $sites);
        $filters = Inventory::filtersFrom($this->request->get(...));
        $inventory = $show === 'all' ? Inventory::filter($rows, $filters, $query) : [];
        $key = $this->app->session->get('fleet_pairing_key');
        $this->app->session->set('fleet_pairing_key', null); // shown once

        return $this->view('list', 'Fleet', ['sites' => $listed, 'groups' => $show === 'today' ? Today::groups($listed) : [], 'inventory' => $inventory, 'values' => Inventory::values($rows), 'filters' => $filters, 'total' => count($sites), 'needing' => $needing, 'show' => $show, 'query' => $query,
            'latest' => TALEA_VERSION, 'pairingKey' => is_string($key) ? $key : '']);
    }

    protected function actionDetail(): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->back('The site is not in the console.', '', [], 'error');
        }
        $events = $this->db->all("SELECT created_at, type, severity, message FROM {events} WHERE type LIKE 'fleet.%' AND " . $this->db->dialect()->jsonExtract('data', '$.site') . " = ? ORDER BY id DESC LIMIT 20", [(string) (int) $site['id']]);

        return $this->view('detail', (string) $site['name'], ['site' => $site, 'events' => $events]);
    }

    /** The inventory as a CSV file for a spreadsheet, with the filters of the list of all sites. */
    protected function actionInventoryCsv(): Response
    {
        $rows = Inventory::filter(array_map(Inventory::row(...), Console::overview($this->db, time())), Inventory::filtersFrom($this->request->get(...)), $this->request->get('q'));
        ChangeLog::write($this->app, 'fleet', 'export CSV', '');

        return new Response(Inventory::csv($rows), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="fleet-' . date('Y-m-d') . '.csv"']);
    }

    /** Accepts a known state of one check of one site until a day: it leaves the open reasons until then or until the state changes (Fleet\Today). */
    protected function actionAccept(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back('', '');
        }
        $to = $this->request->post('back') === 'detail' ? ['detail', ['id' => $site['public_id']]] : ['', []];
        $key = $this->request->post('check');
        $item = array_values(array_filter($site['items'], fn (array $i): bool => $i['key'] === $key))[0] ?? null;
        if ($item === null) {
            return $this->back('This state has changed in the meantime – look at it again.', $to[0], $to[1], 'error');
        }
        $until = $this->request->post('until');
        try {
            $accepted = Today::accept(Today::decode($site), $item, $this->request->post('note'), $until, (string) (($this->app->auth()->user()['name'] ?? '') ?: ($this->app->auth()->user()['username'] ?? '')), time());
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), $to[0], $to[1], 'error');
        }
        $this->db->update('fleet_sites', ['accepted' => json_encode($accepted, JSON_UNESCAPED_UNICODE)], ['id' => (int) $site['id']]);
        ChangeLog::write($this->app, 'fleet', 'accept', (string) $site['url'] . ': ' . $key);

        return $this->back(t('Accepted until %s. It comes back earlier if the state changes.', format_date($until)), $to[0], $to[1]);
    }

    /** Takes an acceptance back: the state needs attention again. */
    protected function actionWithdraw(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back('', '');
        }
        $to = $this->request->post('back') === 'detail' ? ['detail', ['id' => $site['public_id']]] : ['', []];
        $key = $this->request->post('check');
        $accepted = Today::decode($site);
        unset($accepted[$key]);
        $this->db->update('fleet_sites', ['accepted' => $accepted === [] ? null : json_encode($accepted, JSON_UNESCAPED_UNICODE)], ['id' => (int) $site['id']]);
        ChangeLog::write($this->app, 'fleet', 'withdraw', (string) $site['url'] . ': ' . $key);

        return $this->back('The state needs attention again.', $to[0], $to[1]);
    }

    /** When the certificates and the domain registrations of the fleet end: a month view and the list of all dates. */
    protected function actionCalendar(): Response
    {
        $dates = Expiry::dates(Console::sites($this->db), time());

        return $this->view('calendar', 'Calendar', ['calendar' => Expiry::month($this->request->get('month'), $dates), 'dates' => $dates, 'now' => time(),
            'counts' => $this->tabCounts()]);
    }

    /** The end of a site's domain registration, typed from the registrar's account (the console cannot know it). */
    protected function actionDomain(): Response
    {
        $site = $this->site();
        if (!$this->request->isPost() || $site === null) {
            return $this->back($site === null ? 'The site is not in the console.' : '', '', [], 'error');
        }
        $date = trim($this->request->post('domain_expires'));
        $valid = $date === '' || (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]));
        if (!$valid) {
            return $this->back('Enter the date as shown by the registrar, e.g. 2027-03-31.', 'detail', ['id' => $site['public_id']], 'error');
        }
        $this->db->update('fleet_sites', ['domain_expires' => $date !== '' ? $date : null], ['id' => (int) $site['id']]);
        ChangeLog::write($this->app, 'fleet', 'domain', (string) $site['url'] . ' ' . ($date !== '' ? $date : '–'));

        return $this->back('Saved.', 'detail', ['id' => $site['public_id']]);
    }

    /** @return array{needing: int, total: int} what the tabs show */
    private function tabCounts(): array
    {
        $sites = Console::overview($this->db, time());

        return ['needing' => count(array_filter($sites, fn (array $s): bool => $s['score'] >= Console::REASONS['warnings'])), 'total' => count($sites)];
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
