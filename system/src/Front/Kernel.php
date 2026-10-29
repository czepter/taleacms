<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Core\Extensions;

/**
 * Public part of the site.
 *
 *   /                           home page ("Settings → General"), without it the news listing
 *   /news                    news listing
 *   /news/<seo-link>         news item (+ .md for language models)
 *   /<slug>.md               a page or a collection item as Markdown for language models
 *   /news/category/<seo>    news in a category
 *   /news/tag/<seo>       news with a tag
 *   /search?q=...              search
 *   /rss.xml                    RSS feed of news
 *   /<slug>                     page
 *   /screen/<secret>            screen mode for a TV in the reception (2.11, Front\Screen)
 *   robots.txt, sitemap.xml, llms.txt, feed.json... see Seo
 */
final class Kernel
{
    private readonly NewsRepository $news;
    private readonly PageFrame $frame;
    private readonly NewsPages $newsPages;
    private readonly CollectionPages $collectionPages;
    private readonly Endpoints $endpoints;

    /** System URL in a foreign form (/news on the English site) – redirect to the valid one (Core\Routes). */
    private ?Response $redirect = null;

    /** A preview of the whole site with all drafts and the draft look (signed link, target "web"). */
    private bool $sitePreview = false;

    /** The answer of an add-on's early request hook (a refusal), null = the request goes on. */
    private ?Response $early = null;

    public function __construct(private readonly App $app)
    {
        $app->request->setOrigin($app->settings()->get('site_url'));
        $app->applyTimezone();
        \Talea\Extension\Registry::boot($app); // add-ons (3.0)
        $this->early = \Talea\Extension\Registry::runEarly($app); // early request hooks of add-ons (API 2): before routing, sessions and the cache
        // language version: /en/news/x -> language "en", path "/news/x"; URLs from $app->url() then get the prefix
        // automatically
        $language = Language::defaults($app->settings());
        if (preg_match('#^/(' . Language::TAG . ')(/.*)?$#', $app->request->path(), $m) && in_array($m[1], Language::additional($app->settings()), true)) {
            $language = $m[1];
            $app->languagePrefix = $m[1];
            $app->request->setPath($m[2] ?? '/');
        }
        Language::setSite($app->settings(), $language);
        // system URLs in the version's language (/news ↔ /news): the internal form is used from here on, a foreign form
        // redirects
        [$internal, $canonicalUrl] = \Talea\Core\Routes::internalPath($app->request->path(), $app->db());
        if ($canonicalUrl !== $app->request->path() && !$app->request->isPost()) {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $this->redirect = Response::redirect($app->url(ltrim($internal, '/')) . ($query !== '' ? '?' . $query : ''), 301);
        }
        // an old address with a stored redirect (import, slug change) goes to its target in one step, not through the slash form first;
        // only the indexed exact lookup runs on every request, the other match types wait for the 404 (notFound())
        if ($this->redirect === null && $this->redirectRule($internal, true) === null && ($slash = $this->slashRedirect($internal)) !== null) {
            $this->redirect = Response::redirect($slash, 301);
        }
        // /page.html is the same page as /page (url_slash = html)
        $app->request->setPath(\Talea\Core\Routes::pageLike($internal) ? (string) preg_replace('#\.html$#', '', $internal) : $internal);
        $this->startSitePreview();
        $this->frame = new PageFrame($app, $this, $this->sitePreview);
        $this->news = new NewsRepository($app->db(), $app->settings(), $app->request->basePath());
        $this->newsPages = new NewsPages($app, $this->frame, $this->news, $this);
        $this->collectionPages = new CollectionPages($app, $this->frame, $this);
        $this->endpoints = new Endpoints($app, $this->frame, $this->news, $this);
    }

