<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Db;
use Talea\Core\RedirectRules;
use Talea\Core\Response;

/**
 * 301 redirects: old URL -> new. Created automatically when the slug of a page, news item or category changes,
 * manually useful after moving from another system. Used only when the site finds nothing for the URL. Every new
 * redirect also heals the site's own links to the old address (Core\LinkHealing).
 *
 * Since 2.14 the screen also shows, for every address visitors could not find, the page the site thinks they meant
 * (Core\RedirectMatcher) with one click to create the redirect, and the setting that lets the daily job create the
 * sure ones by itself; such a redirect is marked automatic with its score and deleting it is the undo.
 */
final class Redirects extends Module
{
    public const string IDENT = 'redirects';
    public const string TABLE = 'redirects';
    public const string NAME = 'Redirects';
    public const string GROUP = 'Search engines';
    public const string ICON = 'redirects';
    public const string EXTENSION = 'redirects';
    public const bool ADMIN_ONLY = true;

    public static function add(Db $db, string $z, string $commandName): void
    {
        $z = trim($z, '/ ');
        if ($z === '' || $z === trim($commandName, '/ ')) {
            return;
        }
        // the new target URL also takes over older redirects, so that no chains form
        $db->run('UPDATE {redirects} SET to_path = ? WHERE to_path = ?', [$commandName, $z]);
        $db->run('DELETE FROM {redirects} WHERE from_path = ?', [trim($commandName, '/ ')]);
        $db->upsert('redirects', ['from_path' => mb_substr($z, 0, 255), 'to_path' => mb_substr($commandName, 0, 255), 'created_at' => date('Y-m-d H:i:s')], ['from_path'], ['to_path']);
        // links on the site that still lead to the old address are rewritten, so visitors never meet the redirect (2.14)
        \Talea\Core\LinkHealing::heal($db, $z, $commandName);
    }

    private const int PER_PAGE = 50;

    /**
     * The rule as it is stored, or the refusal message (Core\RedirectRules::validate). An exact address is a bare path; the other
     * kinds keep what was typed (a pattern, or a part of an address with its slashes). `$id` > 0 changes that row.
     *
     * @return array{from: string, to: string}|string
     */
    public static function normalize(string $from, string $to, string $match, int $type): array|string
    {
        $from = trim($from);
        if ($match !== 'regex') {
            $from = $match === 'exact' || preg_match('#^https?://#i', $from) ? trim((string) parse_url($from, PHP_URL_PATH), '/ ') : ltrim($from, '/');
        }
        $to = $type >= 400 ? '' : (preg_match('#^https?://#i', trim($to)) ? trim($to) : ($match === 'starts' ? ltrim(trim($to), '/') : trim(trim($to), '/')));

        return RedirectRules::validate($from, $to, $match, $type) ?? ['from' => $from, 'to' => $to];
    }

    /** Stores a checked rule (see normalize()): a new one replaces a rule with the same old address. */
    public static function store(Db $db, string $from, string $to, string $match, int $type, bool $active, int $id = 0): void
    {
        $fields = ['from_path' => mb_substr($from, 0, 255), 'to_path' => mb_substr($to, 0, 255), 'match_type' => $match, 'type' => $type, 'active' => $active, 'auto_score' => null];
        if ($id > 0) {
            $db->update('redirects', $fields, ['redirect_id' => $id]);
        } elseif ($match === 'exact' && $type < 400) {
            self::add($db, $from, $to); // no chains, and the site's own links are healed
            $db->update('redirects', $fields, ['from_path' => $from]);
        } else {
            $db->upsert('redirects', $fields + ['created_at' => date('Y-m-d H:i:s')], ['from_path'], ['to_path', 'match_type', 'type', 'active', 'auto_score']);
        }
        if ($match === 'exact') {
            $db->delete('not_found', ['path' => $from]);
        }
    }

