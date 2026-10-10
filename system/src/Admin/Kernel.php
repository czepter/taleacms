<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\App;
use Talea\Core\Migrator;
use Talea\Core\Response;
use Talea\Core\Extensions;

/**
 * Administration. URLs: admin.php?module=<ident>&action=<action>
 */
final class Kernel
{
    /**
     * Modules in the order they appear in the menu (by the groups Content, Appearance, Administration).
     *
     * @var list<class-string<Module>>
     */
    public const array MODULES = [
        Modules\Pages::class,
        Modules\News::class,
        Modules\Collections::class,
        Modules\Media::class,
        Modules\Newsletters::class,
        Modules\Categories::class,
        Modules\Tags::class,
        Modules\Business::class,
        Modules\Facts::class,
        Modules\Blueprints::class,
        Modules\Bookings::class,
        Modules\Enquiries::class,
        Modules\Subscribers::class,
        Modules\Stats::class,
        Modules\Appearance::class,
        Modules\SiteParts::class,
        Modules\Menu::class,
        Modules\Components::class,
        Modules\Popups::class,
        Modules\Requests::class,
        Modules\ClaudeSettings::class,
        Modules\Schedules::class,
        Modules\Notebook::class,
        Modules\Audit::class,
        Modules\Redirects::class,
        Modules\ChangeLog::class,
        Modules\Status::class,
        Modules\Users::class,
        Modules\Roles::class,
        Modules\Settings::class,
        Modules\Connectors::class,
        Modules\Extensions::class,
        Modules\Addons::class,
        Modules\Transfer::class,
        Modules\Fleet::class,
    ];

    public function __construct(public readonly App $app)
    {
    }

