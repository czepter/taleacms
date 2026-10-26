<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\NotFound;
use Talea\Core\RedirectMatcher;
use Talea\Core\Response;

/**
 * Missing pages: the addresses visitors asked for that do not exist (Core\NotFound), with where they came from and the
 * page the site thinks they meant (Core\RedirectMatcher). Redirect one with a click (a 301 through Core\RedirectRules),
 * or ignore it. The redirects themselves live in the Redirects module.
 */
final class MissingPages extends Module
{
    public const string IDENT = 'missing_pages';
    public const string NAME = 'Missing pages';
    public const string GROUP = 'Search engines';
    public const string ICON = 'redirects';
    public const string EXTENSION = 'redirects';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $status = $this->request->get('status') === 'ignored' ? 'ignored' : 'pending';
        $sort = $this->request->get('sort') === 'new' ? 'new' : 'hits';
        $pending = NotFound::pending($this->app, 60, 200);
        $ignored = NotFound::ignored($this->app, 200);
        $rows = $status === 'ignored' ? $ignored : $pending;
        if ($sort === 'new') {
            usort($rows, fn (array $a, array $b): int => strcmp((string) $b['first_seen_at'], (string) $a['first_seen_at']));
        }
        $week = date('Y-m-d H:i:s', time() - 7 * 86400);

        return $this->kernel->page(t('Missing pages'), $this->app->view->render('admin/seo/missing', [
            'app' => $this->app, 'module' => $this, 'csrf' => $this->app->session->csrfField(),
            'rows' => $rows, 'status' => $status, 'sort' => $sort,
            'suggestions' => $status === 'pending' ? RedirectMatcher::suggestions($this->app, $rows) : [],
            'counts' => ['pending' => count($pending), 'ignored' => count($ignored)],
            'tiles' => [
                'new' => count(array_filter($pending, fn (array $n): bool => $n['first_seen_at'] !== null && $n['first_seen_at'] >= $week)),
                'visits' => array_sum(array_column($pending, 'count')),
                'redirected' => (int) $this->db->value('SELECT COUNT(*) FROM {redirects} WHERE active = TRUE'),
                'ignored' => count($ignored),
            ],
            'ownSite' => fn (string $referrer): bool => str_starts_with($referrer, '/'),
        ]));
    }

    /** A 301 for an address visitors could not find: the typed target, or the page the site suggested. */
    protected function actionRedirect(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $rule = Redirects::normalize($this->request->post('path'), $this->request->post('to'), 'exact', 301);
        if (is_string($rule)) {
            return $this->back($rule, '', [], 'error');
        }
        if (($refused = Redirects::store($this->db, $rule['from'], $rule['to'], 'exact', 301, true)) !== null) {
            return $this->back($refused, '', [], 'error');
        }

        return $this->back('Redirect saved.');
    }

    /** An address visitors could not find, left alone for good (a bot probe, something nobody needs). */
    protected function actionIgnore(): Response
    {
        if ($this->request->isPost()) {
            NotFound::ignore($this->app, [$this->request->post('path')]);
        }

        return Response::redirect($this->url());
    }

    /** All addresses waiting now – the warning on the start screen goes away until a new address appears. */
    protected function actionIgnoreAll(): Response
    {
        $count = $this->request->isPost() ? NotFound::ignore($this->app) : 0;
        $this->app->session->flash('ok', t('%d addresses ignored. A new address that visitors cannot find will show up again.', $count));

        return Response::redirect($this->request->post('back') === 'overview' ? $this->app->url('admin.php') : $this->url());
    }

    /** Empties the overview of not-found URLs. */
    protected function actionClear(): Response
    {
        if ($this->request->isPost()) {
            $this->db->run('DELETE FROM {not_found}');
        }

        return $this->back('The list of addresses not found is empty.');
    }
}