    /** The maintenance notice (setting maintenance_text) with 503. */
    private function maintenance(string $retryAfter): Response
    {
        return new Response('<!doctype html><html lang="' . e(Language::tag(Language::code())) . '"' . Language::dirAttribute(Language::tag(Language::code())) . '><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($this->app->settings()->get('site_name')) . '</title>'
            . '<body style="font:18px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;padding:24px;text-align:center"><div><h1 style="font-size:28px">' . e($this->app->settings()->get('site_name'))
            . '</h1><p>' . e($this->app->settings()->get('maintenance_text')) . '</p></div>', 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => $retryAfter]);
    }

    /**
     * Setting redirect_to_site_url: a GET on another host (with or without www, an old domain) gets a 301 to the same address on
     * site_url. The probe, cron, Claude (/mcp, OAuth, .well-known) and previews (/_popup, preview_key) answer on any host; the
     * administration has its own kernel and is never redirected.
     */
    private function hostRedirect(): ?Response
    {
        $site = $this->app->settings()->get('site_url');
        $r = $this->app->request;
        if ($site === '' || !$this->app->settings()->bool('redirect_to_site_url') || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
            || $r->has('preview_key') || preg_match('#^/(health|tasks|mcp|oauth/.*|\.well-known/.*|_.*)$#', $r->path()) === 1) {
            return null;
        }
        $port = parse_url($site, PHP_URL_PORT);
        $own = strtolower((string) parse_url($site, PHP_URL_HOST)) . ($port !== null ? ':' . $port : '');
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return $host === '' || $host === $own ? null : Response::redirect($site . (str_starts_with($uri, '/') ? $uri : '/'), 301);
    }

    /** 301 target when the request uses the non-preferred slash form (setting url_slash), else null. */
    private function slashRedirect(string $internal): ?string
    {
        if ($this->app->request->isPost() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return null;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return \Talea\Core\Routes::slashRedirect($internal, $uri, $this->app->settings()->get('url_slash'));
    }

    public function handle(): Response
    {
        $request = $this->app->request;
        if ($this->early !== null) {
            return $this->early;
        }
        if (($host = $this->hostRedirect()) !== null) {
            return $host;
        }
        if ($this->redirect !== null) {
            return $this->redirect;
        }
        // the public demo (2.6) offers no Claude connection: anyone could connect to the shared admin
        if (\Talea\Core\Demo::active() && preg_match('#^/(mcp|oauth|\.well-known/oauth|\.well-known/openid)#', $request->path())) {
            return new Response('{"error":"The Claude connection is switched off in the public demo."}', 403, ['Content-Type' => 'application/json']);
        }
        // a running Talea import (Core\SiteImport::work) replaces the content: everyone, signed in or not, and Claude (/mcp, OAuth) wait for it;
        // the orchestrator probe and cron go on – cron carries the import forward
        if (\Talea\Core\SiteImport::running() !== null && !in_array($request->path(), ['/health', '/tasks'], true)) {
            return $this->maintenance('60');
        }
        // OAuth for the Claude connector (metadata, registration, tokens) – runs in maintenance mode too, just like /mcp
        if (($oauth = (new OAuth($this->app))->handle($request->path())) !== null) {
            return $oauth;
        }
        if ($this->app->settings()->bool('maintenance') && $request->path() !== '/mcp' && $this->app->auth()->user() === null) {
            return $this->maintenance('3600');
        }
        // an old numeric WordPress URL /?p=123 (after import): its path is the home page, which always exists, so the redirect
        // on a 404 error would never be reached – it is therefore looked up by the parameter, even before the cache
        // also Polylang's language home ?lang=en, stored as ?lang=en by the WordPress import
        $lang = preg_match('/^' . Language::TAG . '$/D', $request->get('lang')) === 1 ? $request->get('lang') : '';
        if (($request->getInt('p') > 0 || $lang !== '') && $request->path() === '/' && Extensions::isEnabled($this->app->settings(), 'redirects')) {
            $target = $this->app->db()->one('SELECT * FROM {redirects} WHERE from_path = ? AND active = TRUE', [$request->getInt('p') > 0 ? '?p=' . $request->getInt('p') : '?lang=' . $lang]);
            if ($target !== null) {
                return $this->followRule($target + ['target' => $target['to_path']]);
            }
        }
        // a file waiting for approval (#150, Core\MediaStaging): for signed-in users and draft previews; everyone else gets the
        // usual 404, unless published content already uses it
        if (str_starts_with($request->path(), '/media/')
            && ($waiting = \Talea\Core\MediaStaging::serve($this->app, $request->path(), fn (): bool => $this->sitePreview || \Talea\Core\MediaStaging::hasPreview($this->app))) !== null) {
            return $waiting;
        }
        if (($cached = Cache::load($this->app)) !== null) {
            return $cached;
        }
        $path = $request->path();
        if ($path === '/' || $path === '/index.php') {
            return $this->home();
        }
        $withNews = Extensions::isEnabled($this->app->settings(), 'news');
        if (!$withNews && ($path === '/news' || str_starts_with($path, '/news/') || $path === '/rss.xml' || $path === '/feed.json')) {
            return $this->notFound(); // the News extension is disabled: the data stay, but are not on the site
        }
        if ($path === '/news') {
            return $this->newsPages->showNewsList();
        }
        if (preg_match('#^/news/category/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsPages->category($m[1]);
        }
        if (preg_match('#^/news/tag/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsPages->tag($m[1]);
        }
        if (preg_match('#^/news/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_versions')) {
            return $this->newsPages->newsMarkdown($m[1]);
        }
        if (preg_match('#^/news/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsPages->newsItem($m[1]);
        }
        if (preg_match('#^/([a-z0-9][a-z0-9/-]*)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_versions')) {
            return $this->newsPages->markdown($m[1]);
        }
        if ($path === '/search') {
            return $this->newsPages->search();
        }
        if ($path === '/rss.xml') {
            return $this->newsPages->rss();
        }
        if ($path === '/manifest.webmanifest') {
            return $this->endpoints->manifest();
        }
        if ($path === '/favicon.ico') {
            return $this->endpoints->favicon();
        }
        if (preg_match('#^/og/([a-f0-9]{32})\.png$#', $path, $m)) {
            return $this->endpoints->shareImage($m[1]);
        }
        if ($path === '/robots.txt') {
            return $this->endpoints->robots();
        }
        if ($path === '/sitemap.xml') {
            return $this->endpoints->sitemap();
        }
        if ($path === '/feed.json') {
            return $this->endpoints->jsonFeed();
        }
        if ($path === '/.well-known/security.txt' && $this->app->settings()->get('security_contact') !== '') {
            return $this->endpoints->securityTxt();
        }
        if ($path === '/llms.txt' && $this->app->settings()->bool('llms_txt')) {
            return $this->endpoints->llmsTxt();
        }
        if (preg_match('#^/(.+)\.txt$#Ds', $path, $m) && ($key = $this->endpoints->indexNowKey($m[1])) !== null) {
            return $key;
        }
        if ($path === '/consent' && $request->isPost()) {
            return $this->endpoints->consent();
        }
        if ($path === '/popup' && $request->isPost()) {
            return $this->endpoints->popup();
        }
        if (in_array($path, ['/fleet/pair', '/fleet/heartbeat', '/fleet/unpair', '/fleet/kit'], true) && $request->isPost() && Extensions::isEnabled($this->app->settings(), 'fleet')) {
            return $this->endpoints->fleet(substr($path, 7));
        }
        if ($path === '/vitals' && $request->isPost()) {
            return $this->endpoints->vitals();
        }
        if ($path === '/conversion' && $request->isPost()) {
            return $this->endpoints->conversion();
        }
        if ($path === '/experiment' && $request->isPost()) {
            return $this->endpoints->experiment();
        }
        if ($path === '/mcp') {
            return $this->endpoints->mcp();
        }
        if (preg_match('#^/download/([A-Za-z0-9._-]{30,900})$#', $path, $m)) {
            return $this->endpoints->download($m[1]);
        }
        $subscriptionLink = $request->get('confirm') !== '' || $request->get('unsubscribe') !== '';
        if ($path === '/subscribe' && ($subscriptionLink || Extensions::isEnabled($this->app->settings(), 'newsletter_signup'))) {
            return $this->endpoints->subscribe();
        }
        if (($path === '/member' || str_starts_with($path, '/member/')) && Extensions::isEnabled($this->app->settings(), 'members')) {
            return $this->memberArea($path); // member login (Core\Members): sign-in link, sign-in, sign-out
        }
        if (($routed = \Talea\Extension\Registry::runRoute($this->app, $path)) !== null) {
            // an address of an add-on (Api::route): private, no-store, noindex, never cached
            return $routed instanceof Response ? new Response($routed->body, $routed->status, array_merge($routed->headers, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'])) : $this->gatedPage($routed);
        }
        if ($path === '/form' && Extensions::isEnabled($this->app->settings(), 'enquiries')) {
            return $this->endpoints->form();
        }
        // online booking (3.0, Front\Booking): the free days and times as JSON, the booking itself, the customer's cancel page and
        // the .ics file – the links in the e-mails carry a token only the customer has. The booking itself and the free times
        // only while the Bookings feature is on (3.2); the cancel page and the .ics file keep working when it is switched off,
        // so people who booked can still cancel or add the appointment to their calendar (3.2.3)
        $bookingsOn = \Talea\Core\Booking::isOn($this->app->settings());
        if ($path === '/_booking' && $request->isPost() && $bookingsOn) {
            return $this->endpoints->booking();
        }
        if (($path === '/_booking/days' || $path === '/_booking/slots') && $bookingsOn) {
            return $this->endpoints->bookingAvailability(substr($path, 10));
        }
        if (preg_match('#^/_booking/cancel/([a-f0-9]{32})$#', $path, $m)) {
            return $this->endpoints->bookingCancel($m[1]);
        }
        if (preg_match('#^/_booking/choose/([a-f0-9]{32})$#', $path, $m)) {
            return $this->endpoints->bookingChoose($m[1]);
        }
        if (preg_match('#^/_booking/ics/([a-f0-9]{32})$#', $path, $m)) {
            return $this->endpoints->bookingIcs($m[1]);
        }
        if ($path === '/health') {
            return $this->endpoints->health();
        }
        if ($path === '/tasks') {
            return $this->endpoints->tasks();
        }
        if (preg_match('#^/screen/([a-f0-9]{32})$#', $path, $m)) {
            return $this->endpoints->screen($m[1]);
        }
        if ($path === '/status.json') {
            return $this->endpoints->status();
        }

        // a hidden page is visible only in the builder preview (whoever can edit pages) and via a signed preview link
        // (?preview_key=…, Core\Preview)
        $showHidden = $this->sitePreview || ($request->get('build') === 'draft' && ($this->app->auth()->hasModule('pages') || $request->get('preview_key') !== ''));
        $page = $this->app->db()->one('SELECT * FROM {pages} WHERE slug = ? AND language = ? AND deleted_at IS NULL' . ($showHidden ? '' : ' AND visible = TRUE'), [ltrim($path, '/'), Language::siteColumn()]);
        if ($page !== null && !$page['visible'] && !$this->canSeeDraft('page:' . (int) $page['page_id'])) {
            $page = null;
        }
        if ($page !== null) {
            if ((int) $page['page_id'] === $this->frame->homePageId() && !$showHidden) {
                return Response::redirect($this->app->url(''), 301); // the home page has only one URL – the site root
            }

            return $this->showPage($page, ltrim($path, '/'));
        }
        if (preg_match('#^/_section/([a-z0-9-]{1,40})$#', $path, $m) && $this->app->auth()->hasModule('pages')) {
            return $this->previewSection($m[1]);
        }
        if (preg_match('#^/_popup/([a-f0-9-]{36})$#', $path, $m)) {
            return $this->previewPopup($this->app->db()->internalId('popups', $m[1]));
        }
        if (preg_match('#^/_component/([a-f0-9-]{36})$#', $path, $m) && $this->app->auth()->isAdmin()) {
            return $this->previewComponent($this->app->db()->internalId('components', $m[1]));
        }
        if (preg_match('#^/([a-z0-9-]{1,110})(?:/([a-z0-9-]{1,160}))?\.ics$#', $path, $m) && ($calendar = $this->collectionPages->calendarFile($m[1], $m[2] ?? '')) !== null) {
            return $calendar;
        }
        if (preg_match('#^/_testimonial/([a-f0-9]{32})$#', $path, $m)) {
            return $this->collectionPages->testimonialPage($m[1]);
        }
        if ($path === '/_comment' && $request->isPost()) {
            return $this->endpoints->comment();
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/_compare$#', $path, $m)) {
            return $this->collectionPages->compareProducts($m[1]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $path, $m) && $m[1] !== 'news') {
            return $this->collectionPages->showCollectionItem($m[1], $m[2]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})/latest$#', $path, $m) && $m[1] !== 'news') {
            return $this->collectionPages->latest($m[1], $m[2]);
        }

        return $this->notFound();
    }

    /**
     * Preview of a ready-made library section for the builder panel: only the section in the site's appearance, without
     * header and footer. Library classes are only rendered from their default style – nothing is written to the site.
     */
    private function previewSection(string $key): Response
    {
        $section = \Talea\Builder\Library::section($key, Language::code());
        if ($section === null) {
            return $this->notFound();
        }
        $k = new \Talea\Builder\Context($this->app);
        $html = \Talea\Builder\Renderer::html(['children' => [$section['element']]], $k);
        $classes = '';
        foreach ($section['classes'] as $t) {
            $classes .= \Talea\Builder\Style::css('.' . $t, \Talea\Builder\Library::CLASSES[$t] ?? []);
        }
        $k->classes = []; // class styles above come from the library, not from the site database (the class may not exist on the site yet)
        $siteSettings = $this->app->settings();
        $css = \Talea\Builder\DesignSystem::css(\Talea\Builder\DesignSystem::load($siteSettings), $this->app->request->basePath()) . \Talea\Builder\Renderer::css($this->app->db(), $k) . '@layer classes {' . $classes . '}';
        $layout = $this->app->url('image/template.css');

        return new Response('<!doctype html><html lang="' . e(Language::tag(Language::code())) . '"' . Language::dirAttribute(Language::tag(Language::code())) . '><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<link rel="stylesheet" href="' . e($layout) . '"><link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '"><style>' . $css . 'body{margin:0}</style></head>'
            . '<body><main class="build">' . $html . '</main></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Canvas of the component editor (administrator only): the draft component with default property values. */
    private function previewComponent(int $idm): Response
    {
        $component = \Talea\Builder\Components::byId($this->app->db(), $idm);
        if ($component === null) {
            return $this->notFound();
        }
        $k = $this->frame->context();
        $k->item = \Talea\Builder\Components::values($component, []);
        $k->editor = $this->app->request->get('editor') === '1';
        $html = \Talea\Builder\Renderer::html(\Talea\Builder\Build::fromJson($component['build_draft'] ?? $component['build']) ?? ['children' => []], $k);
        [$k->item, $k->editor] = [null, false];

        return $this->frame->page($component['name'], $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    private function home(): Response
    {
        $page = ($id = $this->frame->homePageId()) > 0 ? $this->app->db()->one('SELECT * FROM {pages} WHERE page_id = ? AND visible = TRUE', [$id]) : null;

        if ($page === null && !Extensions::isEnabled($this->app->settings(), 'news')) {
            // without a home page and without news: the site's first published page
            $page = $this->app->db()->one('SELECT * FROM {pages} WHERE visible = TRUE AND deleted_at IS NULL AND language = ? ORDER BY sort_order, page_id LIMIT 1', [Language::siteColumn()]);
        }

        return $page !== null ? $this->showPage($page, '', true) : (Extensions::isEnabled($this->app->settings(), 'news') ? $this->newsPages->showNewsList(true) : $this->notFound());
    }

    /** @param array<string, mixed> $page */
    private function showPage(array $page, string $path, bool $home = false): Response
    {
        $this->frame->counterpart = ['pages', 'page_id', $page, ''];
        $this->frame->isHome = $home;
        if (($gate = $this->gate('page', (int) $page['page_id'], (string) $page['title'], 'page:' . (int) $page['page_id'])) !== null) {
            return $gate; // restricted to member groups (Core\Members)
        }
        if (!$home) {
            // subpage: parent pages in the breadcrumbs too (by slug services/kitchens → services)
            $levels = [];
            $segments = explode('/', (string) $page['slug']);
            for ($i = 1; $i < count($segments); $i++) {
                $parent = $this->app->db()->one('SELECT title, slug FROM {pages} WHERE slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL', [implode('/', array_slice($segments, 0, $i)), $page['language']]);
                if ($parent !== null) {
                    $levels[] = [$parent['title'], $this->app->url($parent['slug'])];
                }
            }
            $this->frame->breadcrumbs(...[...$levels, [(string) ($page['breadcrumb_title'] ?? '') !== '' ? $page['breadcrumb_title'] : $page['title'], '']]);
        }
        // a password-protected page (2.14, Core\PageLock): the password form until the visitor enters it; editors see it
        // without one. Protected pages are noindex, so they never enter the page cache
        $locked = \Talea\Core\PageLock::isProtected($page);
        if ($locked && !$this->app->auth()->hasModule('pages') && !\Talea\Core\PageLock::isUnlocked($this->app, $page)) {
            $error = $this->app->request->isPost() ? \Talea\Core\PageLock::unlock($this->app, $page, $this->app->request->post('tl_page_password')) : '';
            if ($this->app->request->isPost() && $error === '') {
                return Response::redirect($this->app->url($home ? '' : (string) $page['slug']), 303);
            }

            $k = $this->frame->context();
            $k->types['form'] = true; // the form styles
            $k->types[\Talea\Builder\Elements\EnquiryButton::TYPE] = true; // the page frame
            $k->withoutCache = true;

            return $this->frame->page((string) $page['title'], $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => \Talea\Core\PageLock::form($page, $error)]),
                ['build' => true, 'noindex' => true], $error !== '' ? 403 : 200);
        }
        // title and data for search engines and sharing (custom title, image, noindex – as with news)
        $title = $page['seo_title'] !== '' ? $page['seo_title'] : ($home ? '' : $page['title']);
        $meta = [
            'description' => $page['description'] !== '' ? $page['description'] : ($home ? $this->app->settings()->get('site_description') : ''),
            'main' => $home, 'image' => $page['image'], 'noindex' => (bool) $page['noindex'] || $locked,
            'head_code' => (string) ($page['head_code'] ?? ''), // code in <head> of this page only (2.3)
            'markdown' => $locked || $this->frame->gated || !$page['visible'] ? null : (string) $page['slug'], // markdownVersion() answers none of them
        ] + $this->frame->seoMeta($home ? 'home' : 'page', $page);
        // preview of the draft build for the editor: ?build=draft (only whoever can edit pages), &editor=1 adds markers
        // for selecting elements
        $draft = $this->wantsDraft() && $this->canSeeDraft('page:' . (int) $page['page_id']);
        $build = \Talea\Builder\Build::fromJson($draft ? ($page['build_draft'] ?? $page['build']) : $page['build']);
        if ($build !== null) {
            $k = $this->frame->context();
            $k->editor = $draft && $this->app->request->get('editor') === '1' && $this->app->request->get('part') === '' && $this->app->request->get('component') === '';
            // "Edit here" on a component (Admin\Modules\Components): the page around it without markers, the chosen use shows the component's draft with them
            if ($draft && $this->app->request->get('editor') === '1' && $this->app->request->get('component') !== '' && $this->app->auth()->isAdmin()) {
                $k->focus = [$this->app->db()->internalId('components', $this->app->request->get('component')), $this->app->request->get('use')];
            }
            // comment mode (2.15, Core\DraftComments): a shared link whose key allows comments marks the elements and adds the widget
            $previewKey = $this->app->request->get('preview_key');
            $k->markIds = $draft && !$k->editor && $previewKey !== '' && \Talea\Core\Preview::allowsComments($this->app->db(), $this->app->settings(), 'page:' . (int) $page['page_id'], $previewKey);
            if ($k->markIds) {
                $meta['comments'] = (new DraftComments($this->app))->widget('page:' . $this->app->db()->publicId('pages', (int) $page['page_id']), $previewKey, $path);
            }
            // "SEO on the page" (Website mode, ?seo_marks=1): what the SEO check finds, marked on the elements of the draft preview
            // for whoever edits pages, in the language of their administration; a visitor's request ignores it (and a signed-in
            // request is never cached)
            if ($draft && !$k->editor && $this->app->request->get('seo_marks') === '1' && ($auth = $this->app->auth())->hasModule('pages')) {
                $k->seoMarks = Language::runWith(Language::adminCode($auth->user(), $this->app->settings()), fn (): array => \Talea\Core\ContentCheck::marks($build, \Talea\Core\SeoFields::keywords((string) $page['focus_keywords'])),
                    'admin-', Language::normalizeRegister((string) ($auth->user()['register'] ?? '')));
                $meta['seo_marks'] = true;
            }
            $k->source = 'page:' . $this->app->db()->publicId('pages', (int) $page['page_id']);
            // A/B tests (Builder\Experiments): the published page carries both versions, the head picks one in the browser
            if (!$draft) {
                $db = $this->app->db();
                $running = \Talea\Builder\Experiments::forPage($db, (int) $page['page_id']);
                $reaching = \Talea\Builder\Experiments::goalsOnPage($db, (int) $page['page_id']);
                if ($running !== []) {
                    $build = \Talea\Builder\Experiments::expand($build, $running, $db, $k);
                }
                $meta['experiments'] = \Talea\Builder\Experiments::config($this->app, $running, $reaching, (string) parse_url($this->app->url($home ? '' : ltrim((string) $page['slug'], '/')), PHP_URL_PATH));
            }
            $html = \Talea\Builder\Renderer::html($build, $k);
            $k->editor = false;
            $k->markIds = false;
            $k->seoMarks = [];
            $k->focus = null;
            if (!$draft && $this->app->auth()->hasModule('pages')) {
                $this->frame->editHereUrl = $this->app->url('admin.php?module=pages&action=builder&id=' . $this->app->db()->publicId('pages', (int) $page['page_id']));
            }

            if ($meta['description'] === '') {
                $meta['description'] = self::descriptionFrom($html);
            }

            return $this->frame->page($title, $this->frame->view->render('page', ['page' => $page, 'intro' => $home, 'build' => $html]), [
                'build' => true, 'noindex' => $draft || $meta['noindex'],
            ] + $meta);
        }
        if (($form = $this->frame->editInPlace('page', $page, $path)) !== null) {
            return $this->frame->page($page['title'], $form, ['noindex' => true]);
        }

        if ($meta['description'] === '') {
            $meta['description'] = self::descriptionFrom((string) $page['text']);
        }

        return $this->frame->page($title, $this->frame->view->render('page', ['page' => ['text' => PageFrame::authored((string) $page['text'])] + $page, 'intro' => $home, 'build' => null]), $meta);
    }

    /**
     * A page without its own description (2.1): search engines get the start of its first longer paragraph, as collection
     * items do – better than no description, and the site audit still asks for a real one.
     */
    public static function descriptionFrom(string $html): string
    {
        preg_match_all('#<p\b[^>]*>(.*?)</p>#si', $html, $m);
        foreach ($m[1] as $paragraph) {
            $text = \Talea\Core\Html::plain($paragraph);
            if (mb_strlen($text) >= 50) {
                return mb_strimwidth($text, 0, 160, '…');
            }
        }

        return '';
    }

    /**
     * The stored redirect for an old address, null when there is none. The path may have lost its .html (url_slash), while
     * imports store old addresses as they were (/2019/05/post.html), so both forms are tried. `$exactOnly` skips the other match types:
     * they are tried only for an address that would otherwise end with 404, so they never shadow a live page and cost nothing on one.
     */
    private function redirectRule(string $path, bool $exactOnly = false): ?array
    {
        if (!Extensions::isEnabled($this->app->settings(), 'redirects')) {
            return null;
        }
        $path = trim($path, '/');
        // a language version's redirects carry its prefix (en/old: /old may be another page's – with slugs per language, or from a
        // Polylang or WPML import), so in a language version the prefixed form comes first; the form without it still answers
        $sources = $this->app->languagePrefix !== '' ? [$this->app->languagePrefix . '/' . $path, $path] : [$path];
        foreach ($sources as $source) {
            $plain = (string) preg_replace('#\.html$#', '', $source);
            $rule = $this->app->db()->one('SELECT * FROM {redirects} WHERE from_path IN (?, ?, ?) AND active = TRUE AND match_type = \'exact\' ORDER BY from_path = ? DESC LIMIT 1', [$source, $plain, $plain . '.html', $source]);
            if ($rule !== null) {
                return $rule + ['target' => $rule['to_path']];
            }
        }
        if ($exactOnly) {
            return null;
        }
        // no exact rule: the other match types, in the order they were made (a few hundred at most)
        foreach ($this->app->db()->all('SELECT * FROM {redirects} WHERE active = TRUE AND match_type <> \'exact\' ORDER BY redirect_id LIMIT 500') as $rule) {
            foreach ($sources as $source) {
                if (($target = \Talea\Core\RedirectRules::target($rule, $source)) !== null) {
                    return $rule + ['target' => $target];
                }
            }
        }

        return null;
    }

    public function notFound(): Response
    {
        // before the site answers 404, it tries a redirect from an old URL (manual, after import and after a slug change)
        $target = $this->redirectRule($this->app->request->path());
        if ($target !== null) {
            return $this->followRule($target);
        }

        // overview of not-found URLs for the administrator (Redirects); bots probing other systems are not recorded
        $path = mb_substr(trim($this->app->request->path(), '/'), 0, 255);
        if (($refused = \Talea\Extension\Registry::runNotFound($this->app, $path)) !== null) {
            return $refused; // an add-on answers instead of the 404 page (the firewall: the fifth probe blocks the address)
        }
        if ($path !== '' && $this->app->request->get('part') === '' && !\Talea\Core\NotFound::isBot($path) && mb_check_encoding($path, 'UTF-8')) {
            try {
                if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {not_found}') < 2000 || $this->app->db()->value('SELECT 1 FROM {not_found} WHERE path = ?', [$path]) !== null) {
                    $request = $this->app->request;
                    $now = date('Y-m-d H:i:s');
                    $own = parse_url($this->app->settings()->get('site_url') ?: $request->origin());
                    $ownPort = (int) ($own['port'] ?? 0);
                    $ownHost = (string) ($own['host'] ?? '') . ($ownPort > 0 && $ownPort !== (($own['scheme'] ?? '') === 'https' ? 443 : 80) ? ':' . $ownPort : '');
                    $referrer = \Talea\Core\NotFound::referrer($request->referer(), $ownHost);
                    // the last known referrer stays when this visit has none; first_seen_at is written on insert only
                    $this->app->db()->upsert('not_found', ['path' => $path, 'count' => 1, 'last_seen_at' => $now, 'referrer' => $referrer, 'first_seen_at' => $now], ['path'],
                        ['count' => '{old.count} + 1', 'last_seen_at', 'referrer' => "CASE WHEN {new.referrer} <> '' THEN {new.referrer} ELSE {old.referrer} END"]);
                    if ((int) $this->app->db()->value('SELECT count FROM {not_found} WHERE path = ?', [$path]) === \Talea\Core\NotFound::SPIKE) {
                        \Talea\Core\Events::record($this->app->db(), 'notfound.spike', 'warning', t('/%s was requested %d times and there is no page or redirect.', mb_substr($path, 0, 150), \Talea\Core\NotFound::SPIKE), ['path' => $path]);
                    }
                }
            } catch (\Throwable) {
                // the overview is only an aid - a write error must not change the response
            }
        }

        return $this->notFoundPage(404);
    }

    /** The answer of a matched redirect rule (with its expanded `target`): counted, then the redirect or the 410/451 page. */
    private function followRule(array $rule): Response
    {
        $this->app->db()->run('UPDATE {redirects} SET hits = hits + 1, last_hit_at = ? WHERE redirect_id = ?', [date('Y-m-d H:i:s'), $rule['redirect_id']]);
        $status = (int) ($rule['type'] ?? 301);
        if ($status >= 400) {
            return $this->notFoundPage($status); // gone (410) or unavailable for legal reasons (451): the 404 page with that status
        }

        return Response::redirect(preg_match('#^https?://#i', $rule['target']) ? $rule['target'] : $this->app->url($rule['target']), in_array($status, [302, 307], true) ? $status : 301);
    }

    private function notFoundPage(int $status): Response
    {
        return $this->frame->page(t('Page not found'), $this->frame->view->render('not-found', ['url' => $this->app->url(...), 'pages' => $this->frame->menuPages(), 'news' => Extensions::isEnabled($this->app->settings(), 'news')]), ['noindex' => true, 'part' => 'not_found'], $status);
    }

    /**
     * The whole-site preview (Core\Preview target "web", from preview_link or Site appearance): every page, site part and
     * collection template shows its draft and the site uses the draft look. The signed link sets a cookie, so the preview
     * stays while the visitor clicks through the site, until the link expires or ?preview_end=1 ends it. An administrator
     * in the builder (?build=draft) sees the draft look too.
     */
    private function startSitePreview(): void
    {
        $r = $this->app->request;
        $cookiePath = $r->basePath() . '/';
        if ($r->get('preview_end') === '1') {
            setcookie(\Talea\Core\Preview::COOKIE, '', ['expires' => 1, 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Lax']);
            unset($_COOKIE[\Talea\Core\Preview::COOKIE]);
        }
        $key = $r->get('preview_key') !== '' ? $r->get('preview_key') : (string) ($_COOKIE[\Talea\Core\Preview::COOKIE] ?? '');
        if ($key !== '' && \Talea\Core\Preview::verify($this->app->db(), $this->app->settings(), 'web', $key)) {
            $this->sitePreview = true;
            if ($r->get('preview_key') === $key && !headers_sent()) {
                setcookie(\Talea\Core\Preview::COOKIE, $key, ['expires' => (int) strtok($key, '.'), 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $r->isHttps()]);
            }
        }
        if ($this->sitePreview || ($r->get('build') === 'draft' && $this->app->auth()->isAdmin())) {
            \Talea\Core\Look::activate($this->app->settings());
        }
        // #46: the onboarding tries a look from the gallery on (?look=<key>&palette=<n>&fonts=<n>) before it is applied – only for
        // administrators, nothing is stored
        $tried = $r->get('look') !== '' && $this->app->auth()->isAdmin() ? \Talea\Builder\Looks::get($r->get('look')) : null;
        $ds = $tried !== null ? \Talea\Builder\Looks::variant($tried['key'], $r->getInt('palette'), $r->getInt('fonts')) : null;
        if ($tried !== null && $ds !== null) {
            \Talea\Core\Look::tryOn($ds, ['header' => $tried['header'], 'footer' => $tried['footer']]);
        }
    }

    /** Draft instead of the published build: the builder and single previews (?build=draft), or the whole-site preview. */
    public function wantsDraft(): bool
    {
        return $this->sitePreview || $this->app->request->get('build') === 'draft';
    }

    /**
     * Can the visitor see the draft: whoever edits pages (for site parts the administrator), or a valid signed preview
     * link of the target (or of the whole site).
     */
    public function canSeeDraft(string $target): bool
    {
        if ($this->sitePreview) {
            return true;
        }
        $auth = $this->app->auth();
        if (str_starts_with($target, 'part:') || str_starts_with($target, 'collection:') || str_starts_with($target, 'popup:') ? $auth->isAdmin() : $auth->hasModule('pages')) {
            return true;
        }
        $key = $this->app->request->get('preview_key');
        if ($key === '' || !\Talea\Core\Preview::verify($this->app->db(), $this->app->settings(), $target, $key)) {
            return false;
        }
        \Talea\Core\MediaStaging::previewCookie($this->app, $key); // the preview shows the files its draft uses that wait for approval

        return true;
    }

    /**
     * Popup for the builder and a shared preview: with editor=1 (administrator) the popup stands on the canvas for editing,
     * otherwise the draft opens over an empty site page. Without administrator permission or a valid signed link, 404.
     */
    private function previewPopup(int $idpp): Response
    {
        $p = null;
        try {
            $p = \Talea\Builder\Popups::byId($this->app->db(), $idpp);
        } catch (\Throwable) {
            // site before migration
        }
        if ($p === null || $this->app->request->get('build') !== 'draft' || !$this->canSeeDraft('popup:' . $idpp)) {
            return $this->notFound();
        }
        if ($this->app->request->get('editor') === '1' && $this->app->auth()->isAdmin()) {
            $k = $this->frame->context();
            $k->editor = true;
            $k->source = 'popup:' . $this->app->db()->publicId('popups', $idpp);
            $html = \Talea\Builder\Renderer::html(\Talea\Builder\Build::fromJson($p['build_draft'] ?? $p['build']) ?? ['children' => []], $k);
            $k->editor = false;
            $content = '<div class="tl-popup-canvas">' . \Talea\Builder\Popups::editorWrapper($p, $html) . '</div>';
        } else {
            $this->frame->previewPopup = $idpp;
            $content = '<div class="tl-popup-canvas"></div>';
        }

        return $this->frame->page($p['name'], $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $content]), ['build' => true, 'noindex' => true]);
    }

    /**
     * The member pages (Front\MemberArea) in the page frame: nothing here is cached or indexed.
     *
     * @param array{0: string, 1: string, 2: int} $answer title, HTML, status
     */
    private function gatedPage(array $answer): Response
    {
        $k = $this->frame->context();
        $k->types['form'] = true; // the form styles
        $k->types[\Talea\Builder\Elements\EnquiryButton::TYPE] = true; // the page frame
        $k->withoutCache = true;
        $this->frame->gated = true;

        return $this->frame->page($answer[0], $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $answer[1]]), ['build' => true, 'noindex' => true], $answer[2]);
    }

    private function memberArea(string $path): Response
    {
        $answer = (new MemberArea($this->app))->handle($path);
        if ($answer instanceof Response) {
            return new Response($answer->body, $answer->status, $answer->headers + ['Cache-Control' => 'private, no-store']);
        }

        return $answer === null ? $this->notFound() : $this->gatedPage($answer);
    }

    /**
     * Restriction to member groups (Core\Members): null = the visitor may read the content; otherwise the sign-in or no-access page. Whatever is
     * restricted is answered private, no-store and noindex even to a member who may read it, and is never cached.
     *
     * @param string|null $previewTarget a draft preview link for this target (Core\Preview) lets its holder read it too
     */
    public function gate(string $type, int $id, string $title, ?string $previewTarget = null): ?Response
    {
        $access = \Talea\Core\Members::access($this->app, $type, $id);
        if ($access === 'public') {
            return null;
        }
        $this->frame->gated = true;
        $this->frame->context()->withoutCache = true;
        if ($access === 'allowed' || ($previewTarget !== null && $this->wantsDraft() && $this->canSeeDraft($previewTarget))) {
            return null;
        }

        return $this->gatedPage((new MemberArea($this->app))->gatePage($access, $title));
    }

}
