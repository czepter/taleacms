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
 * Since 2.14 the screen also has the setting that lets the daily job create the redirects the site is sure about
 * (Core\RedirectMatcher; the addresses themselves are listed in Missing pages); such a redirect is marked automatic with its score and deleting it is the undo.
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
        $db->run("UPDATE {redirects} SET to_path = ? WHERE to_path = ? AND match_type = 'exact'", [$commandName, $z]);
        $db->run("DELETE FROM {redirects} WHERE from_path = ? AND match_type = 'exact'", [trim($commandName, '/ ')]);
        // a row already there for the old address (switched off, 410, another match kind) becomes this plain 301
        $db->upsert('redirects', ['from_path' => mb_substr($z, 0, 255), 'to_path' => mb_substr($commandName, 0, 255), 'match_type' => 'exact', 'type' => 301, 'active' => true, 'created_at' => date('Y-m-d H:i:s')],
            ['from_path'], ['to_path', 'match_type', 'type', 'active']);
        // links on the site that still lead to the old address are rewritten, so visitors never meet the redirect (2.14)
        \Talea\Core\LinkHealing::heal($db, $z, $commandName);
    }

    /**
     * The redirect of an import: an imported page waits hidden, so an old address that still leads to live content of this site must
     * stay as it is (add() would retarget redirects and heal links to the hidden page). True when the redirect was added.
     */
    public static function addImported(Db $db, string $old, string $new): bool
    {
        if (self::isLive($db, $old)) {
            return false;
        }
        self::add($db, $old, $new);

        return true;
    }

    /** Does the path (an additional language prefix and a trailing .html allowed) lead to a visible page, a published news item, a category, a tag, a collection item, a redirect or a system address? */
    private static function isLive(Db $db, string $path): bool
    {
        if (str_starts_with(trim($path, '/ '), '?')) {
            return false; // the numeric /?p=123 of the old site
        }
        // as Front\Kernel::redirectRule matches: `about.html` also answers /about, `de/about` answers /de/about
        [$language, $path] = RedirectRules::address(new \Talea\Core\Settings($db), $path);
        // a prefixed path is looked up in its language version; the default language stays unscoped as before
        [$sameLanguage, $languageParams] = $language === '' ? ['', []] : \Talea\Core\Slug::scope($db, $language);
        [$sameItemLanguage] = $language === '' ? [''] : \Talea\Core\Slug::scope($db, $language, 'p.language');
        $s = explode('/', $path);
        $s[0] = $s[0] === \Talea\Core\Routes::newsSlug($db) ? 'news' : $s[0];
        if (in_array($s[0], \Talea\Front\Kernel::reservedSlugs(), true) && $s[0] !== 'news') {
            return true;
        }
        $exists = static fn (string $sql, array $params): bool => $db->value($sql, $params) !== null;

        return match (true) {
            $s[0] === 'news' && count($s) === 1 => true,
            $s[0] === 'news' && count($s) === 2 => $exists('SELECT 1 FROM {news} WHERE slug = ? AND visible = TRUE AND deleted_at IS NULL' . $sameLanguage, [$s[1], ...$languageParams]),
            $s[0] === 'news' && count($s) === 3 && $s[1] === 'category' => $exists('SELECT 1 FROM {categories} WHERE slug = ?' . $sameLanguage, [$s[2], ...$languageParams]),
            $s[0] === 'news' && count($s) === 3 && $s[1] === 'tag' => $exists('SELECT 1 FROM {tags} WHERE slug = ?', [$s[2]]),
            $exists('SELECT 1 FROM {pages} WHERE slug = ? AND visible = TRUE AND deleted_at IS NULL' . $sameLanguage, [$path, ...$languageParams]) => true,
            count($s) === 2 && $exists('SELECT 1 FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.slug = ? AND k.detail = TRUE AND p.slug = ? AND p.visible = TRUE AND p.deleted_at IS NULL' . $sameItemLanguage, [...$s, ...$languageParams]) => true,
            default => $exists("SELECT 1 FROM {redirects} WHERE from_path = ? AND active = TRUE AND match_type = 'exact'", [$path]),
        };
    }

    private const int PER_PAGE = 50;

    /** The old address as it is stored (see normalize()). */
    public static function normalizeFrom(string $from, string $match): string
    {
        $from = trim($from);
        if ($match === 'regex') {
            return $from;
        }

        return $match === 'exact' || preg_match('#^https?://#i', $from) ? trim((string) parse_url($from, PHP_URL_PATH), '/ ') : ltrim($from, '/');
    }

    /**
     * The rule as it is stored, or the refusal message (Core\RedirectRules::validate). An exact address is a bare path; the other
     * kinds keep what was typed (a pattern, or a part of an address with its slashes). `$id` > 0 changes that row.
     *
     * @return array{from: string, to: string}|string
     */
    public static function normalize(string $from, string $to, string $match, int $type): array|string
    {
        $from = self::normalizeFrom($from, $match);
        $typed = trim($to);
        $to = $type >= 400 ? '' : (preg_match('#^https?://#i', $typed) ? $typed : ($match === 'starts' ? ltrim($typed, '/') : trim($typed, '/')));
        // "/" is the home page: stored as '' (the front sends '' to url('')), checked as "/" so it is not taken for a missing target
        $home = $type < 400 && $typed !== '' && $to === '';

        return RedirectRules::validate($from, $home ? '/' : $to, $match, $type) ?? ['from' => $from, 'to' => $to];
    }

    /** Stores a checked rule (see normalize()): a new one replaces a rule with the same old address. Returns the refusal, null when stored. */
    public static function store(Db $db, string $from, string $to, string $match, int $type, bool $active, int $id = 0): ?string
    {
        if ($id > 0 && (int) $db->value('SELECT COUNT(*) FROM {redirects} WHERE from_path = ? AND redirect_id <> ?', [mb_substr($from, 0, 255), $id]) > 0) {
            return t('Another redirect already uses this old address.');
        }
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

        return null;
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

            // after a refused save the form shows what was typed (match, to, type and active come only from there)
            $typed = $r->get('match') !== '' ? ['match_type' => in_array($r->get('match'), RedirectRules::MATCHES, true) ? $r->get('match') : 'exact', 'from_path' => mb_substr($r->get('from'), 0, 2000),
                'to_path' => mb_substr($r->get('to'), 0, 255), 'type' => $r->getInt('type', 301), 'active' => $r->get('active') !== '0'] : null;

            return $this->view('edit', $edit !== null ? 'Edit redirect' : 'New redirect', ['edit' => $edit, 'typed' => $typed, 'fromUrl' => mb_substr($r->get('from'), 0, 2000)]);
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

        return $this->view('list', 'Redirects', [
            'records' => $this->db->all('SELECT * FROM {redirects} ' . $whereParts . ' ORDER BY redirect_id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'total' => $total, 'pageNumber' => $pageNumber, 'pageCount' => (int) ceil($total / self::PER_PAGE), 'search' => $search, 'status' => $status,
            'counts' => ['all' => (int) ($counts['n_all'] ?? 0), 'active' => (int) ($counts['n_active'] ?? 0), 'off' => (int) ($counts['n_off'] ?? 0), 'auto' => (int) ($counts['n_auto'] ?? 0)],
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
        // a refused save goes back to the form it came from, with what was typed
        $form = ($editing ? ['edit' => $post->post('redirect_id')] : ['new' => 1])
            + ['from' => $post->post('from_path'), 'match' => $match, 'to' => $post->post('to_path'), 'type' => $type, 'active' => $active ? 1 : 0];
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
            if (($refused = self::store($this->db, $rule['from'], $rule['to'], $match, $type, $active, $id)) !== null) {
                return $this->back($refused, '', $form, 'error');
            }
        }

        return $this->back('Redirect saved.');
    }

    /** Switches a rule off or on (Turn off / Turn on in the row menu), back to the same tab and search. */
    protected function actionToggle(): Response
    {
        $row = $this->request->isPost() ? $this->db->one('SELECT redirect_id, active FROM {redirects} WHERE redirect_id = ?', [$this->idParam('redirect_id')]) : null;
        $message = '';
        if ($row !== null) {
            $this->db->update('redirects', ['active' => !$row['active']], ['redirect_id' => $row['redirect_id']]);
            $message = $row['active'] ? 'Redirect switched off.' : 'Redirect switched on.';
        }

        return $this->back($message, '', array_filter(['status' => $this->request->post('status'), 'search' => $this->request->post('search')]));
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
        // the copy starts switched off, so it never answers before it was edited
        if (($refused = RedirectRules::validate($from, (string) $row['to_path'], $row['match_type'], (int) $row['type'])) !== null) {
            return $this->back($refused, '', [], 'error');
        }
        $this->db->insert('redirects', ['from_path' => $from, 'to_path' => $row['to_path'], 'match_type' => $row['match_type'], 'type' => $row['type'], 'active' => false, 'created_at' => date('Y-m-d H:i:s')]);

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

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('redirects', ['redirect_id' => $this->idParam('redirect_id')]);
        }

        return $this->back('Redirect deleted.');
    }
}