    protected function actionList(): Response
    {
        // one redirect on its own page: new, edited, or prefilled with an address visitors could not find (?from=)
        $r = $this->request;
        if ($r->get('new') !== '' || $r->get('edit') !== '' || $r->get('from') !== '') {
            $edit = $r->get('edit') !== '' ? $this->db->one('SELECT * FROM {redirects} WHERE redirect_id = ?', [$this->idParam('edit')]) : null;
            if ($r->get('edit') !== '' && $edit === null) {
                return $this->error('The redirect does not exist.', 404);
            }

            return $this->view('edit', $edit !== null ? 'Edit redirect' : 'New redirect', ['edit' => $edit, 'fromUrl' => mb_substr($r->get('from'), 0, 255)]);
        }
        $search = mb_substr(trim($r->get('search')), 0, 100);
        $status = in_array($r->get('status'), ['active', 'off', 'auto'], true) ? $r->get('status') : 'all';
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(' . $this->db->dialect()->likeInsensitive('from_path') . ' OR ' . $this->db->dialect()->likeInsensitive('to_path') . ')';
            $params = array_fill(0, 2, '%' . addcslashes($search, '%_\\') . '%');
        }
        $where[] = ['all' => 'TRUE', 'active' => 'active = TRUE', 'off' => 'active = FALSE', 'auto' => 'auto_score IS NOT NULL'][$status];
        $whereParts = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {redirects} ' . $whereParts, $params);
        $pageNumber = max(1, min((int) ceil(max(1, $total) / self::PER_PAGE), $r->getInt('page', 1)));
        $counts = $this->db->one('SELECT COUNT(*) AS n_all, SUM(CASE WHEN active = TRUE THEN 1 ELSE 0 END) AS n_active, SUM(CASE WHEN active = FALSE THEN 1 ELSE 0 END) AS n_off, SUM(CASE WHEN auto_score IS NOT NULL THEN 1 ELSE 0 END) AS n_auto FROM {redirects}') ?? [];

        $notFound = \Talea\Core\NotFound::pending($this->app, 60, 50);

        return $this->view('list', 'Redirects', [
            'records' => $this->db->all('SELECT * FROM {redirects} ' . $whereParts . ' ORDER BY redirect_id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'total' => $total, 'pageNumber' => $pageNumber, 'pageCount' => (int) ceil($total / self::PER_PAGE), 'search' => $search, 'status' => $status,
            'counts' => ['all' => (int) ($counts['n_all'] ?? 0), 'active' => (int) ($counts['n_active'] ?? 0), 'off' => (int) ($counts['n_off'] ?? 0), 'auto' => (int) ($counts['n_auto'] ?? 0)],
            'notFound' => $notFound,
            'suggestions' => \Talea\Core\RedirectMatcher::suggestions($this->app, $notFound),
            'autoOn' => $this->app->settings()->bool('redirect_auto'),
            'threshold' => \Talea\Core\RedirectMatcher::threshold($this->app->settings()),
        ]);
    }

    /** Redirects for missing addresses by themselves (2.14): on/off and the score a candidate needs. */
    protected function actionSettings(): Response
    {
        if ($this->request->isPost()) {
            $threshold = $this->request->postInt('redirect_auto_threshold');
            $s = $this->app->settings();
            $s->set('redirect_auto', $this->request->post('redirect_auto') === '1' ? '1' : '0');
            $s->set('redirect_auto_threshold', (string) ($threshold >= 50 && $threshold <= 100 ? $threshold : \Talea\Core\RedirectMatcher::DEFAULT_THRESHOLD));
            \Talea\Admin\ChangeLog::write($this->app, 'redirects', 'settings', ($s->bool('redirect_auto') ? 'on' : 'off') . ', ' . $s->get('redirect_auto_threshold'));
        }

        return $this->back('Settings saved.');
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $post = $this->request;
        $editing = $post->post('redirect_id') !== '';
        $match = $post->post('match') !== '' ? $post->post('match') : 'exact';
        $type = $post->postInt('type') > 0 ? $post->postInt('type') : 301;
        $active = $post->post('active') !== '0';
        // a refused save goes back to the form it came from
        $form = $editing ? ['edit' => $post->post('redirect_id')] : ['new' => 1, 'from' => $post->post('from_path')];
        if (($guard = $this->refuseUnknownId('redirect_id', 'Enter the old address and the target. The target is a path on this site or a full https://… address.')) !== null) {
            return $guard;
        }
        // one old address per line; one refused line refuses them all
        $lines = $editing ? [$post->post('from_path')] : array_values(array_filter(array_map('trim', preg_split('/\R/u', $post->post('from_path')) ?: [])));
        $rules = [];
        foreach ($lines as $line) {
            $rule = self::normalize($line, $post->post('to_path'), $match, $type);
            if (is_string($rule)) {
                return $this->back($rule, '', $form, 'error');
            }
            $rules[] = $rule;
        }
        if ($rules === []) {
            return $this->back('Enter the old address and the target. The target is a path on this site or a full https://… address.', '', $form, 'error');
        }
        $id = $this->idParam('redirect_id');
        foreach ($rules as $rule) {
            self::store($this->db, $rule['from'], $rule['to'], $match, $type, $active, $id);
        }

        return $this->back('Redirect saved.');
    }

