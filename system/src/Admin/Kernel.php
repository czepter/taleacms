<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\App;
use Talea\Core\Migrator;
use Talea\Core\Response;
use Talea\Core\Extensions;

/**
 * Administration. URLs: /admin/<module>/<action>/<id> (index.php hands them to admin.php, which reads them as
 * ?module=&action=&id=); actions of the administration itself are /admin/<action> (OWN_ACTIONS).
 */
final class Kernel
{
    /** Actions that belong to no module: /admin/account, /admin/oauth … (no module ident may take these names). */
    public const array OWN_ACTIONS = ['account', 'oauth', 'token', 'logout', 'password', 'hide_first_steps', 'skip_first_step'];

    /**
     * The editors' ongoing requests (unsaved state, assistant, build draft): they do not change the site, so they neither clear the
     * page cache (it would be cold all the time during work) nor publish files waiting for approval (Core\MediaStaging).
     */
    public const array DRAFT_ACTIONS = ['draft', 'assistant', 'build_save', 'preview', 'build_ai_text', 'build_ask'];

    /** A path segment that may stand for a module, an action or an id (anything else stays in the query). */
    private const string SEGMENT = '[A-Za-z0-9_.-]+';

    /**
     * The clean address of an admin query: "module=news&action=edit&id=X&page=2#top" -> "admin/news/edit/X?page=2#top".
     * App::url() calls it for every "admin.php?…", so links, forms and redirects never show admin.php or ?module=.
     */
    public static function path(string $query): string
    {
        [$query, $fragment] = array_pad(explode('#', $query, 2), 2, null);
        $named = [];
        $rest = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (in_array($key, ['module', 'action', 'id'], true) && !isset($named[$key]) && preg_match('#^' . self::SEGMENT . '$#', rawurldecode($value)) === 1) {
                $named[$key] = rawurldecode($value);
            } else {
                $rest[] = $pair;
            }
        }
        $module = $named['module'] ?? null;
        $action = $named['action'] ?? null;
        $id = $named['id'] ?? null;
        // an action without a module that is not the administration's own would read as a module: it stays in the query
        if ($module === null && $action !== null && !in_array($action, self::OWN_ACTIONS, true)) {
            $rest[] = 'action=' . rawurlencode($action);
            $action = null;
        }
        // an id goes into the path only after a module and its action
        if ($id !== null && ($module === null || $action === null)) {
            $rest[] = 'id=' . rawurlencode($id);
            $id = null;
        }
        $segments = array_filter([$module, $action, $id], fn (?string $s): bool => $s !== null);

