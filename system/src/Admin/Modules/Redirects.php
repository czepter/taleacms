<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Db;
use Kaleta\Core\Response;

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
    public const string NAME = 'Redirects';
    public const string GROUP = 'Site care';
    public const string ICON = 'presmerovani';
    public const string EXTENSION = 'presmerovani';
    public const bool ADMIN_ONLY = true;

    public static function add(Db $db, string $z, string $commandName): void
    {
        $z = trim($z, '/ ');
        if ($z === '' || $z === trim($commandName, '/ ')) {
            return;
        }
        // the new target URL also takes over older redirects, so that no chains form
        $db->run('UPDATE {presmerovani} SET na_adresu = ? WHERE na_adresu = ?', [$commandName, $z]);
        $db->run('DELETE FROM {presmerovani} WHERE z_adresy = ?', [trim($commandName, '/ ')]);
        $db->run(
            'INSERT INTO {presmerovani} (z_adresy, na_adresu, vytvoreno) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE na_adresu = VALUES(na_adresu)',
            [mb_substr($z, 0, 255), mb_substr($commandName, 0, 255)],
        );
        // links on the site that still lead to the old address are rewritten, so visitors never meet the redirect (2.14)
        \Kaleta\Core\LinkHealing::heal($db, $z, $commandName);
    }

    private const int PER_PAGE = 50;

    protected function actionList(): Response
    {
        $search = mb_substr(trim($this->request->get('hledat')), 0, 100);
        $whereParts = $search !== '' ? 'WHERE z_adresy LIKE ? OR na_adresu LIKE ?' : '';
        $params = $search !== '' ? array_fill(0, 2, '%' . addcslashes($search, '%_\\') . '%') : [];
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {presmerovani} ' . $whereParts, $params);
        $pageNumber = max(1, min((int) ceil(max(1, $total) / self::PER_PAGE), $this->request->getInt('strana', 1)));

        $notFound = \Kaleta\Core\NotFound::pending($this->app, 60, 50);

        return $this->view('list', 'Redirects', [
            'records' => $this->db->all('SELECT * FROM {presmerovani} ' . $whereParts . ' ORDER BY idp DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($pageNumber - 1) * self::PER_PAGE), $params),
            'total' => $total, 'pageNumber' => $pageNumber, 'pageCount' => (int) ceil($total / self::PER_PAGE), 'search' => $search,
            'edit' => $this->request->getInt('upravit') > 0 ? $this->db->one('SELECT * FROM {presmerovani} WHERE idp = ?', [$this->request->getInt('upravit')]) : null,
            'notFound' => $notFound,
            'suggestions' => \Kaleta\Core\RedirectMatcher::suggestions($this->app, $notFound),
            'autoOn' => $this->app->settings()->bool('redirect_auto'),
            'threshold' => \Kaleta\Core\RedirectMatcher::threshold($this->app->settings()),
            'fromUrl' => mb_substr($this->request->get('z'), 0, 255),
        ]);
    }

    /** Redirects for missing addresses by themselves (2.14): on/off and the score a candidate needs. */
    protected function actionSettings(): Response
    {
        if ($this->request->isPost()) {
            $threshold = $this->request->postInt('redirect_auto_threshold');
            $s = $this->app->settings();
            $s->set('redirect_auto', $this->request->post('redirect_auto') === '1' ? '1' : '0');
            $s->set('redirect_auto_threshold', (string) ($threshold >= 50 && $threshold <= 100 ? $threshold : \Kaleta\Core\RedirectMatcher::DEFAULT_THRESHOLD));
            \Kaleta\Admin\ChangeLog::write($this->app, 'redirects', 'settings', ($s->bool('redirect_auto') ? 'on' : 'off') . ', ' . $s->get('redirect_auto_threshold'));
        }

        return $this->back('Settings saved.');
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $z = (string) parse_url($this->request->post('z_adresy'), PHP_URL_PATH);
        $commandName = $this->request->post('na_adresu');
        if (trim($z, '/') === '' || $commandName === '' || (!preg_match('#^https?://#i', $commandName) && !preg_match('#^/?[^\s:]*$#', $commandName))) {
            return $this->back('Enter the old address (a path on this site) and the target – a path or a full https://… URL', type: 'chyba');
        }
        $target = preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/');
        $idp = $this->request->postInt('idp');
        if ($idp > 0) {
            // editing an existing record
            $this->db->update('presmerovani', ['z_adresy' => mb_substr(trim($z, '/ '), 0, 255), 'na_adresu' => mb_substr($target, 0, 255)], ['idp' => $idp]);
        } else {
            self::add($this->db, $z, $target);
        }
        $this->db->run('UPDATE {presmerovani} SET typ = ?, auto_score = NULL WHERE z_adresy = ?', [$this->request->postInt('typ') === 302 ? 302 : 301, trim($z, '/ ')]);
        $this->db->delete('nenalezeno', ['cesta' => trim($z, '/')]);

        return $this->back('Redirect saved.');
    }

    /** An address visitors could not find, left alone for good (a bot probe, something nobody needs). */
    protected function actionIgnore(): Response
    {
        if ($this->request->isPost()) {
            \Kaleta\Core\NotFound::ignore($this->app, [$this->request->post('cesta')]);
        }

        return Response::redirect($this->url() . '#nenalezeno');
    }

    /** All addresses waiting now – the warning on the start screen goes away until a new address appears. */
    protected function actionIgnoreAll(): Response
    {
        $count = $this->request->isPost() ? \Kaleta\Core\NotFound::ignore($this->app) : 0;
        $this->app->session->flash('ok', t('%d addresses ignored. A new address that visitors cannot find will show up again.', $count));

        return Response::redirect($this->request->post('zpet') === 'prehled' ? $this->app->url('admin.php') : $this->url() . '#nenalezeno');
    }

    /** Empties the overview of not-found URLs. */
    protected function actionClear(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {nenalezeno}');
        }

        return $this->back('The list of addresses not found is empty.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('presmerovani', ['idp' => $this->request->postInt('idp')]);
        }

        return $this->back('Redirect deleted.');
    }
}