    public function handle(): Response
    {
        $app = $this->app;
        $request = $app->request;
        \Talea\Extension\Registry::boot($app); // add-ons (3.0): their admin pages and tools

        // admin language: the user's choice (My account); the sign-in page follows the site language. It is set first so that even the message about an expired form is translated
        $language = (string) ($app->auth()->user()['language'] ?? '') ?: \Talea\Core\Language::defaults($app->settings());
        \Talea\Core\Language::setAdminRegister((string) ($app->auth()->user()['register'] ?? ''));
        \Talea\Core\Language::setSiteRegister($app->settings()->get('german_register'));
        \Talea\Core\Language::set(isset(\Talea\Core\Language::ADMIN_LANGUAGES[$language]) ? $language : 'en', 'admin-');
        if ($request->isPost() && !$app->session->csrfValid($request)) {
            return $this->page('Invalid request', $app->view->render('admin/error', [
                'text' => 'The form has expired. Go back, reload the page and submit it again.',
            ]), 400);
        }

        // every change in the admin invalidates the site page cache; the editors' ongoing requests (unsaved state, assistant,
        // build draft) do not change the site - if they cleared the cache, it would be cold all the time during work
        if ($request->isPost() && !in_array($request->get('action'), ['draft', 'assistant', 'build_save', 'preview', 'build_ai_text'], true)) {
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

        // after moving to a new version, clean up the known removed files once (see Updater::REMOVED_FILES)
        if ($app->auth()->isAdmin() && $app->settings()->get('cleaned_version') !== TALEA_VERSION) {
            \Talea\Core\Updater::cleanUpRemoved(TALEA_ROOT);
            $app->settings()->set('cleaned_version', TALEA_VERSION);
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
        if ($ident === '') {
            $newVersion = $app->auth()->isAdmin() ? (new \Talea\Core\UpdateFeed($app->settings()))->available() : null;
            if ($newVersion !== null) {
                // a notice only: the update is the operator's act (docker compose pull && up -d), see docs/DEPLOYMENT.md
                $app->session->flash(!empty($newVersion['security']) ? 'error' : 'info', !empty($newVersion['security'])
                    ? t('A SECURITY update %s is available – update by pulling the new image and restarting.', (string) $newVersion['version'])
                    : t('A new version %s is available – update by pulling the new image and restarting.', (string) $newVersion['version']));
            }

            return $this->page('', $app->view->render('admin/dashboard', $this->desktop()));
        }
        $class = $this->modules()[$ident] ?? null;
        if ($class === null) {
            return $this->page('Error', $app->view->render('admin/error', ['text' => 'You do not have access to this module.']), 403);
        }

        $response = (new $class($this))->handle($action === '' ? 'list' : $action);
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
        $scope = ' AND deleted_at IS NULL' . $this->app->auth()->articleScope();      // for queries without an alias (news in the trash are not counted)
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

        // "Ask Claude" (3.1): a front door to the requests inbox – only with the section and the Claude connection on
        // (not in the public demo, where the Claude connection is refused); it knows whether Claude was connected at all (3.1.1)
        $ask = isset($modules['requests']) && Extensions::isEnabled($this->app->settings(), 'claude') && !\Talea\Core\Demo::active() ? [
            'connected' => \Talea\Core\AskClaude::connected($db),
            'examples' => \Talea\Core\AskClaude::examples($modules),
            'recent' => \Talea\Core\AskClaude::recent($db, $this->app->auth()->id()),
            'routine' => \Talea\Core\AskClaude::routine($db),
            'prompt' => \Talea\Core\AskClaude::prompt(rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . rtrim($this->app->url(''), '/'), \Talea\Core\Language::visitorAddress($this->app->settings())),
            'admin' => $this->app->auth()->isAdmin(),
        ] : null;

        return $data + [
            'ask' => $ask,
            // "Waiting for you" (3.2): drafts and proposals that wait for a person – only what this person may open
            'pending' => \Talea\Core\PendingReview::all($this->app, $modules),
            'firstSteps' => $this->firstSteps(),
            'warnings' => $warnings,
            // traffic for 14 days (own measurement without cookies)
            'traffic' => Extensions::isEnabled($this->app->settings(), 'stats') && isset($modules['stats'])
                ? $db->all('SELECT day, visits, views FROM {stats_days} WHERE day > CURDATE() - INTERVAL 14 DAY ORDER BY day') : [],
            'counts' => array_filter([
                // drafts of news authors wait for an editor – the tile only when there are some
                'News from authors awaiting publication' => isset($modules['news']) && ($pending = Modules\News::countAwaitingPublication($this->app)) > 0 ? [$pending, 'admin.php?module=news&status=awaiting_publication'] : null,
                'New enquiries' => isset($modules['enquiries']) ? [(int) $db->value('SELECT COUNT(*) FROM {enquiries} WHERE status = 0'), 'admin.php?module=enquiries'] : null,
                'Published pages' => isset($modules['pages']) ? [(int) $db->value('SELECT COUNT(*) FROM {pages} WHERE visible = 1 AND deleted_at IS NULL'), 'admin.php?module=pages'] : null,
                'Pages with unpublished changes' => isset($modules['pages']) ? [(int) $db->value('SELECT COUNT(*) FROM {pages} WHERE build_draft IS NOT NULL AND deleted_at IS NULL'), 'admin.php?module=pages'] : null,
                'Published news' => isset($modules['news']) ? [(int) $db->value("SELECT COUNT(*) FROM {news} WHERE visible = 1 AND published_at <= NOW(){$scope}"), 'admin.php?module=news&status=published'] : null,
                'News drafts' => isset($modules['news']) ? [(int) $db->value("SELECT COUNT(*) FROM {news} WHERE visible = 0{$scope}"), 'admin.php?module=news&status=drafts'] : null,
            ]),
            'enquiries' => isset($modules['enquiries']) ? $db->all('SELECT enquiry_id, public_id, created_at, form, email, status FROM {enquiries} ORDER BY enquiry_id DESC LIMIT 5') : [],
            'edited' => array_slice($edited, 0, 8),
        ];
    }

    /**
     * First steps after installation: what is already done is recognized from the data. Only the administrator sees them,
     * until they hide or complete them.
     *
     * @return list<array{nazev:string, popis:string, url:string, hotovo:bool}>
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
            ['Prepare your pages', 'About us, Services, Contact – and pick the home page in Settings.', 'admin.php?module=pages', (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NULL AND visible = 1') >= 3 && $s->int('home_page') > 0
                && $db->value('SELECT 1 FROM {pages} WHERE deleted_at IS NULL AND visible = 1 AND updated_at IS NOT NULL LIMIT 1') !== null],
            ['Complete the privacy policy', 'The enquiry form collects personal data – visitors must know how you handle it. The page is prepared as a hidden draft: fill in the details in square brackets and publish it.', 'admin.php?module=pages',
                // done once the page exists and no longer contains the square brackets of the skeleton from the installation ([COMPANY NAME]…)
                $db->value("SELECT 1 FROM {pages} WHERE deleted_at IS NULL AND visible = 1 AND (" . implode(' OR ', array_map(fn (string $w): string => "slug LIKE '%" . $w . "%'", ['soukromi', 'osobni', 'osobnych', 'gdpr', 'dsgvo', 'privacy', 'datenschutz', 'privacidad', 'confidentialite', 'riservatezza', 'prywatnosc', 'prywatnosci'])) . ") AND text NOT LIKE '%[%]%' LIMIT 1") !== null],
            ['Set up e-mail', 'Where the site sends e-mail from (forms, password reset).', 'admin.php?module=settings&tab=mail', $s->get('mail_mode') === 'smtp' || $s->get('mail_from') !== ''],
            // 3.2: a suggestion, not an installer question – done once a blueprint is applied
            ['Your kind of business', 'Twenty blueprints – from a software company or a restaurant to a clinic or a trade – add the collections, facts and checks such a business needs; Claude can make one for any other.', 'admin.php?module=blueprints',
                $db->value('SELECT 1 FROM {blueprints} LIMIT 1') !== null],
        ];
        // 2.2: Claude is the main way to build and edit a Talea site – done once any user has connected it; the first step
        // since 3.1.1, because "Ask Claude" on the dashboard and the scheduled runs depend on it
        if (\Talea\Core\Extensions::isEnabled($s, 'claude')) {
            array_unshift($steps, ['Connect Claude', 'Build and edit the site by talking to Claude. In the Claude app, add a custom connector with your site address followed by /mcp – My account shows the exact address.',
                'admin.php?action=account#claude', \Talea\Core\AskClaude::connected($db)]);
        }
        $result = array_map(fn (array $k): array => ['name' => $k[0], 'description' => $k[1], 'url' => $app->url($k[2]), 'done' => (bool) $k[3]], $steps);

        return array_filter($result, fn (array $k): bool => !$k['done']) === [] ? [] : $result;
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
        $address = \Talea\Core\Firewall::visitorKey($app->request, $app->settings());
        // second step with a passkey (fingerprint, Face ID): the script image/passkeys.js asks for a challenge and sends the device signature
        if ($app->request->isPost() && in_array($app->request->post('step'), ['passkey_options', 'key'], true)) {
            $url = $app->settings()->get('site_url') ?: $app->request->origin();
            if ($app->request->post('step') === 'passkey_options') {
                $options = $app->auth()->keyChallenge($url);

                return Response::json($options ?? ['error' => t('The sign-in has expired, please start again.')], $options === null ? 400 : 200);
            }
            $error = $app->auth()->verifyKey((array) json_decode((string) ($_POST['answer'] ?? ''), true), $url, $address);
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
                : $app->auth()->login($app->request->post('username'), is_string($_POST['password'] ?? null) ? $_POST['password'] : '', $address);
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
            'code' => $app->auth()->isAwaitingCode(),
            'keys' => $app->auth()->isAwaitingKey(),
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

            return $this->page('Connect an application', $app->view->render('admin/error', ['text' => 'The request to connect the application has expired or does not exist. Start connecting again in the application.']), 400);
        }
        $oauth = new \Talea\Front\OAuth($app);
        if ($app->request->isPost()) {
            $app->session->set('pending_oauth', null);
            if (!$app->request->postBool('allow')) {
                return Response::redirect($oauth->deny($pending));
            }
            $access = \Talea\Front\OAuth::access($app->request->post('access') ?: 'full'); // a consent page from before 2.2 sends none: as before
            ChangeLog::write($app, 'claude', 'connect_app', mb_substr((string) $pending['name'] . ' (' . $access . ')', 0, 100));

            return Response::redirect($oauth->issueCode($pending, $app->auth()->id(), $access));
        }

        $page = $this->page('Connect an application', $app->view->render('admin/oauth', [
            'app' => $app, 'csrf' => $app->session->csrfField(), 'pending' => $pending, 'username' => $app->auth()->user(),
            'url' => (string) parse_url((string) $pending['redirect_uri'], PHP_URL_HOST),
        ]));
        // sending the consent ends with a redirect to the app – CSP form-action must allow it (admin.php)
        $target = parse_url((string) $pending['redirect_uri']);
        $origin = ($target['scheme'] ?? '') . '://' . ($target['host'] ?? '') . (isset($target['port']) ? ':' . $target['port'] : '');

        return new Response($page->body, $page->status, $page->headers + ['X-Talea-Form-Action' => $origin]);
    }
}