        return rtrim('admin/' . implode('/', $segments), '/') . ($rest !== [] ? '?' . implode('&', $rest) : '') . ($fragment !== null ? '#' . $fragment : '');
    }

    /**
     * A request for /admin/… (from index.php): its segments become the query admin.php reads. A GET that still names the
     * module or action in its query (a GET form, an old link) is sent to the clean address. False = not an admin address.
     */
    public static function route(): bool
    {
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (preg_match('#^' . preg_quote($base, '#') . '/admin(?:/(.*))?$#', $path, $m) !== 1) {
            return false;
        }
        $_SERVER['SCRIPT_NAME'] = $base . '/admin.php';
        $target = self::path(http_build_query($_GET, '', '&', PHP_QUERY_RFC3986));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (isset($_GET['module']) || isset($_GET['action'])) && preg_match('#^admin(?:[?\#]|$)#', $target) !== 1) {
            header('Location: ' . $base . '/' . $target, true, 302);
            exit;
        }
        $segments = array_values(array_filter(explode('/', rawurldecode($m[1] ?? '')), fn (string $s): bool => $s !== ''));
        $_GET = (isset($segments[0]) && in_array($segments[0], self::OWN_ACTIONS, true) ? ['action' => $segments[0]]
            : array_filter(['module' => $segments[0] ?? null, 'action' => $segments[1] ?? null, 'id' => $segments[2] ?? null], fn (?string $v): bool => $v !== null)) + $_GET;

        return true;
    }

    /**
     * Modules in the order they appear in the menu, section by section (Module::GROUP); the first module of a section the
     * person may open is where the section's link goes.
     *
     * @var list<class-string<Module>>
     */
    public const array MODULES = [
        Modules\Pages::class,
        Modules\Appearance::class,
        Modules\SiteParts::class,
        Modules\Menu::class,
        Modules\Components::class,
        Modules\Popups::class,
        Modules\Audit::class,
        Modules\Redirects::class,
        Modules\News::class,
        Modules\Categories::class,
        Modules\Tags::class,
        Modules\Collections::class,
        Modules\Media::class,
        Modules\Bookings::class,
        Modules\Enquiries::class,
        Modules\Members::class,
        Modules\Newsletters::class,
        Modules\Subscribers::class,
        Modules\Stats::class,
        Modules\Experiments::class,
        Modules\Requests::class,
        Modules\ClaudeSettings::class,
        Modules\Schedules::class,
        Modules\Notebook::class,
        Modules\Settings::class,
        Modules\Business::class,
        Modules\Facts::class,
        Modules\Blueprints::class,
        Modules\Wizard::class,
        Modules\Users::class,
        Modules\Roles::class,
        Modules\Connectors::class,
        Modules\Extensions::class,
        Modules\Addons::class,
        Modules\Transfer::class,
        Modules\ChangeLog::class,
        Modules\Status::class,
        Modules\Fleet::class,
    ];

    /** Sections of the sidebar in their order (Module::GROUP); Settings is the gear at the bottom, not a section of the list. */
    public const array SECTIONS = ['Website', 'Content', 'Bookings', 'Customers', 'E-mail', 'Analytics', 'AI'];

    /** The periods "At a glance" on Home offers (days; Core\Report::PERIODS has a year too, Analytics shows that). */
    public const array GLANCE_PERIODS = [7, 30, 90];

    /**
     * The sidebar of the Settings area (the gear): heading => list of [module ident, settings tab ('' = the module's own
     * screen), label]. An entry shows only when the person may open its module.
     */
    public const array SETTINGS_MENU = [
        'Site' => [['settings', 'general', 'General'], ['business', '', 'Business details'], ['settings', 'seo', 'SEO and GEO'], ['settings', 'cookies', 'Privacy and cookies']],
        'People' => [['users', '', 'Users and roles']],
        'Connections' => [['settings', 'mail', 'Mail'], ['connectors', '', 'Integrations'], ['settings', 'analytics', 'Analytics'], ['settings', 'webhooks', 'Webhooks']],
        'Features' => [['extensions', '', 'Features and add-ons']],
        'Data and system' => [['settings', 'backups', 'Backups'], ['settings', 'reports', 'Reports'], ['transfer', '', 'Import and export'], ['changelog', '', 'Change log'], ['status', '', 'System status'], ['fleet', '', 'Fleet'], ['settings', 'console', 'Fleet console']],
    ];

    /** Home: the first steps in collapsible groups (step name => group); every step of firstSteps() is listed. */
    private const array FIRST_STEP_GROUPS = [
        'Set up your site' => 'Build your website', 'Give your site a face' => 'Build your website', 'Prepare your pages' => 'Build your website',
        'Complete the privacy policy' => 'Build your website', 'Fill in company details' => 'Describe your business', 'Your kind of business' => 'Describe your business',
        'Connect Claude' => 'Connect Claude and e-mail', 'Set up e-mail' => 'Connect Claude and e-mail',
    ];

    /**
     * The menu entries of each section: section => [ident => class], without the modules that sit under a parent.
     *
     * @param array<string, class-string<Module>> $modules the modules the person may open (modules())
     * @return array<string, array<string, class-string<Module>>>
     */
    public static function sections(array $modules): array
    {
        $sections = [];
        foreach ($modules as $ident => $class) {
            if ($class::PARENT === '' || !isset($modules[$class::PARENT])) {
                $sections[$class::GROUP][$ident] = $class;
            }
        }

        return $sections;
    }

    /**
     * What waits for someone, shown next to its menu item: new enquiries (spam left out, as in their list) and bookings to
     * confirm that have not ended yet. One query per page through indexes, only for the modules the person may open.
     *
     * @param array<string, class-string<Module>> $modules the modules the person may open (modules())
     * @return array<string, int> ident => count, only counts above 0
     */
    public static function waiting(App $app, array $modules): array
    {
        $counts = array_intersect_key([
            'enquiries' => "(SELECT COUNT(*) FROM {enquiries} WHERE status = 0 AND category <> 'spam')",
            'bookings' => "(SELECT COUNT(*) FROM {bookings} WHERE status = 'pending' AND ends_at > NOW())",
        ], $modules);
        if ($counts === []) {
            return [];
        }
        $row = (array) $app->db()->one('SELECT ' . implode(', ', array_map(fn (string $ident, string $count): string => $count . ' AS ' . $ident, array_keys($counts), $counts)));

        return array_filter(array_map(intval(...), $row));
    }

    /** HTML a screen puts into the sidebar instead of the menu (Website mode: the page tree); '' = the menu. */
    public string $sidebar = '';

    public function __construct(public readonly App $app)
    {
    }

    public function handle(): Response
    {
        $app = $this->app;
        $request = $app->request;
        \Talea\Extension\Registry::boot($app); // add-ons (3.0): their admin pages and tools

        // admin language: the user's choice (My account); the sign-in page follows the site language. It is set first so that even the message about an expired form is translated
        // (a regional site language such as de-at gives the administration of its language)
        \Talea\Core\Language::setAdminRegister((string) ($app->auth()->user()['register'] ?? ''));
        \Talea\Core\Language::setSiteRegister($app->settings()->get('german_register'));
        \Talea\Core\Language::set(\Talea\Core\Language::adminCode($app->auth()->user(), $app->settings()), 'admin-');
        if (\Talea\Core\Files::bodyTooLarge($request)) {
            return $this->page('Invalid request', $app->view->render('admin/error', ['app' => $app,
                'text' => t('The upload is larger than the server allows (at most %s), so nothing arrived. Upload a smaller file or raise post_max_size on the server.', \Talea\Core\Files::size(\Talea\Core\Files::iniBytes((string) ini_get('post_max_size')))),
            ]), 413);
        }
        if ($request->isPost() && !$app->session->csrfValid($request)) {
            return $this->page('Invalid request', $app->view->render('admin/error', ['app' => $app,
                'text' => 'The form has expired. Go back, reload the page and submit it again.',
            ]), 400);
        }

        // every change in the admin invalidates the site page cache, except the editors' ongoing requests (DRAFT_ACTIONS)
        if ($request->isPost() && !in_array($request->get('action'), self::DRAFT_ACTIONS, true)) {
            \Talea\Front\Cache::clear();
        }
        $action = $request->get('action');
        // site URL: older installs do not have it yet - it is written from the URL the signed-in administrator works on
        if ($app->settings()->get('site_url') === '' && $app->auth()->isAdmin()) {
            $app->settings()->set('site_url', $request->origin());
        }
        $request->setOrigin($app->settings()->get('site_url'));
        $app->applyTimezone();
        if ($app->auth()->user() === null) {
            return $action === 'password' ? (new PasswordReset($app))->handle() : $this->login();
        }
        if ($action === 'logout' && $request->isPost()) {
            $app->auth()->logout();

            return Response::redirect($app->url('admin.php'));
        }

        // migrations never run on a page request (bin/migrate does, e.g. in the Docker entrypoint); the administrator is told when some wait
        if ($app->auth()->isAdmin() && Migrator::pending($app->db()) !== []) {
            $app->session->flash('error', t('The database is behind the code: run "php bin/migrate" on the server.'));
        }

        if ($app->auth()->isAdmin() && !\Talea\Core\Demo::active()) {
            \Talea\Core\Backup::createAutomatic($app->db(), $app->settings());
        }
        if ($app->auth()->user() !== null) {
            Modules\News::emptyTrash($app->db()); // the trash keeps news and pages for 30 days
            Modules\Pages::emptyTrash($app->db());
            Modules\Collections::emptyTrash($app->db());
        }

        $ident = $request->get('module');
        if (\Talea\Core\Demo::active() && \Talea\Core\Demo::blocksAdmin($ident, $action, $request->post('tab') ?: $request->get('tab'), $request->isPost())) {
            $app->session->flash('error', \Talea\Core\Demo::refusal());

            return Response::redirect($app->url('admin.php' . ($ident !== '' ? '?module=' . rawurlencode($ident) : '')));
        }
        // mandatory two-factor sign-in: whoever does not have it yet can only go to My account (and sign out) until they enable it
        if ($app->auth()->isMissingRequired2fa($app->settings()) && !in_array($action, ['account', 'token'], true)) {
            $app->session->flash('error', t('This site requires two-factor sign-in. Please turn it on below – until then the administration is locked.'));

            return Response::redirect($app->url('admin.php?action=account'));
        }
        if ($action === 'token') {
            // after a new sign-in in another tab, the editor takes the valid form token here and continues saving
            return Response::json(['csrf' => $app->session->csrfToken()]);
        }
        if ($action === 'account') {
            return (new Account($this))->handle();
        }
        if ($action === 'oauth') {
            return $this->handleOAuthConsent();
        }
        if ($action === 'hide_first_steps' && $request->isPost() && $app->auth()->isAdmin()) {
            $app->settings()->set('first_steps_hidden', '1');

            return Response::redirect($app->url('admin.php'));
        }
        // one step skipped (not wanted, or done elsewhere) or brought back
        if ($action === 'skip_first_step' && $request->isPost() && $app->auth()->isAdmin() && array_key_exists($request->post('step'), self::FIRST_STEP_GROUPS)) {
            $step = $request->post('step');
            $skipped = $this->skippedFirstSteps();
            $skipped = in_array($step, $skipped, true) ? array_diff($skipped, [$step]) : [...$skipped, $step];
            $app->settings()->set('first_steps_skipped', (string) json_encode(array_values($skipped)));

            return Response::redirect($app->url('admin.php'));
        }
        if ($ident === '') {
            return $this->page('', $app->view->render('admin/dashboard', $this->desktop()));
        }
        $class = $this->modules()[$ident] ?? null;
        if ($class === null) {
            // a mistyped section is not a matter of access
            $known = array_filter(self::MODULES, fn (string $c): bool => $c::IDENT === $ident) !== [];

            return $known ? $this->page('Error', $app->view->render('admin/error', ['app' => $app, 'text' => 'You do not have access to this module.']), 403)
                : $this->page('Error', $app->view->render('admin/error', ['app' => $app, 'text' => 'This page does not exist.']), 404);
        }

        $response = (new $class($this))->handle($action === '' ? 'list' : $action);
        if ($request->isPost() && !in_array($action, self::DRAFT_ACTIONS, true)) {
            \Talea\Core\MediaStaging::promotePublished($app->db()); // files waiting for approval that published content now uses (#150)
        }
        if ($request->isPost() && $response->status === 302) {
            // every change made in the admin goes to the change log
            $description = $request->post('title') ?: ($request->post('name') ?: ($request->post('username') ?: $request->post('tab')));
            ChangeLog::write($app, $ident, $action, $description);
        }

        return $response;
    }

    /**
     * Modules available to the signed-in user.
     *
     * @return array<string, class-string<Module>> ident => class
     */
    public function modules(): array
    {
        $auth = $this->app->auth();
        $modules = [];
        foreach (self::MODULES as $class) {
            if (!Extensions::isEnabled($this->app->settings(), $class::EXTENSION)) {
                continue;
            }
            $allowed = $class::ADMIN_ONLY ? $auth->isAdmin() : $auth->hasModule($class::SHARES_PERMISSION_OF !== '' ? $class::SHARES_PERMISSION_OF : $class::IDENT, $class::FOR_ALL_USERS);
            if ($allowed && $class::availableTo($this->app)) {
                $modules[$class::IDENT] = $class;
            }
        }

        return $modules;
    }

    /** Wraps the content in the common admin frame (menu, sign-in bar, messages). */
    public function page(string $heading, string $content, int $status = 200): Response
    {
        $app = $this->app;

        return Response::html($app->view->render('admin/layout', [
            'app' => $app,
            'heading' => t($heading),
            'content' => $content,
            'modules' => $app->auth()->user() !== null ? $this->modules() : [],
            'active' => $app->request->get('module'),
            'username' => $app->auth()->user(),
            'flashes' => $app->session->takeFlashes(),
            'sidebar' => $this->sidebar,
        ]), $status);
    }

    /**
     * Data of the start screen: site overview.
     *
     * @return array<string, mixed>
     */
    private function desktop(): array
    {
        $data = ['app' => $this->app, 'modules' => $this->modules()];
        $db = $this->app->db();
        $aliasedScope = ' AND c.deleted_at IS NULL' . $this->app->auth()->articleScope('c.');  // for queries with the alias c

        $modules = $this->modules();
        $warnings = [];
        if (isset($modules['redirects'])) {
            // only addresses that still end in 404 and nobody ignored (Core\NotFound cleans the rest up)
            $missing = count(\Talea\Core\NotFound::pending($this->app));
            if ($missing > 0) {
                $warnings[] = [t('Addresses on your site that repeatedly ended with “page not found” this week: %d. Visitors came from an old link or a typo – send each one to the right page with a redirect, or ignore it.', $missing),
                    $this->app->url('admin.php?module=redirects#not-found'), t('Review the addresses'), $this->app->url('admin.php?module=redirects&action=ignore_all')];
            }
        }
        if ($this->app->auth()->isAdmin()) {
            $backup = \Talea\Core\Backup::listAll()[0]['time'] ?? 0;
            if (time() - $backup > 8 * 86400) {
                $warnings[] = [$backup === 0 ? t('The site has no database backup yet.') : t('The last database backup is from %s.', format_date(date('Y-m-d H:i:s', $backup))), $this->app->url('admin.php?module=settings&tab=backups')];
            }
            // the instructions steer every Claude connection and a connection may rewrite them: shown until an administrator checked them (#149)
            if (($change = \Talea\Core\ClaudeInstructions::pending($this->app)) !== null) {
                $warnings[] = [\Talea\Core\ClaudeInstructions::who($change) . ' ' . t('Check them.'), $this->app->url('admin.php?module=claude_settings#instructions-change'), t('Check the instructions')];
            }
        }
        // recently edited content: pages and news together
        $edited = [];
        if (isset($modules['pages'])) {
            foreach ($db->all('SELECT public_id, title, updated_at, visible, build_draft IS NOT NULL AS has_draft FROM {pages} WHERE deleted_at IS NULL AND updated_at IS NOT NULL ORDER BY updated_at DESC LIMIT 6') as $r) {
                $edited[] = ['kind' => t('Page'), 'title' => $r['title'], 'edited' => (string) $r['updated_at'], 'url' => $this->app->url('admin.php?module=pages&action=edit&id=' . $r['public_id']),
                    'status' => !$r['visible'] ? t('hidden') : ($r['has_draft'] ? t('unpublished changes') : '')];
            }
        }
        if (isset($modules['news'])) {
            foreach ($db->all('SELECT c.public_id, c.title, COALESCE(c.edited_at, c.published_at) AS edited, c.visible, c.published_at > NOW() AS plan FROM {news} c WHERE 1 = 1' . $aliasedScope . ' ORDER BY COALESCE(c.edited_at, c.published_at) DESC LIMIT 6') as $r) {
                $edited[] = ['kind' => t('News item'), 'title' => $r['title'], 'edited' => (string) $r['edited'], 'url' => $this->app->url('admin.php?module=news&action=edit&id=' . $r['public_id']),
                    'status' => !$r['visible'] ? t('draft') : ($r['plan'] ? t('scheduled') : '')];
            }
        }
        usort($edited, fn (array $a, array $b): int => strcmp($b['edited'], $a['edited']));

        // Home shows the box only once Claude is connected; until then it stays above the requests with the note how to connect
        $ask = $this->askClaude();

        return $data + [
            'ask' => $ask !== null && $ask['connected'] ? $ask : null,
            // "Waiting for you" (3.2): drafts and proposals that wait for a person – only what this person may open
            'pending' => [
                // drafts of news authors wait for an editor – a row only when there are some
                ...(isset($modules['news']) && ($awaiting = Modules\News::countAwaitingPublication($this->app)) > 0
                    ? [['kind' => 'news_awaiting', 'label' => 'News from authors awaiting publication', 'count' => $awaiting, 'url' => 'admin.php?module=news&status=awaiting_publication', 'examples' => []]] : []),
                ...\Talea\Core\PendingReview::all($this->app, $modules),
            ],
            'firstSteps' => $this->firstSteps(),
            'warnings' => $warnings,
            // the site card: what the site is called, where it lives and what waits for publishing
            'site' => [
                'name' => $this->app->settings()->get('site_name'),
                'url' => rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . rtrim($this->app->url(''), '/'),
                'drafts' => isset($modules['pages']) ? (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE build_draft IS NOT NULL AND deleted_at IS NULL') : 0,
                'look' => $this->app->auth()->isAdmin() && \Talea\Core\Look::hasDraft($this->app->settings()),
            ],
            'edited' => array_slice($edited, 0, 6),
            'glance' => $this->glance($modules),
        ];
    }

    /**
     * "At a glance" on Home: visits, enquiries, bookings and pages of a period (?days=7|30|90) – each part only for whoever
     * may open its section; null when there is none of them.
     *
     * @param array<string, class-string<Module>> $modules
     * @return array<string, mixed>|null
     */
    private function glance(array $modules): ?array
    {
        $parts = array_intersect_key($modules, array_flip(['stats', 'enquiries', 'bookings', 'pages']));
        if ($parts === []) {
            return null;
        }
        $db = $this->app->db();
        $days = in_array($this->app->request->getInt('days'), self::GLANCE_PERIODS, true) ? $this->app->request->getInt('days') : 30;
        $report = \Talea\Core\Report::glance($db, $days);
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $before = date('Y-m-d 00:00:00', strtotime('-' . (2 * $days - 1) . ' days'));

        return ['days' => $days, 'parts' => array_keys($parts), 'report' => $report]
            + (isset($parts['enquiries']) ? ['unread' => (int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE status = 0')] : [])
            + (isset($parts['bookings']) ? ['bookings' => [
                'count' => (int) $db->value('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ?', [$since]),
                'previous' => (int) $db->value('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ?', [$before, $since]),
                'upcoming' => (int) $db->value("SELECT COUNT(*) FROM {bookings} WHERE status IN ('pending', 'confirmed') AND starts_at >= NOW()"),
                'next' => (string) $db->value("SELECT MIN(starts_at) FROM {bookings} WHERE status IN ('pending', 'confirmed') AND starts_at >= NOW()"),
            ]] : [])
            + (isset($parts['pages']) ? ['pages' => array_map(intval(...), $db->one('SELECT SUM(CASE WHEN visible = TRUE THEN 1 ELSE 0 END) AS published, SUM(CASE WHEN visible = FALSE THEN 1 ELSE 0 END) AS hidden,
                SUM(CASE WHEN build_draft IS NOT NULL THEN 1 ELSE 0 END) AS drafts FROM {pages} WHERE deleted_at IS NULL') ?? [])] : []);
    }

    /**
     * "Ask Claude" (3.1): a front door to the requests inbox, on Home and above the requests (#113) – only with the section and the
     * Claude connection on (not in the public demo, where the Claude connection is refused); it knows whether Claude was
     * connected at all (3.1.1). null = no box.
     *
     * @return array<string, mixed>|null
     */
    public function askClaude(): ?array
    {
        $modules = $this->modules();
        $db = $this->app->db();

        return isset($modules['requests']) && Extensions::isEnabled($this->app->settings(), 'claude') && !\Talea\Core\Demo::active() ? [
            'connected' => \Talea\Core\AskClaude::connected($db, $this->app->settings()),
            'status' => \Talea\Core\ClaudeConnection::status($db, $this->app->settings()), // #154: whether a request reaches Claude and how
            'examples' => \Talea\Core\AskClaude::examples($modules),
            'recent' => \Talea\Core\AskClaude::recent($db, $this->app->auth()->id()),
            'routine' => \Talea\Core\AskClaude::routine($db),
            'prompt' => \Talea\Core\AskClaude::prompt(rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . rtrim($this->app->url(''), '/'), \Talea\Core\Language::visitorAddress($this->app->settings())),
            'admin' => $this->app->auth()->isAdmin(),
        ] : null;
    }

    /**
     * First steps after installation: what is already done is recognized from the data, a step the administrator skipped
     * counts as done. Only the administrator sees them, until they hide or complete them.
     *
     * @return array<int, array{name: string, description: string, url: string, done: bool, skipped: bool, group: string}>
     */
    private function firstSteps(): array
    {
        $app = $this->app;
        $s = $app->settings();
        if (!$app->auth()->isAdmin() || $s->bool('first_steps_hidden')) {
            return [];
        }
        $db = $app->db();
        $steps = [
            // done only after the user's own choice: appearance and pages from the starter site do not count
            ['Give your site a face', 'Logo, main colour and fonts.', 'admin.php?module=appearance', $s->get('logo') !== '' || $s->bool('appearance_saved')],
            ['Fill in company details', 'Address, phone and opening hours appear on the contact page, in the footer and to search engines.', 'admin.php?module=business', $s->get('company_street') !== '' && ($s->get('company_phone') !== '' || $s->get('company_email') !== '' || $s->get('site_email') !== '')],
            ['Prepare your pages', 'About us, Services, Contact – and pick the home page in Settings.', 'admin.php?module=pages', (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE') >= 3 && $s->int('home_page') > 0
                && $db->value('SELECT 1 FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE AND updated_at IS NOT NULL LIMIT 1') !== null],
            ['Complete the privacy policy', 'The enquiry form collects personal data – visitors must know how you handle it. The page is prepared as a hidden draft: fill in the details in square brackets and publish it.', 'admin.php?module=pages',
                // done once the page exists and no longer contains the square brackets of the skeleton from the installation ([COMPANY NAME]…)
                $db->value("SELECT 1 FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE AND (" . implode(' OR ', array_map(fn (string $w): string => "slug LIKE '%" . $w . "%'", ['soukromi', 'osobni', 'osobnych', 'gdpr', 'dsgvo', 'privacy', 'datenschutz', 'privacidad', 'confidentialite', 'riservatezza', 'prywatnosc', 'prywatnosci'])) . ") AND text NOT LIKE '%[%]%' LIMIT 1") !== null],
            ['Set up e-mail', 'Where the site sends e-mail from (forms, password reset).', 'admin.php?module=settings&tab=mail', $s->get('mail_mode') === 'smtp' || $s->get('mail_from') !== ''],
            // 3.2: a suggestion, not an installer question – done once a blueprint is applied
            ['Your kind of business', 'Twenty blueprints – from a software company or a restaurant to a clinic or a trade – add the collections, facts and checks such a business needs; Claude can make one for any other.', 'admin.php?module=blueprints',
                $db->value('SELECT 1 FROM {blueprints} LIMIT 1') !== null],
        ];
        // #31, #46: setting up the site – three questions, then a look or the AI wizard, all as drafts; done once either has run
        array_unshift($steps, ['Set up your site', 'Three questions, then a look tried on your own site, the features and first pages you need – all as drafts. With your own AI key the assistant can write the texts.', 'admin.php?module=wizard',
            $db->value("SELECT 1 FROM {change_log} WHERE (module = 'assistant' AND action = 'wizard') OR (module = 'wizard' AND action = 'onboarding') LIMIT 1") !== null]);
        // 2.2: Claude is the main way to build and edit a Talea site – done once any user has connected it; the first step
        // since 3.1.1, because "Ask Claude" on the dashboard and the scheduled runs depend on it
        if (\Talea\Core\Extensions::isEnabled($s, 'claude')) {
            array_unshift($steps, ['Connect Claude', 'Build and edit the site by talking to Claude. In the Claude app, add a custom connector with your site address followed by /mcp – My account shows the exact address.',
                'admin.php?action=account#claude', \Talea\Core\AskClaude::connected($db, $s)]);
        }
        $skipped = $this->skippedFirstSteps();
        $result = array_map(fn (array $k): array => ['name' => $k[0], 'description' => $k[1], 'url' => $app->url($k[2]), 'done' => (bool) $k[3] || in_array($k[0], $skipped, true),
            'skipped' => !$k[3] && in_array($k[0], $skipped, true), 'group' => self::FIRST_STEP_GROUPS[$k[0]]], $steps);
        // Home shows the groups in the order of FIRST_STEP_GROUPS, the steps keep their order within a group
        $order = array_flip(array_values(array_unique(self::FIRST_STEP_GROUPS)));
        usort($result, fn (array $a, array $b): int => $order[$a['group']] <=> $order[$b['group']]);

        return array_filter($result, fn (array $k): bool => !$k['done']) === [] ? [] : $result;
    }

    /** @return list<string> names of the first steps the administrator skipped */
    private function skippedFirstSteps(): array
    {
        $skipped = json_decode($this->app->settings()->get('first_steps_skipped'), true);

        return is_array($skipped) ? array_values(array_filter($skipped, 'is_string')) : [];
    }

    /** Where to go after sign-in: if an app connection via OAuth (the Claude connector) is waiting, straight to the consent, otherwise to the overview. */
    private function resolveAfterSignIn(): string
    {
        return $this->app->url(is_array($this->app->session->get('pending_oauth')) ? 'admin.php?action=oauth' : 'admin.php');
    }

    private function login(): Response
    {
        $app = $this->app;
        $error = null;
        // the sign-in limits count the visitor's address behind the configured proxy, an IPv6 address by its /64 (3.3.3, N54)
        $address = \Talea\Core\Antispam::visitorKey($app->request, $app->settings());
        // a passkey (fingerprint, Face ID) on the first step or as the second: the script image/passkeys.js asks for a challenge and sends the device signature
        if ($app->request->isPost() && in_array($app->request->post('step'), ['passkey_options', 'key'], true)) {
            $url = $app->settings()->get('site_url') ?: $app->request->origin();
            if ($app->request->post('step') === 'passkey_options') {
                $options = $app->auth()->keyChallenge($url);

                return Response::json($options ?? ['error' => t('The sign-in has expired, please start again.')], $options === null ? 400 : 200);
            }
            $error = $app->auth()->verifyKey((array) json_decode((string) ($_POST['answer'] ?? ''), true), $url, $address, $app->request->postBool('remember'));
            if ($error === null) {
                ChangeLog::write($app, 'signed_in', 'login', 'passkey');
            }

            return Response::json($error === null ? ['ok' => true, 'redirect' => $this->resolveAfterSignIn()] : ['error' => $error], $error === null ? 200 : 401);
        }
        if ($app->request->isPost()) {
            $secondStep = $app->request->post('code') !== '' || $app->request->post('step') === 'code';
            $error = $secondStep
                ? $app->auth()->verifyCode($app->request->post('code'), $address)
                // the password as typed, not trimmed – as every place that sets one reads it (3.3.3, N61)
                : $app->auth()->login($app->request->post('username'), is_string($_POST['password'] ?? null) ? $_POST['password'] : '', $address, $app->request->postBool('remember'));
            if ($error === null && $app->auth()->user() !== null) {
                ChangeLog::write($app, 'signed_in', 'login', $secondStep ? 'two-step' : '');

                return Response::redirect($this->resolveAfterSignIn());
            }
            if ($error !== null && !$secondStep) {
                ChangeLog::write($app, 'signed_in', 'failed_attempt', 'account: ' . mb_substr($app->request->post('username'), 0, 40));
            }
        }

        return Response::html($app->view->render('admin/login', [
            'app' => $app,
            'error' => $error,
            'login' => $app->request->post('username'),
            'remember' => $app->request->postBool('remember'),
            'code' => $app->auth()->isAwaitingCode(),
            // the passkey button: after the password when the account has keys, on the first step when any account has one
            'keys' => $app->auth()->isAwaitingCode() ? $app->auth()->isAwaitingKey() : (int) $app->db()->value('SELECT COUNT(*) FROM {user_passkeys}') > 0,
        ]), $error === null ? 200 : 401);
    }

    /**
     * Consent to connecting an app via OAuth (the Claude connector): shows who is asking and with which permissions, and
     * after confirmation returns a one-time code to the app. The request waits in the session (Front\OAuth::authorize) for
     * at most 15 minutes.
     */
    private function handleOAuthConsent(): Response
    {
        $app = $this->app;
        $pending = $app->session->get('pending_oauth');
        if (!is_array($pending) || time() - (int) ($pending['time'] ?? 0) > 900) {
            $app->session->set('pending_oauth', null);

            return $this->page('Connect an application', $app->view->render('admin/error', ['app' => $app, 'text' => 'The request to connect the application has expired or does not exist. Start connecting again in the application.']), 400);
        }
        $oauth = new \Talea\Front\OAuth($app);
        if ($app->request->isPost()) {
            $app->session->set('pending_oauth', null);
            if (!$app->request->postBool('allow')) {
                return Response::redirect($oauth->deny($pending));
            }
            $access = \Talea\Front\OAuth::access($app->request->post('access') ?: 'drafts'); // no choice sent: drafts only, the default of the page
            $toolSet = \Talea\Mcp\Catalog::toolSet($app->request->post('tool_set')); // none sent: all tools, as before
            ChangeLog::write($app, 'claude', 'connect_app', mb_substr((string) $pending['name'] . ' (' . $access . ($toolSet === 'essentials' ? ', essentials' : '') . ')', 0, 100));

            return Response::redirect($oauth->issueCode($pending, $app->auth()->id(), $access, $toolSet));
        }

        // a focused frame without the admin menu and the draft-look bar – a stray Publish has no place here
        $page = Response::html($app->view->render('admin/bare', ['app' => $app, 'heading' => t('Connect an application'), 'content' => $app->view->render('admin/oauth', [
            'app' => $app, 'csrf' => $app->session->csrfField(), 'pending' => $pending, 'username' => $app->auth()->user(),
            'url' => (string) parse_url((string) $pending['redirect_uri'], PHP_URL_HOST), 'connected' => $oauth->isConnectedClient((string) $pending['client_id']),
        ])]));
        // sending the consent ends with a redirect to the app – CSP form-action must allow it (admin.php)
        $target = parse_url((string) $pending['redirect_uri']);
        $origin = ($target['scheme'] ?? '') . '://' . ($target['host'] ?? '') . (isset($target['port']) ? ':' . $target['port'] : '');

        return new Response($page->body, $page->status, $page->headers + ['X-Talea-Form-Action' => $origin]);
    }
}