    /** Switches a rule off or on (Turn off / Turn on in the row menu). */
    protected function actionToggle(): Response
    {
        if ($this->request->isPost() && ($id = $this->idParam('redirect_id')) > 0) {
            $this->db->run('UPDATE {redirects} SET active = CASE WHEN active = TRUE THEN FALSE ELSE TRUE END WHERE redirect_id = ?', [$id]);
        }

        return $this->back('Redirect saved.');
    }

    /** A copy of a rule to edit: the old address gets a free "-copy" ending (the old address is unique). */
    protected function actionDuplicate(): Response
    {
        $row = $this->request->isPost() ? $this->db->one('SELECT * FROM {redirects} WHERE redirect_id = ?', [$this->idParam('redirect_id')]) : null;
        if ($row === null) {
            return $this->back();
        }
        $from = mb_substr($row['from_path'], 0, 250) . '-copy';
        for ($i = 2; $this->db->value('SELECT COUNT(*) FROM {redirects} WHERE from_path = ?', [$from]) > 0; $i++) {
            $from = mb_substr($row['from_path'], 0, 245) . '-copy-' . $i;
        }
        $this->db->insert('redirects', ['from_path' => $from, 'to_path' => $row['to_path'], 'match_type' => $row['match_type'], 'type' => $row['type'], 'active' => (bool) $row['active'], 'created_at' => date('Y-m-d H:i:s')]);

        return $this->back('Redirect saved.', '', ['edit' => $this->db->value('SELECT public_id FROM {redirects} WHERE from_path = ?', [$from])]);
    }

    /** What a rule does with a sample path (read-only). */
    protected function actionTest(): Response
    {
        $row = $this->db->one('SELECT * FROM {redirects} WHERE redirect_id = ?', [$this->idParam('id')]);
        if ($row === null) {
            return $this->error('The redirect does not exist.', 404);
        }
        $path = mb_substr(trim($this->request->get('path')), 0, 255);

        return $this->view('test', 'Test redirect', ['rule' => $row, 'path' => $path, 'result' => $path === '' ? null : RedirectRules::target($row, $path)]);
    }

    /** An address visitors could not find, left alone for good (a bot probe, something nobody needs). */
    protected function actionIgnore(): Response
    {
        if ($this->request->isPost()) {
            \Talea\Core\NotFound::ignore($this->app, [$this->request->post('path')]);
        }

        return Response::redirect($this->url() . '#not-found');
    }

    /** All addresses waiting now – the warning on the start screen goes away until a new address appears. */
    protected function actionIgnoreAll(): Response
    {
        $count = $this->request->isPost() ? \Talea\Core\NotFound::ignore($this->app) : 0;
        $this->app->session->flash('ok', t('%d addresses ignored. A new address that visitors cannot find will show up again.', $count));

        return Response::redirect($this->request->post('back') === 'overview' ? $this->app->url('admin.php') : $this->url() . '#not-found');
    }

    /** Empties the overview of not-found URLs. */
    protected function actionClear(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {not_found}');
        }

        return $this->back('The list of addresses not found is empty.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('redirects', ['redirect_id' => $this->idParam('redirect_id')]);
        }

        return $this->back('Redirect deleted.');
    }
}
