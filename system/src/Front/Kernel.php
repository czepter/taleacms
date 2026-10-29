<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Core\Extensions;
use Talea\Core\View;

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
            return $this->showNewsList();
        }
        if (preg_match('#^/news/category/([a-z0-9-]+)$#', $path, $m)) {
            return $this->category($m[1]);
        }
        if (preg_match('#^/news/tag/([a-z0-9-]+)$#', $path, $m)) {
            return $this->tag($m[1]);
        }
        if (preg_match('#^/news/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_versions')) {
            $newsItem = $this->news->bySlug($m[1]);

            return $newsItem === null || \Talea\Core\Members::isGated($this->app->db(), 'news', (int) $newsItem['news_id']) // gated news has no Markdown version
                ? $this->notFound()
                : new Response((new Seo($this->app))->newsItemMarkdown($newsItem), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if (preg_match('#^/news/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsItem($m[1]);
        }
        if (preg_match('#^/([a-z0-9][a-z0-9/-]*)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_versions')) {
            $markdown = $this->markdownVersion($m[1]);

            return $markdown === null ? $this->notFound() : new Response($markdown, 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if ($path === '/search') {
            return $this->search();
        }
        if ($path === '/rss.xml') {
            return $this->rss();
        }
        $seo = new Seo($this->app);
        if ($path === '/manifest.webmanifest') {
            return new Response(SiteIdentity::manifest($this->app->settings(), $this->app->request->basePath()), 200, ['Content-Type' => 'application/manifest+json; charset=utf-8']);
        }
        if ($path === '/favicon.ico') {
            // browsers ask on their own; instead of a full 404 page a link to the site icon, or an empty response
            $icon = is_file(TALEA_ROOT . '/media/icon-32.png') ? $this->app->url('media/icon-32.png') : null;

            return $icon !== null ? Response::redirect($icon, 301) : new Response('', 204, ['Cache-Control' => 'public, max-age=86400']);
        }
        if (preg_match('#^/og/([a-f0-9]{32})\.png$#', $path, $m)) {
            // a share image drawn by the site (2.12, ShareImage); a hash the site did not make is a 404
            return ShareImage::serve($this->app, $m[1]) ?? $this->notFound();
        }
        if ($path === '/robots.txt') {
            return new Response($seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/sitemap.xml') {
            return new Response(Cache::text($this->app, 'sitemap', $seo->sitemapXml(...)), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/feed.json') {
            $json = Cache::text($this->app, 'feed.json|' . Language::siteColumn(), fn (): string => (string) json_encode($seo->jsonFeed($this->news->listPublished(1, 20, true)[0]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return new Response($json, 200, ['Content-Type' => 'application/feed+json; charset=utf-8']);
        }
        if ($path === '/.well-known/security.txt' && $this->app->settings()->get('security_contact') !== '') {
            $siteSettings = $this->app->settings();

            return new Response(Seo::securityTxt($siteSettings->get('security_contact'), $request->origin() . $this->app->url(''),
                array_values(array_unique(array_merge([Language::defaults($siteSettings)], Language::additional($siteSettings)))), time()), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/llms.txt' && $this->app->settings()->bool('llms_txt')) {
            return new Response(Cache::text($this->app, 'llms|' . Language::siteColumn(), $seo->llmsTxt(...)), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $indexNowKey = $this->app->settings()->get('indexnow_key');
        if ($indexNowKey !== '' && $path === '/' . $indexNowKey . '.txt') {
            return new Response($indexNowKey, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/consent' && $request->isPost()) {
            // cookie consent log: without the IP address, only a random identifier from the visitor's cookie
            $category = implode(',', array_intersect(explode(',', $request->post('category')), ['analytics', 'marketing'])) ?: 'none';
            $antispam = new \Talea\Core\Antispam($this->app->db(), $this->app->settings());
            if ($this->app->settings()->bool('cookies_log') && preg_match('/^[a-f0-9]{32}$/', $request->post('id')) && $antispam->count($request->ip(), 'consent', 0, 60) < 20) {
                $antispam->write($request->ip(), 'consent', 0);
                $this->app->db()->insert('consents', ['visitor_token' => $request->post('id'), 'created_at' => date('Y-m-d H:i:s'), 'categories' => $category]);
            }

            return new Response('', 204);
        }
        if ($path === '/popup' && $request->isPost()) {
            // popup counters: views, closes, conversions – without cookies and without data about the visitor
            $column = ['view' => 'impressions', 'close' => 'closes', 'conversion' => 'conversions'][$request->post('event')] ?? null;
            $antispam = new \Talea\Core\Antispam($this->app->db(), $this->app->settings());
            $popupId = $this->app->db()->internalId('popups', $request->post('popup'));
            if ($column !== null && $popupId > 0 && $antispam->count($request->ip(), 'popup', 0, 60) < 60) {
                $antispam->write($request->ip(), 'popup', 0);
                $this->app->db()->run('UPDATE {popups} SET ' . $column . ' = ' . $column . ' + 1 WHERE popup_id = ? AND active = TRUE', [$popupId]);
            }

            return new Response('', 204);
        }
        if (in_array($path, ['/fleet/pair', '/fleet/heartbeat', '/fleet/unpair', '/fleet/kit'], true) && $request->isPost() && Extensions::isEnabled($this->app->settings(), 'fleet')) {
            // the fleet console (2.9): sites pair with it and report to it, every request signed by the site's own key;
            // 2.16: a paired site asks for the shared design kit the same way
            $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
            $signature = (string) ($request->serverValues()['HTTP_X_TALEA_SIGNATURE'] ?? '');

            return match ($path) {
                '/fleet/pair' => \Talea\Fleet\Console::pair($this->app, $body, $signature),
                '/fleet/heartbeat' => \Talea\Fleet\Console::heartbeat($this->app, $body, $signature),
                '/fleet/kit' => \Talea\Fleet\Kit::answer($this->app, $body, $signature),
                default => \Talea\Fleet\Console::unpair($this->app, $body, $signature),
            };
        }
        if ($path === '/vitals' && $request->isPost()) {
            // real-user speed (2.8): one beacon per page view from image/vitals.js, aggregated per page and day without cookies
            return \Talea\Core\WebVitals::record($this->app);
        }
        if ($path === '/conversion' && $request->isPost()) {
            // contact clicks (2.12): a beacon from image/web.js for a click on a phone number, an e-mail address or a WhatsApp
            // link, counted as a lead per page and day without cookies
            return \Talea\Core\Conversions::record($this->app);
        }
        if ($path === '/experiment' && $request->isPost()) {
            // A/B tests: a beacon from image/web.js per view and per goal, counted per day and variant without cookies
            return \Talea\Builder\Experiments::record($this->app);
        }
        if ($path === '/mcp') {
            return (new \Talea\Mcp\Server($this->app))->handle();
        }
        if (preg_match('#^/download/([A-Za-z0-9._-]{30,900})$#', $path, $m)) {
            // a gated download (2.11, Core\Documents): the file arrives by e-mail after a form is sent, as a signed link
            return \Talea\Core\Documents::gatedDownload($this->app, $m[1]) ?? $this->notFound();
        }
        // subscribe: sign-up from the element (only with Newsletter enabled); confirmation and unsubscribe by a link from the
        // e-mail always work – even after the extension is disabled, unsubscribing from already sent e-mails must work
        $subscriptionLink = $request->get('confirm') !== '' || $request->get('unsubscribe') !== '';
        if ($path === '/subscribe' && ($subscriptionLink || Extensions::isEnabled($this->app->settings(), 'newsletter_signup'))) {
            $subscription = new Subscription($this->app);
            if ($request->isPost() && !$subscriptionLink) {
                $back = $request->post('back');
                $back = preg_match('~^/[^\s\\\\?#]*$~', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('');
                $anchor = preg_match('/^[a-z0-9-]{1,60}$/', $request->post('anchor')) ? '#' . $request->post('anchor') : '';

                return Response::redirect($back . '?subscription=' . $subscription->subscribe() . $anchor, 303);
            }
            [$heading, $content] = $subscription->link();

            return $this->frame->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true]);
        }
        if (($path === '/member' || str_starts_with($path, '/member/')) && Extensions::isEnabled($this->app->settings(), 'members')) {
            return $this->memberArea($path); // member login (Core\Members): sign-in link, sign-in, sign-out
        }
        if (($routed = \Talea\Extension\Registry::runRoute($this->app, $path)) !== null) {
            // an address of an add-on (Api::route): private, no-store, noindex, never cached
            return $routed instanceof Response ? new Response($routed->body, $routed->status, array_merge($routed->headers, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'])) : $this->gatedPage($routed);
        }
        if ($path === '/form' && Extensions::isEnabled($this->app->settings(), 'enquiries')) {
            return (new Forms($this->app))->process();
        }
        // online booking (3.0, Front\Booking): the free days and times as JSON, the booking itself, the customer's cancel page and
        // the .ics file – the links in the e-mails carry a token only the customer has. The booking itself and the free times
        // only while the Bookings feature is on (3.2); the cancel page and the .ics file keep working when it is switched off,
        // so people who booked can still cancel or add the appointment to their calendar (3.2.3)
        $bookingsOn = \Talea\Core\Booking::isOn($this->app->settings());
        if ($path === '/_booking' && $request->isPost() && $bookingsOn) {
            return (new Booking($this->app))->process();
        }
        if (($path === '/_booking/days' || $path === '/_booking/slots') && $bookingsOn) {
            return (new Booking($this->app))->availability(substr($path, 10));
        }
        if (preg_match('#^/_booking/cancel/([a-f0-9]{32})$#', $path, $m)) {
            [$heading, $content, $status] = (new Booking($this->app))->cancelPage($m[1]);
            $this->frame->context()->types['button'] = true;

            return $this->frame->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true], $status);
        }
        if (preg_match('#^/_booking/choose/([a-f0-9]{32})$#', $path, $m)) {
            [$heading, $content, $status] = (new Booking($this->app))->choosePage($m[1]);
            $this->frame->context()->types['button'] = true;

            return $this->frame->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true], $status);
        }
        if (preg_match('#^/_booking/ics/([a-f0-9]{32})$#', $path, $m)) {
            return (new Booking($this->app))->ics($m[1]) ?? $this->notFound();
        }
        if ($path === '/health') {
            // for an orchestrator (docker HEALTHCHECK, a load balancer): 200 when the database answers and no migration is waiting, else 503 with one word why
            try {
                $pending = \Talea\Core\Migrator::pending($this->app->db()) !== [];
            } catch (\Throwable) {
                return new Response("database\n", 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
            }

            return new Response($pending ? "migrations pending\n" : "ok\n", $pending ? 503 : 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if ($path === '/tasks') {
            // background tasks for cron: this way low-traffic sites publish a scheduled news item and send mail on time
            $token = $this->app->settings()->get('tasks_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return new Response(t('Invalid token.') . "\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            $done = [];
            $this->app->settings()->set('tasks_last_run', (string) time()); // newsletters are sent only while cron runs
            @set_time_limit(90);
            try {
                foreach (\Talea\Core\Scheduler::run($this->app, 'cron', 50.0) as $job => $result) { // 2.8: every due job (Core\Scheduler)
                    $done[] = $job . ': ' . $result;
                }
            } catch (\Throwable $e) {
                $done[] = 'error: ' . $e->getMessage();
            }

            return new Response('OK ' . date('c') . ' ' . implode(', ', $done) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if (preg_match('#^/screen/([a-f0-9]{32})$#', $path, $m)) {
            // screen mode (2.11): the kiosk page for a TV in the reception – only with the mode on and the right secret, otherwise 404
            return Screen::opens($this->app->settings()->bool('screen_mode'), $this->app->settings()->get('screen_secret'), $m[1]) ? Screen::response($this->app) : $this->notFound();
        }
        if ($path === '/status.json') {
            $token = $this->app->settings()->get('health_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return Response::json(['error' => 'Invalid token.'], 403);
            }
            // monitoring always gets the texts in English - they must not change with the language of the shown site version
            $checks = Language::runWith('en', fn (): array => \Talea\Core\Health::checks($this->app));

            return Response::json(['status' => \Talea\Core\Health::summary($checks), 'version' => TALEA_VERSION, 'time' => date('c'), 'checks' => $checks]);
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
        if (preg_match('#^/([a-z0-9-]{1,110})(?:/([a-z0-9-]{1,160}))?\.ics$#', $path, $m) && ($calendar = $this->calendarFile($m[1], $m[2] ?? '')) !== null) {
            return $calendar;
        }
        if (preg_match('#^/_testimonial/([a-f0-9]{32})$#', $path, $m)) {
            return $this->testimonialPage($m[1]);
        }
        if ($path === '/_comment' && $request->isPost()) {
            // a comment on a draft from a shared preview link that allows comments (2.15, Core\DraftComments)
            return (new DraftComments($this->app))->post();
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/_compare$#', $path, $m)) {
            return $this->compareProducts($m[1]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $path, $m) && $m[1] !== 'news') {
            return $this->showCollectionItem($m[1], $m[2]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})/latest$#', $path, $m) && $m[1] !== 'news') {
            // the stable address of a document's current file (2.11, Core\Documents)
            return \Talea\Core\Documents::latest($this->app, $m[1], $m[2]) ?? $this->notFound();
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

    /**
     * The page a customer opens from a testimonial request (2.12, Core\Testimonials): their words, name and role, an optional
     * photo and two separate consents. An unknown, used or expired link is a 404. Never cached, never indexed.
     */
    private function testimonialPage(string $token): Response
    {
        $db = $this->app->db();
        $request = \Talea\Core\Testimonials::find($db, $token);
        if ($request === null) {
            return $this->notFound();
        }
        $r = $this->app->request;
        $site = (string) $this->app->settings()->get('site_name');
        $consents = \Talea\Core\Testimonials::consents($site);
        $error = '';
        if ($r->isPost()) {
            $antispam = new \Talea\Core\Antispam($db, $this->app->settings());
            $answer = \Talea\Core\Testimonials::clean($_POST);
            $reason = $antispam->verify($r, 'testimonial|' . $token);
            if ($reason !== null) {
                $error = $reason === 'robot' ? t('The form could not be verified. Reload the page and try again.') : $reason;
            } elseif (is_string($answer)) {
                $error = $answer;
            } else {
                \Talea\Core\Testimonials::save($this->app, $request, $answer, is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : null);
                $html = '<div class="tl-system-page"><h1>' . e(t('Thank you!')) . '</h1><p>' . e(t('We have received your words. We will publish them after a short check.')) . '</p></div>';

                return $this->frame->page(t('Thank you!'), $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
            }
        }
        $field = fn (string $name): string => e(is_scalar($_POST[$name] ?? null) ? (string) $_POST[$name] : '');
        $antispam = new \Talea\Core\Antispam($db, $this->app->settings());
        $title = t('Share your experience with %s', $site);
        $html = '<div class="tl-system-page"><h1>' . e($title) . '</h1>'
            . ($error !== '' ? '<p class="tl-form-error" role="alert">' . e($error) . '</p>' : '')
            . '<form class="tl-form" method="post" enctype="multipart/form-data">' . $antispam->fields('testimonial|' . $token)
            . '<p class="tl-field"><label for="t-text">' . e(t('Your words')) . ' <span class="tl-required" aria-hidden="true">*</span></label><textarea id="t-text" name="text" rows="6" maxlength="3000" required>' . $field('text') . '</textarea></p>'
            . '<p class="tl-field"><label for="t-name">' . e(t('Your name')) . ' <span class="tl-required" aria-hidden="true">*</span></label><input id="t-name" name="name" maxlength="120" autocomplete="name" required value="' . $field('name') . '"></p>'
            . '<p class="tl-field"><label for="t-role">' . e(t('Role and company (optional)')) . '</label><input id="t-role" name="role" maxlength="160" autocomplete="organization-title" value="' . $field('role') . '"></p>'
            . '<p class="tl-field"><label for="t-photo">' . e(t('Your photo (optional)')) . '</label><input id="t-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></p>'
            . '<p class="tl-field tl-field-consent"><label><input type="checkbox" name="consent_words" value="1" required> <span>' . e($consents['words']) . '</span></label></p>'
            . '<p class="tl-field tl-field-consent"><label><input type="checkbox" name="consent_photo" value="1"> <span>' . e($consents['photo']) . '</span></label></p>'
            . '<p class="tl-field"><button class="tl-button tl-button--primary" type="submit">' . e(t('Send')) . '</button></p></form></div>';
        $k = $this->frame->context();
        $k->types['form'] = true; // the form styles
        $k->types[\Talea\Builder\Elements\EnquiryButton::TYPE] = true; // the page frame
        $k->withoutCache = true;

        return $this->frame->page($title, $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    /**
     * Comparison of up to four products (2.11, Builder\Products): /<collection>/_compare?i=a,b,c – their pictures, names,
     * prices and every parameter side by side. Not indexed; an unknown collection or no known item is a 404.
     */
    private function compareProducts(string $collectionSlug): Response
    {
        $db = $this->app->db();
        $collection = \Talea\Builder\Collections::bySlug($db, $collectionSlug);
        $fields = $collection !== null ? \Talea\Builder\Products::fields($collection) : null;
        $slugs = array_slice(array_values(array_unique(array_filter(explode(',', $this->app->request->get('i')), fn (string $s): bool => preg_match('/^[a-z0-9-]{1,160}$/', $s) === 1))), 0, \Talea\Builder\Products::MAX_COMPARE);
        if ($collection === null || $fields === null || $slugs === []) {
            return $this->notFound();
        }
        $items = [];
        foreach ($slugs as $slug) {
            $item = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('item', 'item_id'), [$collection['collection_id'], $slug, Language::siteColumn()]);
            if ($item !== null) {
                $item['data'] = json_decode((string) $item['data'], true) ?: [];
                $items[] = $item;
            }
        }
        if ($items === []) {
            return $this->notFound();
        }
        $k = $this->frame->context();
        $k->types[\Talea\Builder\Elements\EnquiryButton::TYPE] = true; // its CSS has the comparison table
        $head = '';
        foreach ($items as $item) {
            $image = $fields['image'] !== '' ? (string) ($item['data'][$fields['image']] ?? '') : '';
            $price = $fields['price'] !== '' ? trim((string) ($item['data'][$fields['price']] ?? '') . ' ' . ($fields['price_note'] !== '' ? (string) ($item['data'][$fields['price_note']] ?? '') : '')) : '';
            $head .= '<th scope="col">' . ($image !== '' ? '<img src="' . e($k->image($image)) . '" alt="" loading="lazy">' : '')
                . ($collection['detail'] ? '<a href="' . e($this->app->url($collection['slug'] . '/' . $item['slug'])) . '">' . e($item['name']) . '</a>' : e($item['name']))
                . ($price !== '' ? '<br><small>' . e($price) . '</small>' : '') . '</th>';
        }
        $rows = '';
        foreach (\Talea\Builder\Products::comparison($fields['parameters'], $items) as [$name, $values]) {
            $rows .= '<tr><th scope="row">' . e($name) . '</th>' . implode('', array_map(fn (string $v): string => '<td>' . e($v) . '</td>', $values)) . '</tr>';
        }
        $title = t('Comparison') . ': ' . $collection['name'];
        $html = '<div class="tl-system-page"><h1>' . e($title) . '</h1><div class="tl-compare-wrap"><table class="tl-compare"><thead><tr><td></td>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p><a href="' . e($this->app->url($collection['slug'])) . '">' . e(t('Back to %s', (string) $collection['name'])) . '</a></p></div>';

        return $this->frame->page($title, $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    /**
     * iCalendar of an events collection (2.11, Core\Calendar): /<collection>.ics – upcoming events and those of the last
     * 30 days, to subscribe to; /<collection>/<item>.ics – one event to add. null = not an events collection.
     */
    private function calendarFile(string $collectionSlug, string $itemSlug): ?Response
    {
        $db = $this->app->db();
        $collection = \Talea\Builder\Collections::bySlug($db, $collectionSlug);
        $fields = $collection !== null ? \Talea\Core\Calendar::fields($collection) : null;
        if ($collection === null || $fields === null) {
            return null;
        }
        $start = $db->dialect()->jsonExtract('data', '$.' . $fields['start']);
        $rows = $itemSlug !== ''
            ? $db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('item', 'item_id'), [$collection['collection_id'], $itemSlug, Language::siteColumn()])
            : $db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('item', 'item_id') . ' AND ' . $start . ' >= ? ORDER BY ' . $start . ' LIMIT 500',
                [$collection['collection_id'], Language::siteColumn(), date('Y-m-d', strtotime('-30 days'))]);
        if ($itemSlug !== '' && $rows === []) {
            return $this->notFound();
        }
        $events = array_map(function (array $r) use ($collection): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return [$r, $collection['detail'] ? \Talea\Core\Mailing::absolute($this->app, $collection['slug'] . '/' . $r['slug']) : ''];
        }, $rows);
        $name = $this->app->settings()->get('site_name') . ' – ' . $collection['name'];
        $ics = \Talea\Core\Calendar::ics($collection, $events, $name, (string) (parse_url(\Talea\Core\Mailing::absolute($this->app, ''), PHP_URL_HOST) ?: 'talea'));

        return new Response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'public, max-age=900']
            + ($itemSlug !== '' ? ['Content-Disposition' => 'attachment; filename="' . $itemSlug . '.ics"'] : []));
    }

    /**
     * Collection item page (/<collection>/<item>) by the item template from the builder. In the editor the administrator
     * sees the template draft (?build=draft&editor=1), and when the collection has no items yet, a sample with field
     * labels (/<collection>/_sample).
     */
    private function showCollectionItem(string $collectionSlug, string $seo): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $collection = \Talea\Builder\Collections::bySlug($db, $collectionSlug);
        // item template in the shown site version's language; a language without its own template uses the default language's
        $template = $collection !== null ? \Talea\Builder\Collections::inLanguage($db, $collection, Language::siteColumn()) : null;
        // template draft: the administrator, or a signed preview of exactly this template (Core\Preview, target
        // collection:<idk>[:<language>])
        $draft = $template !== null && $this->wantsDraft() && ($this->app->auth()->isAdmin() || $this->canSeeDraft(\Talea\Builder\Collections::templateKey($template)));
        if ($collection === null || (!$collection['detail'] && !$draft)) {
            return $this->notFound();
        }
        $item = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND deleted_at IS NULL' . ($draft ? '' : ' AND visible = TRUE'), [$collection['collection_id'], $seo, Language::siteColumn()]);
        if ($item === null && !$draft && ($collection['hidden_redirect'] ?? '') !== ''
            && $db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND slug = ?', [$collection['collection_id'], $seo]) !== null) {
            // a hidden or deleted item (a person who left, 2.10): its old address leads to the chosen page instead of a 404
            $to = (string) $collection['hidden_redirect'];

            return Response::redirect(str_starts_with($to, 'https://') ? $to : $this->app->url(ltrim($to, '/')), 301);
        }
        if ($item === null && !($draft && $seo === '_sample')) {
            return $this->notFound();
        }
        if ($item !== null && ($gate = $this->gate('item', (int) $item['item_id'], (string) $item['name'])) !== null) {
            return $gate;
        }
        if ($item !== null) {
            $item['data'] = json_decode((string) $item['data'], true) ?: [];
        }
        $build = \Talea\Builder\Build::fromJson($draft ? ($template['build_draft'] ?? $template['build']) : $template['build'])
            ?? \Talea\Builder\Build::fromJson($draft ? ($collection['build_draft'] ?? $collection['build']) : $collection['build'])
            ?? \Talea\Builder\Collections::defaultTemplate($collection);
        // the collection level links to the page with the same slug (e.g. /navod above /navod/<article>) when it exists on
        // the site – with its title; in another language version to its translation (page slugs are unique across
        // languages: /de/vergleich)
        $parentPage = null;
        // with slugs per language the page of the version itself comes first
        $main = $db->one('SELECT page_id, translation_of, language, slug, title, visible FROM {pages} WHERE slug = ? AND deleted_at IS NULL ORDER BY language = ? DESC LIMIT 1', [$collection['slug'], Language::siteColumn()]);
        if ($main !== null && $main['language'] === Language::siteColumn()) {
            $parentPage = $main['visible'] ? $main : null;
        } elseif ($main !== null) {
            $original = (int) ($main['translation_of'] ?: $main['page_id']);
            $parentPage = $db->one('SELECT slug, title FROM {pages} WHERE (page_id = ? OR translation_of = ?) AND language = ? AND visible = TRUE AND deleted_at IS NULL LIMIT 1', [$original, $original, Language::siteColumn()]);
        }
        $this->frame->breadcrumbs([$parentPage !== null && $parentPage['title'] !== '' ? (string) $parentPage['title'] : $collection['name'], $parentPage !== null ? $this->app->url((string) $parentPage['slug']) : ''],
            [($item['breadcrumb_title'] ?? '') !== '' ? $item['breadcrumb_title'] : ($item['name'] ?? t('Sample item')), '']);
        $this->frame->collectionItem = $item !== null ? [(int) $collection['collection_id'], (string) $collection['slug'], (string) $item['slug']] : null;
        $k = $this->frame->context();
        if (\Talea\Core\Notices::isBoard($collection)) {
            $k->withoutCache = true; // {{notice_status}} changes with the day, not with an edit (2.11)
        }
        $k->item = $item !== null ? \Talea\Builder\Collections::values($collection, $item, $this->app->url(...), $this->app->db()) + \Talea\Core\Documents::values($this->app, $collection, $item) : \Talea\Builder\Collections::sample($collection);
        if (isset($k->item['_registration'])) {
            $k->withoutCache = true; // an event's page says whether it is full or over – that changes without an edit (2.11)
        }
        $k->editor = $draft && $r->get('editor') === '1';
        $k->source = 'collection:' . $this->app->db()->publicId('collections', (int) $collection['collection_id']);
        $this->frame->pageCollection = (string) $collection['slug'];
        $html = \Talea\Builder\Renderer::html($build, $k);
        [$k->item, $k->editor] = [null, false];

        // description and image for search engines and sharing: the item's first longer text and first image
        $description = '';
        $image = '';
        foreach ($collection['fields'] as $field) {
            $h = (string) ($item['data'][$field['key']] ?? '');
            if ($description === '' && in_array($field['type'], ['lines', 'html'], true) && $h !== '') {
                $description = \Talea\Core\Html::plain($h, 300);
            }
            if ($image === '' && $field['type'] === 'gallery' && $h !== '') {
                $h = \Talea\Builder\Collections::galleryPhotos($h)[0]['src'] ?? ''; // no image field before it: the gallery's first photo
            }
            if ($image === '' && in_array($field['type'], ['image', 'gallery'], true) && $h !== '') {
                $image = preg_match('#^https?://#', $h) ? $h : $this->app->request->origin() . $this->app->url(ltrim($h, '/'));
            }
        }

        // the item's own SEO fields (1.9) win over what is derived from its fields
        if ($item !== null && $item['description'] !== '') {
            $description = (string) $item['description'];
        }
        if ($item !== null && $item['image'] !== '') {
            $image = preg_match('#^https?://#', $item['image']) ? (string) $item['image'] : $this->app->request->origin() . $this->app->url(ltrim((string) $item['image'], '/'));
        }
        $title = $item !== null && $item['seo_title'] !== '' ? (string) $item['seo_title'] : (string) ($item['name'] ?? $collection['name']);

        return $this->frame->page($title, $this->frame->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), [
            'description' => $description, 'image' => $image, 'build' => true, 'noindex' => $draft || !empty($item['noindex']),
            'item' => $item !== null ? ['collection' => $collection, 'item' => $item] : null,
            'markdown' => $item !== null && $collection['detail'] && $item['visible'] && !$this->frame->gated ? $collection['slug'] . '/' . $item['slug'] : null,
        ] + $this->frame->seoMeta('item', $item ?? [], ['category' => (string) $collection['name']]));
    }

    /**
     * Markdown version of a published page or collection item (/<slug>.md, /<collection>/<item>.md); null for a hidden,
     * password-protected or gated one, which has none – like gated news.
     */
    private function markdownVersion(string $slug): ?string
    {
        $db = $this->app->db();
        $seo = new Seo($this->app);
        $page = $db->one('SELECT * FROM {pages} WHERE slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL', [$slug, Language::siteColumn()]);
        if ($page !== null) {
            return \Talea\Core\PageLock::isProtected($page) || \Talea\Core\Members::isGated($db, 'page', (int) $page['page_id'])
                ? null : $seo->pageMarkdown($page, (int) $page['page_id'] === $this->frame->homePageId());
        }
        if (!preg_match('#^([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $slug, $m) || $m[1] === 'news') {
            return null;
        }
        $collection = \Talea\Builder\Collections::bySlug($db, $m[1]);
        $item = $collection !== null && $collection['detail']
            ? $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL', [$collection['collection_id'], $m[2], Language::siteColumn()])
            : null;

        return $item === null || \Talea\Core\Members::isGated($db, 'item', (int) $item['item_id']) ? null : $seo->itemMarkdown($collection, $item);
    }

    private function home(): Response
    {
        $page = ($id = $this->frame->homePageId()) > 0 ? $this->app->db()->one('SELECT * FROM {pages} WHERE page_id = ? AND visible = TRUE', [$id]) : null;

        if ($page === null && !Extensions::isEnabled($this->app->settings(), 'news')) {
            // without a home page and without news: the site's first published page
            $page = $this->app->db()->one('SELECT * FROM {pages} WHERE visible = TRUE AND deleted_at IS NULL AND language = ? ORDER BY sort_order, page_id LIMIT 1', [Language::siteColumn()]);
        }

        return $page !== null ? $this->showPage($page, '', true) : (Extensions::isEnabled($this->app->settings(), 'news') ? $this->showNewsList(true) : $this->notFound());
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

    private function showNewsList(bool $home = false): Response
    {
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->listPublished($pageNumber);
        if (!$home) {
            $this->frame->breadcrumbs([t('News'), '']);
        }

        return $this->frame->page($home ? '' : t('News'), $this->frame->view->render('list', ['heading' => t('News'), 'description' => ''] + $this->listVariables($news, $total, $pageNumber, $home ? '' : 'news')), [
            'main' => $home,
            // without a site description: the list's own summary (the site name and the latest headlines)
            'description' => $this->app->settings()->get('site_description') !== '' ? $this->app->settings()->get('site_description')
                : mb_strimwidth(t('News') . ' – ' . $this->app->settings()->get('site_name') . ($news !== [] ? ': ' . implode(' · ', array_column(array_slice($news, 0, 3), 'title')) : ''), 0, 160, '…'),
            'part' => 'list',
        ] + $this->frame->seoMeta($home ? 'home' : 'other'));
    }

    private function category(string $seo): Response
    {
        $category = $this->app->db()->one('SELECT * FROM {categories} WHERE slug = ? AND language = ?', [$seo, Language::siteColumn()]);
        if ($category === null) {
            return $this->notFound();
        }
        $this->frame->counterpart = ['categories', 'category_id', $category, 'news/category/'];
        $this->frame->breadcrumbs([t('News'), $this->app->url('news')], [$category['name'], '']);
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->inCategory((int) $category['category_id'], $pageNumber);

        return $this->frame->page(
            $category['name'],
            $this->frame->view->render('list', ['heading' => $category['name'], 'description' => PageFrame::authored(\Talea\Core\Html::safe((string) $category['description']))] + $this->listVariables($news, $total, $pageNumber, 'news/category/' . $seo)),
            ['description' => \Talea\Core\Html::plain((string) $category['description'], 300), 'part' => 'list', 'noindex' => $total === 0 && $this->app->settings()->bool('seo_hide_empty_terms')]
                + $this->frame->seoMeta('category', [], ['category' => (string) $category['name']]),
        );
    }

    private function tag(string $seo): Response
    {
        $tag = $this->app->db()->one('SELECT * FROM {tags} WHERE slug = ?', [$seo]);
        if ($tag === null) {
            return $this->notFound();
        }
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->withTag((int) $tag['tag_id'], $pageNumber);
        // a tag with a description is a topic page: intro and its own description for search engines
        $colorScheme = trim((string) $tag['description']) !== '';

        return $this->frame->page($colorScheme ? $tag['name'] : t('Tag') . ' ' . $tag['name'], $this->frame->view->render('list', [
            'heading' => ($colorScheme ? '' : '#') . $tag['name'], 'description' => $colorScheme ? PageFrame::authored(\Talea\Core\Html::safe((string) $tag['description'])) : '',
        ] + $this->listVariables($news, $total, $pageNumber, 'news/tag/' . $seo)), [
            'description' => $colorScheme ? \Talea\Core\Html::plain((string) $tag['description'], 300) : '',
            'part' => 'list',
            'noindex' => $total === 0 && $this->app->settings()->bool('seo_hide_empty_terms'),
        ] + $this->frame->seoMeta('tag'));
    }

    private function newsItem(string $seo): Response
    {
        $preview = $this->app->request->get('preview') === '1' && $this->app->auth()->user() !== null;
        $newsItem = $this->news->bySlug($seo, $preview);
        if ($newsItem === null) {
            return $this->notFound();
        }
        if ($newsItem['language'] !== Language::siteColumn()) {
            // the news item belongs to a different language version than the one the request came from
            if ($newsItem['language'] !== '' && !in_array($newsItem['language'], Language::additional($this->app->settings()), true)) {
                return $this->notFound(); // its language version is disabled: a redirect would lead back to the same URL
            }
            $this->app->languagePrefix = $newsItem['language'];

            return Response::redirect($this->app->url('news/' . $newsItem['slug']) . ($preview ? '?preview=1' : ''), 301);
        }
        if (($gate = $this->gate('news', (int) $newsItem['news_id'], (string) $newsItem['title'])) !== null) {
            return $gate;
        }
        // editing directly on the site works with the raw text from the database (without the outline and embedded players)
        $raw = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {news} WHERE news_id = ?', [$newsItem['news_id']]);
        if ($raw !== null && ($form = $this->frame->editInPlace('news', $raw, 'news/' . $newsItem['slug'])) !== null) {
            return $this->frame->page($newsItem['title'], $form, ['noindex' => true]);
        }
        if (!$preview && $this->app->auth()->user() === null && ($_SERVER['HTTP_USER_AGENT'] ?? '') !== '') { // the cache warm-up sends no user agent
            $this->app->db()->run('UPDATE {news} SET visit = visit + 1 WHERE news_id = ?', [$newsItem['news_id']]);
        }

        $this->frame->breadcrumbs([t('News'), $this->app->url('news')], [$newsItem['category_name'], $this->app->url('news/category/' . $newsItem['category_slug'])], [(string) ($newsItem['breadcrumb_title'] ?? '') !== '' ? $newsItem['breadcrumb_title'] : $newsItem['title'], '']);
        $newsItem['faq_html'] = (new View([TALEA_SYSTEM . '/views/front']))->render('faq', ['faq' => Seo::faq($newsItem['faq'])]);
        $newsItem = (new NewsText($this->app))->complete($newsItem);
        $newsItem['tags'] = $this->app->db()->all('SELECT s.name, s.slug FROM {tags} s JOIN {news_tags} cs ON cs.tag_id = s.tag_id WHERE cs.news_id = ? ORDER BY s.name', [$newsItem['news_id']]);

        $content = $this->frame->view->render('news-item', [
            // what the editor wrote; the item itself stays as it is for the description and structured data
            'newsItem' => array_map(PageFrame::authored(...), array_filter(array_intersect_key($newsItem, ['intro' => 1, 'text' => 1, 'faq_html' => 1, 'image_caption_html' => 1]), is_string(...))) + $newsItem,
            'url' => $this->app->url(...),
            'souvisejici' => $this->app->settings()->bool('related_news_auto') ? $this->news->similar($newsItem) : [],
        ]);

        return $this->frame->page($newsItem['seo_title'] !== '' ? $newsItem['seo_title'] : $newsItem['title'], $content, [
            'article' => $newsItem,
            'description' => $newsItem['seo_description'] !== '' ? $newsItem['seo_description'] : \Talea\Core\Html::plain($newsItem['intro'], 300),
            'keywords' => $newsItem['keywords'],
            'image' => $newsItem['image'],
            'noindex' => (bool) $newsItem['noindex'],
            'type' => 'article',
            'part' => 'news_item',
        ] + $this->frame->seoMeta('news', $newsItem, ['category' => (string) $newsItem['category_name']]));
    }

    private function search(): Response
    {
        $q = mb_substr($this->app->request->get('q'), 0, 100);
        // search is the site's most expensive query and is not cached: at most 30 searches per minute from one address
        $antispam = new \Talea\Core\Antispam($this->app->db(), $this->app->settings());
        if (mb_strlen($q) >= 3) {
            if ($antispam->count($this->app->request->ip(), 'search', 0, 1) >= 30) {
                return new Response(t('Too many searches in a row. Please try again in a moment.'), 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60']);
            }
            $antispam->write($this->app->request->ip(), 'search', 0);
        }
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = mb_strlen($q) >= 3 && Extensions::isEnabled($this->app->settings(), 'news') ? $this->news->search($q, $pageNumber) : [[], 0];
        // pages and collection items with their own page – regardless of diacritics, with a snippet (news are found by the
        // fulltext above)
        $pages = [];
        if (mb_strlen($q) >= 3 && $pageNumber === 1) {
            $db = $this->app->db();
            $home = $this->frame->homePageId();
            $candidates = array_map(fn (array $s): array => ['title' => $s['title'], 'url' => (int) $s['page_id'] === $home ? '' : $s['slug'], 'text' => (string) $s['text']],
                $db->all('SELECT page_id, title, slug, text FROM {pages} WHERE visible = TRUE AND noindex = FALSE AND password_hash IS NULL AND deleted_at IS NULL AND ' . \Talea\Core\Members::notGated('page', 'page_id') . ' AND language = ? ORDER BY sort_order LIMIT 500', [Language::siteColumn()]));
            foreach ($db->all('SELECT p.name, p.slug, p.data, k.slug AS collection, k.fields FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.detail = TRUE AND p.visible = TRUE AND p.noindex = FALSE AND ' . \Talea\Core\Members::notGated('item', 'p.item_id') . ' AND p.language = ? ORDER BY p.sort_order LIMIT 2000', [Language::siteColumn()]) as $p) {
                $data = json_decode((string) $p['data'], true);
                // only text fields are searched – image paths and link URLs would add noise to the results and snippets
                $textFields = array_column(array_filter(json_decode((string) $p['fields'], true) ?: [], fn (array $f): bool => in_array($f['type'] ?? '', ['text', 'lines', 'html'], true)), 'key');
                $candidates[] = ['title' => $p['name'], 'url' => $p['collection'] . '/' . $p['slug'],
                    'text' => implode(' ', array_filter(array_intersect_key(is_array($data) ? $data : [], array_flip($textFields)), 'is_string'))];
            }
            $pages = array_map(fn (array $v): array => ['title' => $v['title'], 'slug' => $v['url'], 'snippet' => $v['snippet']], \Talea\Core\Search::find($q, $candidates));
        }

        return $this->frame->page(
            t('Search'),
            $this->frame->view->render('list', ['heading' => t('Search'), 'description' => '', 'searched' => $q, 'foundPages' => $pages] + $this->listVariables($news, $total, $pageNumber, 'search', ['q' => $q])),
            ['noindex' => true],
        );
    }

    private function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Language::siteColumn(), fn (): string => $this->frame->view->render('rss', [
            'web' => $this->app->settings(),
            'news' => $this->news->listPublished(1, 20)[0],
            'url' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
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
     * @param list<array<string, mixed>> $news
     * @param array<string, string> $params extra parameters of the pagination links
     * @return array<string, mixed>
     */
    private function listVariables(array $news, int $total, int $pageNumber, string $path, array $params = []): array
    {
        $this->frame->pastEnd = $pageNumber > 1 && $news === [];

        return [
            'news' => array_map(fn (array $n): array => ['intro' => PageFrame::authored((string) $n['intro'])] + $n, $news),
            'total' => $total,
            'page' => $pageNumber,
            'pagesCount' => max(1, (int) ceil($total / $this->news->perPage())),
            'pageUrl' => fn (int $s): string => $this->app->url($path) . (($query = http_build_query($params + ($s > 1 ? ['page' => $s] : []))) !== '' ? '?' . $query : ''),
            'searched' => null,
            'foundPages' => [],
            'url' => $this->app->url(...),
        ];
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
    private function wantsDraft(): bool
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
    private function gate(string $type, int $id, string $title, ?string $previewTarget = null): ?Response
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
