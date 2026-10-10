<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\View;

/**
 * Public part of the site.
 *
 *   /                           home page ("Nastavení → Základní", Settings → Basic), without it the news listing
 *   /news                    news listing
 *   /news/<seo-link>         news item (+ .md for language models)
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
    private readonly View $view;
    private readonly NewsRepository $news;

    /** Category or page the request shows - the language switcher uses it to find the counterpart in another version. */
    private ?array $counterpart = null;

    /** Shown collection item [idk, collection slug, item slug]: counterparts in other languages have the same slug. */
    private ?array $collectionItem = null;

    /** The shown page is the home page: in every language version its URL is the root (/, /en/), not its slug (that redirects). */
    private bool $isHome = false;

    /** Shared builder state for the whole page (page build, header, footer, wrapper) – one CSS without repetition. */
    private ?\Kaleta\Builder\Context $context = null;

    /** System URL in a foreign form (/news on the English site) – redirect to the valid one (Core\Routes). */
    private ?Response $redirect = null;

    /** The requested listing page is past its end - the response is 404. */
    private bool $pastEnd = false;

    /** The „Upravit zde“ (Edit here) link for the shown page or news item; page() prints it for a signed-in user allowed to. */
    private string $editHereUrl = '';

    /** Collection of the shown item page (popup rules „jen v kolekci“, only in collection). */
    private ?string $pageCollection = null;

    /** Popup whose draft the signed preview /_popup/<id> shows (it opens immediately). */
    private int $previewPopup = 0;

    /** A preview of the whole site with all drafts and the draft look (signed link, target "web"). */
    private bool $sitePreview = false;

    public function __construct(private readonly App $app)
    {
        $app->request->setOrigin($app->settings()->get('site_url'));
        $app->applyTimezone();
        \Kaleta\Extension\Registry::boot($app); // add-ons (3.0)
        // language version: /en/news/x -> language "en", path "/news/x"; URLs from $app->url() then get the prefix
        // automatically
        $language = Language::defaults($app->settings());
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $app->request->path(), $m) && in_array($m[1], Language::additional($app->settings()), true)) {
            $language = $m[1];
            $app->languagePrefix = $m[1];
            $app->request->setPath($m[2] ?? '/');
        }
        Language::setSite($app->settings(), $language);
        // system URLs in the version's language (/news ↔ /news): the internal form is used from here on, a foreign form
        // redirects
        [$internal, $canonicalUrl] = \Kaleta\Core\Routes::internalPath($app->request->path(), $app->db());
        if ($canonicalUrl !== $app->request->path() && !$app->request->isPost()) {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $this->redirect = Response::redirect($app->url(ltrim($internal, '/')) . ($query !== '' ? '?' . $query : ''), 301);
        }
        // an old address with a stored redirect (import, slug change) goes to its target in one step, not through the slash form first
        if ($this->redirect === null && $this->redirectRule($internal) === null && ($slash = $this->slashRedirect($internal)) !== null) {
            $this->redirect = Response::redirect($slash, 301);
        }
        // /page.html is the same page as /page (url_slash = html)
        $app->request->setPath(\Kaleta\Core\Routes::pageLike($internal) ? (string) preg_replace('#\.html$#', '', $internal) : $internal);
        // themeless: the front templates are the system's own, the look comes from the design system and the builder
        $this->view = new View([KALETA_SYSTEM . '/views/front']);
        $this->startSitePreview();
        $this->news = new NewsRepository($app->db(), $app->settings(), $app->request->basePath());
    }

    /** 301 target when the request uses the non-preferred slash form (setting url_slash), else null. */
    private function slashRedirect(string $internal): ?string
    {
        if ($this->app->request->isPost() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return null;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return \Kaleta\Core\Routes::slashRedirect($internal, $uri, $this->app->settings()->get('url_slash'));
    }

    public function handle(): Response
    {
        $request = $this->app->request;
        if ($this->redirect !== null) {
            return $this->redirect;
        }
        // 2.8: the firewall of the public site (off by default; never admin.php)
        if (($refused = \Kaleta\Core\Firewall::check($this->app)) !== null) {
            return $refused;
        }
        // the public demo (2.6) offers no Claude connection: anyone could connect to the shared admin
        if (\Kaleta\Core\Demo::active() && preg_match('#^/(mcp|oauth|\.well-known/oauth|\.well-known/openid)#', $request->path())) {
            return new Response('{"error":"The Claude connection is switched off in the public demo."}', 403, ['Content-Type' => 'application/json']);
        }
        // OAuth for the Claude connector (metadata, registration, tokens) – runs in maintenance mode too, just like /mcp
        if (($oauth = (new OAuth($this->app))->handle($request->path())) !== null) {
            return $oauth;
        }
        if ($this->app->settings()->bool('maintenance') && $request->path() !== '/mcp' && $this->app->auth()->user() === null) {
            return new Response('<!doctype html><html lang="' . e(Language::code()) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($this->app->settings()->get('site_name')) . '</title>'
                . '<body style="font:18px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;padding:24px;text-align:center"><div><h1 style="font-size:28px">' . e($this->app->settings()->get('site_name'))
                . '</h1><p>' . e($this->app->settings()->get('maintenance_text')) . '</p></div>', 503, ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '3600']);
        }
        // an old numeric WordPress URL /?p=123 (after import): its path is the home page, which always exists, so the redirect
        // on a 404 error would never be reached – it is therefore looked up by the parameter, even before the cache
        if ($request->getInt('p') > 0 && $request->path() === '/' && Extensions::isEnabled($this->app->settings(), 'redirects')) {
            $target = $this->app->db()->one('SELECT redirect_id, to_path FROM {redirects} WHERE from_path = ?', ['?p=' . $request->getInt('p')]);
            if ($target !== null) {
                $this->app->db()->run('UPDATE {redirects} SET hits = hits + 1 WHERE redirect_id = ?', [$target['redirect_id']]);

                return Response::redirect(preg_match('#^https?://#i', $target['to_path']) ? $target['to_path'] : $this->app->url($target['to_path']), 301);
            }
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
        if (preg_match('#^/news/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_news')) {
            $newsItem = $this->news->bySlug($m[1]);

            return $newsItem === null
                ? $this->notFound()
                : new Response((new Seo($this->app))->newsItemMarkdown($newsItem), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if (preg_match('#^/news/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsItem($m[1]);
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
            $icon = is_file(KALETA_ROOT . '/media/icon-32.png') ? $this->app->url('media/icon-32.png') : null;

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
            $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
            if ($this->app->settings()->bool('cookies_log') && preg_match('/^[a-f0-9]{32}$/', $request->post('id')) && $antispam->count($request->ip(), 'consent', 0, 60) < 20) {
                $antispam->write($request->ip(), 'consent', 0);
                $this->app->db()->insert('consents', ['visitor_token' => $request->post('id'), 'created_at' => date('Y-m-d H:i:s'), 'categories' => $category]);
            }

            return new Response('', 204);
        }
        if ($path === '/popup' && $request->isPost()) {
            // popup counters: views, closes, conversions – without cookies and without data about the visitor
            $column = ['view' => 'impressions', 'close' => 'closes', 'conversion' => 'conversions'][$request->post('event')] ?? null;
            $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
            if ($column !== null && $request->postInt('id') > 0 && $antispam->count($request->ip(), 'popup', 0, 60) < 60) {
                $antispam->write($request->ip(), 'popup', 0);
                $this->app->db()->run('UPDATE {popups} SET ' . $column . ' = ' . $column . ' + 1 WHERE popup_id = ? AND active = 1', [$request->postInt('id')]);
            }

            return new Response('', 204);
        }
        if (in_array($path, ['/fleet/pair', '/fleet/heartbeat', '/fleet/unpair', '/fleet/kit'], true) && $request->isPost() && Extensions::isEnabled($this->app->settings(), 'fleet')) {
            // the fleet console (2.9): sites pair with it and report to it, every request signed by the site's own key;
            // 2.16: a paired site asks for the shared design kit the same way
            $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
            $signature = (string) ($request->serverValues()['HTTP_X_KALETA_SIGNATURE'] ?? '');

            return match ($path) {
                '/fleet/pair' => \Kaleta\Fleet\Console::pair($this->app, $body, $signature),
                '/fleet/heartbeat' => \Kaleta\Fleet\Console::heartbeat($this->app, $body, $signature),
                '/fleet/kit' => \Kaleta\Fleet\Kit::answer($this->app, $body, $signature),
                default => \Kaleta\Fleet\Console::unpair($this->app, $body, $signature),
            };
        }
        if ($path === '/vitals' && $request->isPost()) {
            // real-user speed (2.8): one beacon per page view from image/vitals.js, aggregated per page and day without cookies
            return \Kaleta\Core\WebVitals::record($this->app);
        }
        if ($path === '/conversion' && $request->isPost()) {
            // contact clicks (2.12): a beacon from image/web.js for a click on a phone number, an e-mail address or a WhatsApp
            // link, counted as a lead per page and day without cookies
            return \Kaleta\Core\Conversions::record($this->app);
        }
        if ($path === '/mcp') {
            return (new \Kaleta\Mcp\Server($this->app))->handle();
        }
        if (preg_match('#^/download/([A-Za-z0-9._-]{30,900})$#', $path, $m)) {
            // a gated download (2.11, Core\Documents): the file arrives by e-mail after a form is sent, as a signed link
            return \Kaleta\Core\Documents::gatedDownload($this->app, $m[1]) ?? $this->notFound();
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

            return $this->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true]);
        }
        if ($path === '/form' && Extensions::isEnabled($this->app->settings(), 'enquiries')) {
            return (new Forms($this->app))->process();
        }
        // online booking (3.0, Front\Booking): the free days and times as JSON, the booking itself, the customer's cancel page and
        // the .ics file – the links in the e-mails carry a token only the customer has. The booking itself and the free times
        // only while the Bookings feature is on (3.2); the cancel page and the .ics file keep working when it is switched off,
        // so people who booked can still cancel or add the appointment to their calendar (3.2.3)
        $bookingsOn = \Kaleta\Core\Booking::isOn($this->app->settings());
        if ($path === '/_booking' && $request->isPost() && $bookingsOn) {
            return (new Booking($this->app))->process();
        }
        if (($path === '/_booking/days' || $path === '/_booking/slots') && $bookingsOn) {
            return (new Booking($this->app))->availability(substr($path, 10));
        }
        if (preg_match('#^/_booking/cancel/([a-f0-9]{32})$#', $path, $m)) {
            [$heading, $content, $status] = (new Booking($this->app))->cancelPage($m[1]);
            $this->context()->types['button'] = true;

            return $this->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true], $status);
        }
        if (preg_match('#^/_booking/choose/([a-f0-9]{32})$#', $path, $m)) {
            [$heading, $content, $status] = (new Booking($this->app))->choosePage($m[1]);
            $this->context()->types['button'] = true;

            return $this->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true], $status);
        }
        if (preg_match('#^/_booking/ics/([a-f0-9]{32})$#', $path, $m)) {
            return (new Booking($this->app))->ics($m[1]) ?? $this->notFound();
        }
        if ($path === '/health') {
            // for an orchestrator (docker HEALTHCHECK, a load balancer): 200 when the database answers and no migration is waiting, else 503 with one word why
            try {
                $pending = \Kaleta\Core\Migrator::pending($this->app->db()) !== [];
            } catch (\Throwable) {
                return new Response("database\n", 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
            }

            return new Response($pending ? "migrations pending\n" : "ok\n", $pending ? 503 : 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if ($path === '/tasks' && $request->get('probe') !== '') {
            // 2.8: right after an update the Updater asks whether the new version runs – a one-time code, valid only during the update
            $probe = $this->app->settings()->get('update_probe');

            return $probe !== '' && hash_equals($probe, $request->get('probe'))
                ? new Response('KALETA-PROBE ' . KALETA_VERSION . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'])
                : new Response(t('Invalid token.') . "\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
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
                foreach (\Kaleta\Core\Scheduler::run($this->app, 'cron', 50.0) as $job => $result) { // 2.8: every due job (Core\Scheduler)
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
        if ($path === '/stav.json') {
            $token = $this->app->settings()->get('health_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return Response::json(['error' => 'Invalid token.'], 403);
            }
            // monitoring always gets the texts in Czech - they must not change with the language of the shown site version
            $checks = Language::runWith('cs', fn (): array => \Kaleta\Core\Health::checks($this->app));

            return Response::json(['status' => \Kaleta\Core\Health::summary($checks), 'version' => KALETA_VERSION, 'time' => date('c'), 'checks' => $checks]);
        }

        // a hidden page is visible only in the builder preview (whoever can edit pages) and via a signed preview link
        // (?preview_key=…, Core\Preview)
        $showHidden = $this->sitePreview || ($request->get('build') === 'koncept' && ($this->app->auth()->hasModule('pages') || $request->get('preview_key') !== ''));
        $page = $this->app->db()->one('SELECT * FROM {pages} WHERE slug = ? AND language = ? AND deleted_at IS NULL' . ($showHidden ? '' : ' AND visible = 1'), [ltrim($path, '/'), Language::siteColumn()]);
        if ($page !== null && !$page['visible'] && !$this->canSeeDraft('page:' . (int) $page['page_id'])) {
            $page = null;
        }
        if ($page !== null) {
            if ((int) $page['page_id'] === $this->homePageId() && !$showHidden) {
                return Response::redirect($this->app->url(''), 301); // the home page has only one URL – the site root
            }

            return $this->showPage($page, ltrim($path, '/'));
        }
        if (preg_match('#^/_section/([a-z0-9-]{1,40})$#', $path, $m) && $this->app->auth()->hasModule('pages')) {
            return $this->previewSection($m[1]);
        }
        if (preg_match('#^/_popup/(\d+)$#', $path, $m)) {
            return $this->previewPopup((int) $m[1]);
        }
        if (preg_match('#^/_component/(\d+)$#', $path, $m) && $this->app->auth()->isAdmin()) {
            return $this->previewComponent((int) $m[1]);
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
            return \Kaleta\Core\Documents::latest($this->app, $m[1], $m[2]) ?? $this->notFound();
        }

        return $this->notFound();
    }

    /**
     * Preview of a ready-made library section for the builder panel: only the section in the site's appearance, without
     * header and footer. Library classes are only rendered from their default style – nothing is written to the site.
     */
    private function previewSection(string $key): Response
    {
        $section = \Kaleta\Builder\Library::section($key, Language::code());
        if ($section === null) {
            return $this->notFound();
        }
        $k = new \Kaleta\Builder\Context($this->app);
        $html = \Kaleta\Builder\Build::html(['children' => [$section['element']]], $k);
        $classes = '';
        foreach ($section['classes'] as $t) {
            $classes .= \Kaleta\Builder\Style::css('.' . $t, \Kaleta\Builder\Library::CLASSES[$t] ?? []);
        }
        $k->classes = []; // class styles above come from the library, not from the site database (the class may not exist on the site yet)
        $siteSettings = $this->app->settings();
        $css = \Kaleta\Builder\DesignSystem::css(\Kaleta\Builder\DesignSystem::load($siteSettings), $this->app->request->basePath()) . \Kaleta\Builder\Build::css($this->app->db(), $k) . '@layer classes {' . $classes . '}';
        $layout = $this->app->url('image/template.css');

        return new Response('<!doctype html><html lang="' . e(Language::code()) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<link rel="stylesheet" href="' . e($layout) . '"><link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '"><style>' . $css . 'body{margin:0}</style></head>'
            . '<body><main class="build">' . $html . '</main></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Canvas of the component editor (administrator only): the draft component with default property values. */
    private function previewComponent(int $idm): Response
    {
        $component = \Kaleta\Builder\Components::byId($this->app->db(), $idm);
        if ($component === null) {
            return $this->notFound();
        }
        $k = $this->context();
        $k->item = \Kaleta\Builder\Components::values($component, []);
        $k->editor = $this->app->request->get('editor') === '1';
        $html = \Kaleta\Builder\Build::html(\Kaleta\Builder\Build::fromJson($component['build_draft'] ?? $component['build']) ?? ['children' => []], $k);
        [$k->item, $k->editor] = [null, false];

        return $this->page($component['name'], $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    /**
     * The page a customer opens from a testimonial request (2.12, Core\Testimonials): their words, name and role, an optional
     * photo and two separate consents. An unknown, used or expired link is a 404. Never cached, never indexed.
     */
    private function testimonialPage(string $token): Response
    {
        $db = $this->app->db();
        $request = \Kaleta\Core\Testimonials::find($db, $token);
        if ($request === null) {
            return $this->notFound();
        }
        $r = $this->app->request;
        $site = (string) $this->app->settings()->get('site_name');
        $consents = \Kaleta\Core\Testimonials::consents($site);
        $error = '';
        if ($r->isPost()) {
            $antispam = new \Kaleta\Core\Antispam($db, $this->app->settings());
            $answer = \Kaleta\Core\Testimonials::clean($_POST);
            $reason = $antispam->verify($r, 'testimonial|' . $token);
            if ($reason !== null) {
                $error = $reason === 'robot' ? t('The form could not be verified. Reload the page and try again.') : $reason;
            } elseif (is_string($answer)) {
                $error = $answer;
            } else {
                \Kaleta\Core\Testimonials::save($this->app, $request, $answer, is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : null);
                $html = '<div class="ka-system-page"><h1>' . e(t('Thank you!')) . '</h1><p>' . e(t('We have received your words. We will publish them after a short check.')) . '</p></div>';

                return $this->page(t('Thank you!'), $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
            }
        }
        $field = fn (string $name): string => e(is_scalar($_POST[$name] ?? null) ? (string) $_POST[$name] : '');
        $antispam = new \Kaleta\Core\Antispam($db, $this->app->settings());
        $title = t('Share your experience with %s', $site);
        $html = '<div class="ka-system-page"><h1>' . e($title) . '</h1>'
            . ($error !== '' ? '<p class="ka-form-error" role="alert">' . e($error) . '</p>' : '')
            . '<form class="ka-form" method="post" enctype="multipart/form-data">' . $antispam->fields('testimonial|' . $token)
            . '<p class="ka-field"><label for="t-text">' . e(t('Your words')) . ' <span class="ka-required" aria-hidden="true">*</span></label><textarea id="t-text" name="text" rows="6" maxlength="3000" required>' . $field('text') . '</textarea></p>'
            . '<p class="ka-field"><label for="t-name">' . e(t('Your name')) . ' <span class="ka-required" aria-hidden="true">*</span></label><input id="t-name" name="name" maxlength="120" autocomplete="name" required value="' . $field('name') . '"></p>'
            . '<p class="ka-field"><label for="t-role">' . e(t('Role and company (optional)')) . '</label><input id="t-role" name="role" maxlength="160" autocomplete="organization-title" value="' . $field('role') . '"></p>'
            . '<p class="ka-field"><label for="t-photo">' . e(t('Your photo (optional)')) . '</label><input id="t-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></p>'
            . '<p class="ka-field ka-field-consent"><label><input type="checkbox" name="consent_words" value="1" required> <span>' . e($consents['words']) . '</span></label></p>'
            . '<p class="ka-field ka-field-consent"><label><input type="checkbox" name="consent_photo" value="1"> <span>' . e($consents['photo']) . '</span></label></p>'
            . '<p class="ka-field"><button class="ka-button ka-button--primary" type="submit">' . e(t('Send')) . '</button></p></form></div>';
        $k = $this->context();
        $k->types['form'] = true; // the form styles
        $k->types[\Kaleta\Builder\Elements\EnquiryButton::TYPE] = true; // the page frame
        $k->withoutCache = true;

        return $this->page($title, $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    /**
     * Comparison of up to four products (2.11, Builder\Products): /<collection>/_compare?i=a,b,c – their pictures, names,
     * prices and every parameter side by side. Not indexed; an unknown collection or no known item is a 404.
     */
    private function compareProducts(string $collectionSlug): Response
    {
        $db = $this->app->db();
        $collection = \Kaleta\Builder\Collections::bySlug($db, $collectionSlug);
        $fields = $collection !== null ? \Kaleta\Builder\Products::fields($collection) : null;
        $slugs = array_slice(array_values(array_unique(array_filter(explode(',', $this->app->request->get('i')), fn (string $s): bool => preg_match('/^[a-z0-9-]{1,160}$/', $s) === 1))), 0, \Kaleta\Builder\Products::MAX_COMPARE);
        if ($collection === null || $fields === null || $slugs === []) {
            return $this->notFound();
        }
        $items = [];
        foreach ($slugs as $slug) {
            $item = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = 1 AND deleted_at IS NULL', [$collection['collection_id'], $slug, Language::siteColumn()]);
            if ($item !== null) {
                $item['data'] = json_decode((string) $item['data'], true) ?: [];
                $items[] = $item;
            }
        }
        if ($items === []) {
            return $this->notFound();
        }
        $k = $this->context();
        $k->types[\Kaleta\Builder\Elements\EnquiryButton::TYPE] = true; // its CSS has the comparison table
        $head = '';
        foreach ($items as $item) {
            $image = $fields['image'] !== '' ? (string) ($item['data'][$fields['image']] ?? '') : '';
            $price = $fields['price'] !== '' ? trim((string) ($item['data'][$fields['price']] ?? '') . ' ' . ($fields['price_note'] !== '' ? (string) ($item['data'][$fields['price_note']] ?? '') : '')) : '';
            $head .= '<th scope="col">' . ($image !== '' ? '<img src="' . e($k->image($image)) . '" alt="" loading="lazy">' : '')
                . ($collection['detail'] ? '<a href="' . e($this->app->url($collection['slug'] . '/' . $item['slug'])) . '">' . e($item['name']) . '</a>' : e($item['name']))
                . ($price !== '' ? '<br><small>' . e($price) . '</small>' : '') . '</th>';
        }
        $rows = '';
        foreach (\Kaleta\Builder\Products::comparison($fields['parameters'], $items) as [$name, $values]) {
            $rows .= '<tr><th scope="row">' . e($name) . '</th>' . implode('', array_map(fn (string $v): string => '<td>' . e($v) . '</td>', $values)) . '</tr>';
        }
        $title = t('Comparison') . ': ' . $collection['name'];
        $html = '<div class="ka-system-page"><h1>' . e($title) . '</h1><div class="ka-compare-wrap"><table class="ka-compare"><thead><tr><td></td>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p><a href="' . e($this->app->url($collection['slug'])) . '">' . e(t('Back to %s', (string) $collection['name'])) . '</a></p></div>';

        return $this->page($title, $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), ['build' => true, 'noindex' => true]);
    }

    /**
     * iCalendar of an events collection (2.11, Core\Calendar): /<collection>.ics – upcoming events and those of the last
     * 30 days, to subscribe to; /<collection>/<item>.ics – one event to add. null = not an events collection.
     */
    private function calendarFile(string $collectionSlug, string $itemSlug): ?Response
    {
        $db = $this->app->db();
        $collection = \Kaleta\Builder\Collections::bySlug($db, $collectionSlug);
        $fields = $collection !== null ? \Kaleta\Core\Calendar::fields($collection) : null;
        if ($collection === null || $fields === null) {
            return null;
        }
        $start = "JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $fields['start'] . "'))";
        $rows = $itemSlug !== ''
            ? $db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = 1 AND deleted_at IS NULL', [$collection['collection_id'], $itemSlug, Language::siteColumn()])
            : $db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND language = ? AND visible = 1 AND deleted_at IS NULL AND ' . $start . ' >= ? ORDER BY ' . $start . ' LIMIT 500',
                [$collection['collection_id'], Language::siteColumn(), date('Y-m-d', strtotime('-30 days'))]);
        if ($itemSlug !== '' && $rows === []) {
            return $this->notFound();
        }
        $events = array_map(function (array $r) use ($collection): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return [$r, $collection['detail'] ? \Kaleta\Core\Mailing::absolute($this->app, $collection['slug'] . '/' . $r['slug']) : ''];
        }, $rows);
        $name = $this->app->settings()->get('site_name') . ' – ' . $collection['name'];
        $ics = \Kaleta\Core\Calendar::ics($collection, $events, $name, (string) (parse_url(\Kaleta\Core\Mailing::absolute($this->app, ''), PHP_URL_HOST) ?: 'kaleta'));

        return new Response($ics, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'public, max-age=900']
            + ($itemSlug !== '' ? ['Content-Disposition' => 'attachment; filename="' . $itemSlug . '.ics"'] : []));
    }

    /**
     * Collection item page (/<collection>/<item>) by the item template from the builder. In the editor the administrator
     * sees the template draft (?build=koncept&editor=1), and when the collection has no items yet, a sample with field
     * labels (/<collection>/_sample).
     */
    private function showCollectionItem(string $collectionSlug, string $seo): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $collection = \Kaleta\Builder\Collections::bySlug($db, $collectionSlug);
        // item template in the shown site version's language; a language without its own template uses the default language's
        $template = $collection !== null ? \Kaleta\Builder\Collections::inLanguage($db, $collection, Language::siteColumn()) : null;
        // template draft: the administrator, or a signed preview of exactly this template (Core\Preview, target
        // collection:<idk>[:<language>])
        $draft = $template !== null && $this->wantsDraft() && ($this->app->auth()->isAdmin() || $this->canSeeDraft(\Kaleta\Builder\Collections::templateKey($template)));
        if ($collection === null || (!$collection['detail'] && !$draft)) {
            return $this->notFound();
        }
        $item = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND deleted_at IS NULL' . ($draft ? '' : ' AND visible = 1'), [$collection['collection_id'], $seo, Language::siteColumn()]);
        if ($item === null && !$draft && ($collection['hidden_redirect'] ?? '') !== ''
            && $db->value('SELECT 1 FROM {collection_items} WHERE collection_id = ? AND slug = ?', [$collection['collection_id'], $seo]) !== null) {
            // a hidden or deleted item (a person who left, 2.10): its old address leads to the chosen page instead of a 404
            $to = (string) $collection['hidden_redirect'];

            return Response::redirect(str_starts_with($to, 'https://') ? $to : $this->app->url(ltrim($to, '/')), 301);
        }
        if ($item === null && !($draft && $seo === '_sample')) {
            return $this->notFound();
        }
        if ($item !== null) {
            $item['data'] = json_decode((string) $item['data'], true) ?: [];
        }
        $build = \Kaleta\Builder\Build::fromJson($draft ? ($template['build_draft'] ?? $template['build']) : $template['build'])
            ?? \Kaleta\Builder\Build::fromJson($draft ? ($collection['build_draft'] ?? $collection['build']) : $collection['build'])
            ?? \Kaleta\Builder\Collections::defaultTemplate($collection);
        // the collection level links to the page with the same slug (e.g. /navod above /navod/<article>) when it exists on
        // the site – with its title; in another language version to its translation (page slugs are unique across
        // languages: /de/vergleich)
        $parentPage = null;
        $main = $db->one('SELECT page_id, translation_of, language, slug, title, visible FROM {pages} WHERE slug = ? AND deleted_at IS NULL', [$collection['slug']]);
        if ($main !== null && $main['language'] === Language::siteColumn()) {
            $parentPage = $main['visible'] ? $main : null;
        } elseif ($main !== null) {
            $original = (int) ($main['translation_of'] ?: $main['page_id']);
            $parentPage = $db->one('SELECT slug, title FROM {pages} WHERE (page_id = ? OR translation_of = ?) AND language = ? AND visible = 1 AND deleted_at IS NULL LIMIT 1', [$original, $original, Language::siteColumn()]);
        }
        $this->breadcrumbs([$parentPage !== null && $parentPage['title'] !== '' ? (string) $parentPage['title'] : $collection['name'], $parentPage !== null ? $this->app->url((string) $parentPage['slug']) : ''],
            [$item['name'] ?? t('Sample item'), '']);
        $this->collectionItem = $item !== null ? [(int) $collection['collection_id'], (string) $collection['slug'], (string) $item['slug']] : null;
        $k = $this->context();
        if (\Kaleta\Core\Notices::isBoard($collection)) {
            $k->withoutCache = true; // {{notice_status}} changes with the day, not with an edit (2.11)
        }
        $k->item = $item !== null ? \Kaleta\Builder\Collections::values($collection, $item, $this->app->url(...), $this->app->db()) + \Kaleta\Core\Documents::values($this->app, $collection, $item) : \Kaleta\Builder\Collections::sample($collection);
        if (isset($k->item['_registration'])) {
            $k->withoutCache = true; // an event's page says whether it is full or over – that changes without an edit (2.11)
        }
        $k->editor = $draft && $r->get('editor') === '1';
        $k->source = 'collection:' . (int) $collection['collection_id'];
        $this->pageCollection = (string) $collection['slug'];
        $html = \Kaleta\Builder\Build::html($build, $k);
        [$k->item, $k->editor] = [null, false];

        // description and image for search engines and sharing: the item's first longer text and first image
        $description = '';
        $image = '';
        foreach ($collection['fields'] as $field) {
            $h = (string) ($item['data'][$field['key']] ?? '');
            if ($description === '' && in_array($field['type'], ['lines', 'html'], true) && $h !== '') {
                $description = mb_strimwidth(trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)), 0, 300, '…');
            }
            if ($image === '' && $field['type'] === 'image' && $h !== '') {
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

        return $this->page($title, $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $html]), [
            'description' => $description, 'image' => $image, 'build' => true, 'noindex' => $draft || !empty($item['noindex']),
            'item' => $item !== null ? ['collection' => $collection, 'item' => $item] : null,
        ]);
    }

    /** Home page ID in the shown site version's language (counterpart of the page from Settings); 0 = the news listing is home. */
    private function homePageId(): int
    {
        $id = $this->app->settings()->int('home_page');
        if ($id === 0 || Language::siteColumn() === '') {
            return $id;
        }

        return (int) $this->app->db()->value('SELECT page_id FROM {pages} WHERE translation_of = ? AND language = ?', [$id, Language::siteColumn()]);
    }

    private function home(): Response
    {
        $page = ($id = $this->homePageId()) > 0 ? $this->app->db()->one('SELECT * FROM {pages} WHERE page_id = ? AND visible = 1', [$id]) : null;

        if ($page === null && !Extensions::isEnabled($this->app->settings(), 'news')) {
            // without a home page and without news: the site's first published page
            $page = $this->app->db()->one('SELECT * FROM {pages} WHERE visible = 1 AND deleted_at IS NULL AND language = ? ORDER BY sort_order, page_id LIMIT 1', [Language::siteColumn()]);
        }

        return $page !== null ? $this->showPage($page, '', true) : (Extensions::isEnabled($this->app->settings(), 'news') ? $this->showNewsList(true) : $this->notFound());
    }

    /** @param array<string, mixed> $page */
    private function showPage(array $page, string $path, bool $home = false): Response
    {
        $this->counterpart = ['pages', 'page_id', $page, ''];
        $this->isHome = $home;
        if (!$home) {
            // subpage: parent pages in the breadcrumbs too (by slug sluzby/kuchyne → sluzby)
            $levels = [];
            $segments = explode('/', (string) $page['slug']);
            for ($i = 1; $i < count($segments); $i++) {
                $parent = $this->app->db()->one('SELECT title, slug FROM {pages} WHERE slug = ? AND language = ? AND visible = 1 AND deleted_at IS NULL', [implode('/', array_slice($segments, 0, $i)), $page['language']]);
                if ($parent !== null) {
                    $levels[] = [$parent['title'], $this->app->url($parent['slug'])];
                }
            }
            $this->breadcrumbs(...[...$levels, [$page['title'], '']]);
        }
        // a password-protected page (2.14, Core\PageLock): the password form until the visitor enters it; editors see it
        // without one. Protected pages are noindex, so they never enter the page cache
        $locked = \Kaleta\Core\PageLock::isProtected($page);
        if ($locked && !$this->app->auth()->hasModule('pages') && !\Kaleta\Core\PageLock::isUnlocked($this->app, $page)) {
            $error = $this->app->request->isPost() ? \Kaleta\Core\PageLock::unlock($this->app, $page, $this->app->request->post('ka_page_password')) : '';
            if ($this->app->request->isPost() && $error === '') {
                return Response::redirect($this->app->url($home ? '' : (string) $page['slug']), 303);
            }

            $k = $this->context();
            $k->types['form'] = true; // the form styles
            $k->types[\Kaleta\Builder\Elements\EnquiryButton::TYPE] = true; // the page frame
            $k->withoutCache = true;

            return $this->page((string) $page['title'], $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => \Kaleta\Core\PageLock::form($page, $error)]),
                ['build' => true, 'noindex' => true], $error !== '' ? 403 : 200);
        }
        // title and data for search engines and sharing (custom title, image, noindex – as with news)
        $title = $page['seo_title'] !== '' ? $page['seo_title'] : ($home ? '' : $page['title']);
        $meta = [
            'description' => $page['description'] !== '' ? $page['description'] : ($home ? $this->app->settings()->get('site_description') : ''),
            'main' => $home, 'image' => $page['image'], 'noindex' => (bool) $page['noindex'] || $locked,
            'head_code' => (string) ($page['head_code'] ?? ''), // code in <head> of this page only (2.3)
        ];
        // preview of the draft build for the editor: ?build=koncept (only whoever can edit pages), &editor=1 adds markers
        // for selecting elements
        $draft = $this->wantsDraft() && $this->canSeeDraft('page:' . (int) $page['page_id']);
        $build = \Kaleta\Builder\Build::fromJson($draft ? ($page['build_draft'] ?? $page['build']) : $page['build']);
        if ($build !== null) {
            $k = $this->context();
            $k->editor = $draft && $this->app->request->get('editor') === '1' && $this->app->request->get('part') === '';
            // comment mode (2.15, Core\DraftComments): a shared link whose key allows comments marks the elements and adds the widget
            $previewKey = $this->app->request->get('preview_key');
            $k->markIds = $draft && !$k->editor && $previewKey !== '' && \Kaleta\Core\Preview::allowsComments($this->app->db(), $this->app->settings(), 'page:' . (int) $page['page_id'], $previewKey);
            if ($k->markIds) {
                $meta['comments'] = (new DraftComments($this->app))->widget('page:' . (int) $page['page_id'], $previewKey, $path);
            }
            $k->source = 'page:' . (int) $page['page_id'];
            $html = \Kaleta\Builder\Build::html($build, $k);
            $k->editor = false;
            $k->markIds = false;
            if (!$draft && $this->app->auth()->hasModule('pages')) {
                $this->editHereUrl = $this->app->url('admin.php?module=pages&action=builder&id=' . (int) $page['page_id']);
            }

            if ($meta['description'] === '') {
                $meta['description'] = self::descriptionFrom($html);
            }

            return $this->page($title, $this->view->render('page', ['page' => $page, 'intro' => $home, 'build' => $html]), [
                'build' => true, 'noindex' => $draft || $meta['noindex'],
            ] + $meta);
        }
        if (($form = $this->editInPlace('page', $page, $path)) !== null) {
            return $this->page($page['title'], $form, ['noindex' => true]);
        }

        if ($meta['description'] === '') {
            $meta['description'] = self::descriptionFrom((string) $page['text']);
        }

        return $this->page($title, $this->view->render('page', ['page' => ['text' => self::authored((string) $page['text'])] + $page, 'intro' => $home, 'build' => null]), $meta);
    }

    /**
     * Add-on tokens {{ext.<slug>.<name>}} (3.0) in HTML an editor wrote – page and news text, a category description. Never over
     * a whole page: a template adds what the visitor sent (the search query), and that must not run an add-on (3.3.2, N38).
     * Builds fill them in Build::html().
     */
    private static function authored(string $html): string
    {
        return \Kaleta\Extension\Registry::fillTokens($html);
    }

    /**
     * A page without its own description (2.1): search engines get the start of its first longer paragraph, as collection
     * items do – better than no description, and the site audit still asks for a real one.
     */
    public static function descriptionFrom(string $html): string
    {
        preg_match_all('#<p\b[^>]*>(.*?)</p>#si', $html, $m);
        foreach ($m[1] as $paragraph) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5)));
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
            $this->breadcrumbs([t('News'), '']);
        }

        return $this->page($home ? '' : t('News'), $this->view->render('vypis', ['heading' => t('News'), 'description' => ''] + $this->listVariables($news, $total, $pageNumber, $home ? '' : 'news')), [
            'main' => $home,
            // without a site description: the list's own summary (the site name and the latest headlines)
            'description' => $this->app->settings()->get('site_description') !== '' ? $this->app->settings()->get('site_description')
                : mb_strimwidth(t('News') . ' – ' . $this->app->settings()->get('site_name') . ($news !== [] ? ': ' . implode(' · ', array_column(array_slice($news, 0, 3), 'title')) : ''), 0, 160, '…'),
            'part' => 'list',
        ]);
    }

    private function category(string $seo): Response
    {
        $category = $this->app->db()->one('SELECT * FROM {categories} WHERE slug = ? AND language = ?', [$seo, Language::siteColumn()]);
        if ($category === null) {
            return $this->notFound();
        }
        $this->counterpart = ['categories', 'category_id', $category, 'news/category/'];
        $this->breadcrumbs([t('News'), $this->app->url('news')], [$category['name'], '']);
        $pageNumber = max(1, $this->app->request->getInt('page', 1));
        [$news, $total] = $this->news->inCategory((int) $category['category_id'], $pageNumber);

        return $this->page(
            $category['name'],
            $this->view->render('vypis', ['heading' => $category['name'], 'description' => self::authored(\Kaleta\Core\Html::safe((string) $category['description']))] + $this->listVariables($news, $total, $pageNumber, 'news/category/' . $seo)),
            ['description' => strip_tags($category['description']), 'part' => 'list'],
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

        return $this->page($colorScheme ? $tag['name'] : t('Tag') . ' ' . $tag['name'], $this->view->render('vypis', [
            'heading' => ($colorScheme ? '' : '#') . $tag['name'], 'description' => $colorScheme ? self::authored(\Kaleta\Core\Html::safe((string) $tag['description'])) : '',
        ] + $this->listVariables($news, $total, $pageNumber, 'news/tag/' . $seo)), [
            'description' => $colorScheme ? mb_strimwidth(trim(strip_tags((string) $tag['description'])), 0, 300, '…') : '',
            'part' => 'list',
        ]);
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
        // editing directly on the site works with the raw text from the database (without the outline and embedded players)
        $raw = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {news} WHERE news_id = ?', [$newsItem['news_id']]);
        if ($raw !== null && ($form = $this->editInPlace('news', $raw, 'news/' . $newsItem['slug'])) !== null) {
            return $this->page($newsItem['title'], $form, ['noindex' => true]);
        }
        if (!$preview) {
            $this->app->db()->run('UPDATE {news} SET visit = visit + 1 WHERE news_id = ?', [$newsItem['news_id']]);
        }

        $this->breadcrumbs([t('News'), $this->app->url('news')], [$newsItem['category_name'], $this->app->url('news/category/' . $newsItem['category_slug'])], [$newsItem['title'], '']);
        $newsItem['faq_html'] = (new View([KALETA_SYSTEM . '/views/front']))->render('faq', ['faq' => Seo::faq($newsItem['faq'])]);
        $newsItem = (new NewsText($this->app))->complete($newsItem);
        $newsItem['tags'] = $this->app->db()->all('SELECT s.name, s.slug FROM {tags} s JOIN {news_tags} cs ON cs.tag_id = s.tag_id WHERE cs.news_id = ? ORDER BY s.name', [$newsItem['news_id']]);

        $content = $this->view->render('novinka', [
            // what the editor wrote; the item itself stays as it is for the description and structured data
            'newsItem' => array_map(self::authored(...), array_filter(array_intersect_key($newsItem, ['intro' => 1, 'text' => 1, 'faq_html' => 1, 'image_caption_html' => 1]), is_string(...))) + $newsItem,
            'url' => $this->app->url(...),
            'souvisejici' => $this->app->settings()->bool('related_news_auto') ? $this->news->similar($newsItem) : [],
        ]);

        return $this->page($newsItem['seo_title'] !== '' ? $newsItem['seo_title'] : $newsItem['title'], $content, [
            'article' => $newsItem,
            'description' => $newsItem['seo_description'] !== '' ? $newsItem['seo_description'] : mb_strimwidth(trim(strip_tags($newsItem['intro'])), 0, 300, '…'),
            'keywords' => $newsItem['keywords'],
            'image' => $newsItem['image'],
            'noindex' => (bool) $newsItem['noindex'],
            'type' => 'article',
            'part' => 'news_item',
        ]);
    }

    private function search(): Response
    {
        $q = mb_substr($this->app->request->get('q'), 0, 100);
        // search is the site's most expensive query and is not cached: at most 30 searches per minute from one address
        $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
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
            $home = $this->homePageId();
            $candidates = array_map(fn (array $s): array => ['title' => $s['title'], 'url' => (int) $s['page_id'] === $home ? '' : $s['slug'], 'text' => (string) $s['text']],
                $db->all('SELECT page_id, title, slug, text FROM {pages} WHERE visible = 1 AND noindex = 0 AND password_hash IS NULL AND deleted_at IS NULL AND language = ? ORDER BY sort_order LIMIT 500', [Language::siteColumn()]));
            foreach ($db->all('SELECT p.name, p.slug, p.data, k.slug AS collection, k.fields FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.detail = 1 AND p.visible = 1 AND p.noindex = 0 AND p.language = ? ORDER BY p.sort_order LIMIT 2000', [Language::siteColumn()]) as $p) {
                $data = json_decode((string) $p['data'], true);
                // only text fields are searched – image paths and link URLs would add noise to the results and snippets
                $textFields = array_column(array_filter(json_decode((string) $p['fields'], true) ?: [], fn (array $f): bool => in_array($f['type'] ?? '', ['text', 'lines', 'html'], true)), 'key');
                $candidates[] = ['title' => $p['name'], 'url' => $p['collection'] . '/' . $p['slug'],
                    'text' => implode(' ', array_filter(array_intersect_key(is_array($data) ? $data : [], array_flip($textFields)), 'is_string'))];
            }
            $pages = array_map(fn (array $v): array => ['title' => $v['title'], 'slug' => $v['url'], 'snippet' => $v['snippet']], \Kaleta\Core\Search::find($q, $candidates));
        }

        return $this->page(
            t('Search'),
            $this->view->render('vypis', ['heading' => t('Search'), 'description' => '', 'searched' => $q, 'foundPages' => $pages] + $this->listVariables($news, $total, $pageNumber, 'search', ['q' => $q])),
            ['noindex' => true],
        );
    }

    private function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Language::siteColumn(), fn (): string => $this->view->render('rss', [
            'web' => $this->app->settings(),
            'news' => $this->news->listPublished(1, 20)[0],
            'url' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    /**
     * The stored redirect for an old address, null when there is none. The path may have lost its .html (url_slash), while
     * imports store old addresses as they were (/2019/05/post.html), so both forms are tried.
     */
    private function redirectRule(string $path): ?array
    {
        if (!Extensions::isEnabled($this->app->settings(), 'redirects')) {
            return null;
        }
        $path = trim($path, '/');
        $plain = (string) preg_replace('#\.html$#', '', $path);

        return $this->app->db()->one('SELECT * FROM {redirects} WHERE from_path IN (?, ?, ?) ORDER BY from_path = ? DESC LIMIT 1', [$path, $plain, $plain . '.html', $path]);
    }

    private function notFound(): Response
    {
        // before the site answers 404, it tries a redirect from an old URL (manual, after import and after a slug change)
        $target = $this->redirectRule($this->app->request->path());
        if ($target !== null) {
            $this->app->db()->run('UPDATE {redirects} SET hits = hits + 1 WHERE redirect_id = ?', [$target['redirect_id']]);

            return Response::redirect(preg_match('#^https?://#i', $target['to_path']) ? $target['to_path'] : $this->app->url($target['to_path']), (int) ($target['type'] ?? 301) === 302 ? 302 : 301);
        }

        // overview of not-found URLs for the administrator (Redirects); bots probing other systems are not recorded
        $path = mb_substr(trim($this->app->request->path(), '/'), 0, 255);
        if (($refused = \Kaleta\Core\Firewall::notFound($this->app, $path)) !== null) {
            return $refused; // 2.8: the fifth probe for another system in an hour blocks the address
        }
        if ($path !== '' && $this->app->request->get('part') === '' && !\Kaleta\Core\NotFound::isBot($path) && mb_check_encoding($path, 'UTF-8')) {
            try {
                if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {not_found}') < 2000 || $this->app->db()->value('SELECT 1 FROM {not_found} WHERE path = ?', [$path]) !== null) {
                    $this->app->db()->run('INSERT INTO {not_found} (path, count, last_seen_at) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE count = count + 1, last_seen_at = NOW()', [$path]);
                    if ((int) $this->app->db()->value('SELECT count FROM {not_found} WHERE path = ?', [$path]) === \Kaleta\Core\NotFound::SPIKE) {
                        \Kaleta\Core\Events::record($this->app->db(), 'notfound.spike', 'warning', t('/%s was requested %d times and there is no page or redirect.', mb_substr($path, 0, 150), \Kaleta\Core\NotFound::SPIKE), ['path' => $path]);
                    }
                }
            } catch (\Throwable) {
                // the overview is only an aid - a write error must not change the response
            }
        }

        return $this->page(t('Page not found'), $this->view->render('nenalezeno', ['url' => $this->app->url(...), 'pages' => $this->menuPages(), 'news' => Extensions::isEnabled($this->app->settings(), 'news')]), ['noindex' => true, 'part' => 'not_found'], 404);
    }

    /**
     * @param list<array<string, mixed>> $news
     * @param array<string, string> $params extra parameters of the pagination links
     * @return array<string, mixed>
     */
    private function listVariables(array $news, int $total, int $pageNumber, string $path, array $params = []): array
    {
        $this->pastEnd = $pageNumber > 1 && $news === [];

        return [
            'news' => array_map(fn (array $n): array => ['intro' => self::authored((string) $n['intro'])] + $n, $news),
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
     * Language switcher for the template: code => [name, url, is active]. For a news item it leads to its translation,
     * otherwise to the counterpart of the page or category, and when there is none, to the version's home page.
     * An empty array = the site has a single language.
     *
     * @param array<string, mixed>|null $newsItem
     * @return array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}>
     */
    private function languages(?array $newsItem): array
    {
        $siteSettings = $this->app->settings();
        // an unfinished language (without a published translation of the home page) is not offered by the switcher; it stays
        // on its own pages
        $publishedLanguages = Language::published($siteSettings, $this->app->db());
        $additional = array_values(array_filter(Language::additional($siteSettings), fn (string $j): bool => in_array($j, $publishedLanguages, true) || $j === Language::siteColumn()));
        if ($additional === []) {
            return [];
        }
        $translations = [];
        if ($newsItem !== null) {
            $original = (int) ($newsItem['translation_of'] ?: $newsItem['news_id']);
            $translations = array_map(fn (string $seo): string => 'news/' . $seo, $this->app->db()->pairs('SELECT language, slug FROM {news} WHERE (news_id = ? OR translation_of = ?) AND visible = 1 AND published_at <= NOW()', [$original, $original]));
        } elseif ($this->collectionItem !== null) {
            [$idk, $collection, $seo] = $this->collectionItem;
            $translations = array_map(fn (string $s): string => $collection . '/' . $s, $this->app->db()->pairs('SELECT language, slug FROM {collection_items} WHERE collection_id = ? AND slug = ? AND visible = 1', [$idk, $seo]));
        } elseif ($this->counterpart !== null && !$this->isHome) {
            // category or page: the original + its translations
            [$table, $key, $row, $path] = $this->counterpart;
            $original = (int) ($row['translation_of'] ?: $row[$key]);
            $condition = $table === 'pages' ? ' AND visible = 1' : '';
            $translations = array_map(fn (string $seo): string => $path . $seo, $this->app->db()->pairs("SELECT language, slug FROM {{$table}} WHERE ({$key} = ? OR translation_of = ?){$condition}", [$original, $original]));
        }
        $root = $this->app->request->basePath() . '/';
        $result = [];
        foreach ([Language::defaults($siteSettings), ...$additional] as $code) {
            $column = Language::column($siteSettings, $code);
            $prefix = $column === '' ? '' : $column . '/';
            $result[$code] = [
                'name' => Language::AVAILABLE[$code][0],
                'url' => $root . $prefix . ($translations[$column] ?? ''),
                'active' => $code === Language::code(),
                'translated' => isset($translations[$column]),
            ];
        }

        return $result;
    }

    /**
     * Editing a page or news item directly on the site. Without the permission it does nothing; with it, it prepares the
     * „Upravit zde“ (Edit here) link, and with ?edit=text it returns a form with the editor instead of the content.
     * The administration saves it (action save_text).
     *
     * @param array<string, mixed> $record row of ka_stranky or ka_novinky
     */
    private function editInPlace(string $type, array $record, string $path): ?string
    {
        $auth = $this->app->auth();
        if ($auth->user() === null || !($type === 'news' ? $auth->canEditArticle($record) : $auth->hasModule('pages'))) {
            return null;
        }
        $url = $this->app->url($path);
        if ($this->app->request->get('edit') !== 'text') {
            // the draft is visible only in the preview – without it „Upravit zde“ would end on the Not found page
            $this->editHereUrl = $url . ($this->app->request->get('preview') === '1' ? '?preview=1&edit=text' : '?edit=text');

            return null;
        }

        return $this->view->render('upravit', [
            'app' => $this->app, 'type' => $type, 'record' => $record,
            'back' => $url . ($this->app->request->get('preview') === '1' ? '?preview=1' : ''),
            'action' => $this->app->url('admin.php?module=' . ($type === 'news' ? 'news' : 'pages') . '&action=save_text'),
            'error' => $this->app->request->get('error') === '1',
        ]);
    }

    /** @var list<array{titulek:string, seo_link:string, uvod:bool}>|null */
    private ?array $menuPages = null;

    /** Pages for the template's main navigation (the home page leads to the site root). */
    private function menuPages(): array
    {
        if ($this->menuPages === null) {
            $home = $this->homePageId();
            $this->menuPages = array_map(
                fn (array $s): array => ['title' => $s['title'], 'slug' => (int) $s['page_id'] === $home ? '' : $s['slug'], 'intro' => (int) $s['page_id'] === $home],
                $this->app->db()->all('SELECT page_id, title, slug FROM {pages} WHERE visible = 1 AND in_menu = 1 AND language = ? ORDER BY sort_order, title', [Language::siteColumn()]),
            );
        }

        return $this->menuPages;
    }

    /** Breadcrumb navigation of the shown page (Breadcrumbs element and BreadcrumbList): Home and the given levels. */
    private function breadcrumbs(array ...$levels): void
    {
        $this->context()->breadcrumbs = [[t('Home'), $this->app->url('')], ...$levels];
    }

    /** @var array<string, list<array<string, mixed>>> location => menu items (Core\Menu) */
    private array $menu = [];

    /** @return list<array<string, mixed>> */
    private function menu(string $location): array
    {
        return $this->menu[$location] ??= \Kaleta\Core\Menu::items($this->app, $location, Language::siteColumn(), $this->homePageId());
    }

    /**
     * Assembles the page: wraps the content in the site layout (header with navigation, footer), adds SEO and saves it to
     * the cache.
     *
     * @param array<string, mixed> $meta
     */
    private function context(): \Kaleta\Builder\Context
    {
        if ($this->context === null) {
            $this->context = new \Kaleta\Builder\Context($this->app);
            // path of the shown page already for the content (filter and pagination links of a collection list, active
            // navigation item)
            $this->context->path = (string) parse_url($this->app->url(ltrim($this->app->request->path(), '/')), PHP_URL_PATH);
        }

        return $this->context;
    }

    /**
     * Site parts from the builder: the wrapper around the content (news item, listing, 404), header and footer. A part
     * without a published build returns null and the layout renders its own. In the editor the administrator sees the
     * part's draft (?part=<type>&build=koncept&editor=1).
     *
     * @param array<string, mixed> $meta
     * @return array{0: string, 1: array{header: ?string, footer: ?string}, 2: array<string, mixed>}
     */
    /**
     * The whole-site preview (Core\Preview target "web", from preview_link or Site appearance): every page, site part and
     * collection template shows its draft and the site uses the draft look. The signed link sets a cookie, so the preview
     * stays while the visitor clicks through the site, until the link expires or ?preview_end=1 ends it. An administrator
     * in the builder (?build=koncept) sees the draft look too.
     */
    private function startSitePreview(): void
    {
        $r = $this->app->request;
        $cookiePath = $r->basePath() . '/';
        if ($r->get('preview_end') === '1') {
            setcookie('ka_preview', '', ['expires' => 1, 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Lax']);
            unset($_COOKIE['ka_preview']);
        }
        $key = $r->get('preview_key') !== '' ? $r->get('preview_key') : (string) ($_COOKIE['ka_preview'] ?? '');
        if ($key !== '' && \Kaleta\Core\Preview::verify($this->app->db(), $this->app->settings(), 'web', $key)) {
            $this->sitePreview = true;
            if ($r->get('preview_key') === $key && !headers_sent()) {
                setcookie('ka_preview', $key, ['expires' => (int) strtok($key, '.'), 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $r->isHttps()]);
            }
        }
        if ($this->sitePreview || ($r->get('build') === 'koncept' && $this->app->auth()->isAdmin())) {
            \Kaleta\Core\Look::activate($this->app->settings());
        }
    }

    /** Draft instead of the published build: the builder and single previews (?build=koncept), or the whole-site preview. */
    private function wantsDraft(): bool
    {
        return $this->sitePreview || $this->app->request->get('build') === 'koncept';
    }

    /**
     * Can the visitor see the draft: whoever edits pages (for site parts the administrator), or a valid signed preview
     * link of the target (or of the whole site).
     */
    private function canSeeDraft(string $target): bool
    {
        if ($this->sitePreview) {
            return true;
        }
        $auth = $this->app->auth();
        if (str_starts_with($target, 'part:') || str_starts_with($target, 'collection:') || str_starts_with($target, 'popup:') ? $auth->isAdmin() : $auth->hasModule('pages')) {
            return true;
        }
        $key = $this->app->request->get('preview_key');

        return $key !== '' && \Kaleta\Core\Preview::verify($this->app->db(), $this->app->settings(), $target, $key);
    }

    private function siteParts(string $content, array $meta, string $languageSwitcher, string $path): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $k = $this->context();
        $k->menu = ['main' => $this->menu('main'), 'footer' => $this->menu('footer')];
        $k->path = $path;
        $k->languages = $languageSwitcher;
        $preview = isset(\Kaleta\Builder\SiteParts::TYPES[$r->get('part')]) && $r->get('build') === 'koncept'
            && ($this->app->auth()->isAdmin() || $this->canSeeDraft('part:' . $r->get('part') . ':' . Language::siteColumn() . ($r->get('variant') !== '' ? ':' . $r->get('variant') : ''))) ? $r->get('part') : '';
        $editor = $r->get('editor') === '1' && ($preview !== '' || ($r->get('build') === 'koncept' && $r->get('part') === ''));
        $language = Language::siteColumn();
        // a site page can have its own header and footer variant; in the variant editor the ?variant= parameter decides
        $ids = ($this->counterpart[0] ?? '') === 'pages' ? (int) $this->counterpart[2]['page_id'] : null;
        $previewVariant = preg_match(\Kaleta\Builder\SiteParts::VARIANT_PATTERN, $r->get('variant')) ? $r->get('variant') : '';
        $allDrafts = $this->sitePreview;
        $render = function (string $type) use ($db, $k, $preview, $editor, $language, $ids, $previewVariant, $allDrafts): ?string {
            try {
                $variant = $preview === $type ? $previewVariant : \Kaleta\Builder\SiteParts::pageVariant($db, $type, $language, $ids);
                $build = \Kaleta\Builder\SiteParts::build($db, $type, $language, $preview === $type || $allDrafts, $variant);
            } catch (\Throwable $e) {
                error_log('Části webu: ' . $e->getMessage()); // a site without the table (before migration) renders the parts from the layout

                return null;
            }
            if ($build === null) {
                return null;
            }
            $k->editor = $editor && $preview === $type;
            $k->source = 'part:' . $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
            $html = \Kaleta\Builder\Build::html($build, $k);
            $k->editor = false;

            return $html;
        };

        $wrapper = $meta['part'] ?? null;
        unset($meta['part']);
        if ($wrapper !== null) {
            $k->content = $content;
            if (($html = $render($wrapper)) !== null) {
                $content = $html;
                $meta['build'] = true;
            }
            $k->content = '';
        }
        $parts = ['header' => $render('header'), 'footer' => $render('footer')];
        $meta['popups'] = $this->popups($k, in_array($wrapper, ['news_item', 'list'], true));
        if ($r->get('popup') !== '' || $this->previewPopup > 0) {
            $meta['noindex'] = true; // preview of a popup draft
        }

        if ($k->types !== []) {
            $meta['css'] = \Kaleta\Builder\Build::css($db, $k)
                // the builder canvas reloads after every change – a page transition would only flicker and report an abort in
                // the browser
                . ($editor ? '@view-transition{navigation:none}' : '');
            if ($k->faq !== [] && !isset($meta['faq'])) {
                $meta['faq'] = $k->faq;
            }
            if ($k->structured !== []) {
                $meta['structured'] = $k->structured;
            }
        }
        if ($preview !== '') {
            $meta['noindex'] = true;
        }

        return [$content, $parts, $meta];
    }

    /**
     * Popups for the shown page (Builder\Popups): enabled and published, by the server rules. A draft preview
     * (?popup=<id>&build=koncept or /_popup/<id>) adds the given popup even when disabled and opens it immediately.
     */
    private function popups(\Kaleta\Builder\Context $k, bool $news): string
    {
        $r = $this->app->request;
        if ($r->get('preview') === 'vzhled' || ($r->get('editor') === '1' && !$this->previewPopup)) {
            return ''; // the preview in Appearance and the builder canvas (outside the popup builder) show the page without popups
        }
        $db = $this->app->db();
        $preview = $this->previewPopup ?: ($r->get('build') === 'koncept' && preg_match('/^\d{1,9}$/', $r->get('popup')) && $this->canSeeDraft('popup:' . $r->get('popup')) ? (int) $r->get('popup') : 0);
        try {
            $popups = \Kaleta\Builder\Popups::forPage($db, ['page_id' => ($this->counterpart[0] ?? '') === 'pages' ? (int) $this->counterpart[2]['page_id'] : null,
                'collection' => $this->pageCollection, 'news' => $news, 'language' => Language::code(), 'today' => date('Y-m-d')]);
            $draft = $preview > 0 ? \Kaleta\Builder\Popups::byId($db, $preview) : null;
        } catch (\Throwable $e) {
            error_log('Pop-ups: ' . $e->getMessage()); // site before migration

            return '';
        }
        if ($draft !== null) {
            $popups = [...array_filter($popups, fn (array $p): bool => $p['popup_id'] !== $preview), ['build' => $draft['build_draft'] ?? $draft['build'], 'preview' => true] + $draft];
            $k->withoutCache = true;
        }
        $html = '';
        foreach ($popups as $p) {
            $build = \Kaleta\Builder\Build::fromJson($p['build']);
            if ($build === null) {
                continue;
            }
            if ($p['rules']['from'] !== '' || $p['rules']['to'] !== '') {
                $k->withoutCache = true; // a popup with a date range must not stay in the page cache after it ends
            }
            $k->source = 'popup:' . $p['popup_id'];
            // signed-in users (administrators, editors) view the popups, but are not counted in the counters
            $html .= \Kaleta\Builder\Popups::wrapper($p, \Kaleta\Builder\Build::html($build, $k), $this->app->auth()->user() === null ? $this->app->url('popup') : '', !empty($p['preview']));
        }

        return $html;
    }

    /**
     * Popup for the builder and a shared preview: with editor=1 (administrator) the popup stands on the canvas for editing,
     * otherwise the draft opens over an empty site page. Without administrator permission or a valid signed link, 404.
     */
    private function previewPopup(int $idpp): Response
    {
        $p = null;
        try {
            $p = \Kaleta\Builder\Popups::byId($this->app->db(), $idpp);
        } catch (\Throwable) {
            // site before migration
        }
        if ($p === null || $this->app->request->get('build') !== 'koncept' || !$this->canSeeDraft('popup:' . $idpp)) {
            return $this->notFound();
        }
        if ($this->app->request->get('editor') === '1' && $this->app->auth()->isAdmin()) {
            $k = $this->context();
            $k->editor = true;
            $k->source = 'popup:' . $idpp;
            $html = \Kaleta\Builder\Build::html(\Kaleta\Builder\Build::fromJson($p['build_draft'] ?? $p['build']) ?? ['children' => []], $k);
            $k->editor = false;
            $content = '<div class="ka-popup-canvas">' . \Kaleta\Builder\Popups::editorWrapper($p, $html) . '</div>';
        } else {
            $this->previewPopup = $idpp;
            $content = '<div class="ka-popup-canvas"></div>';
        }

        return $this->page($p['name'], $this->view->render('page', ['page' => ['title' => ''], 'intro' => false, 'build' => $content]), ['build' => true, 'noindex' => true]);
    }

    private function page(string $title, string $content, array $meta = [], int $status = 200): Response
    {
        if ($this->pastEnd) {
            $this->pastEnd = false;

            return $this->notFound();
        }
        $siteSettings = $this->app->settings();
        // business facts (2.10) in the content outside the builder (news, text pages), the title and the description
        $content = \Kaleta\Core\Privacy::fillCookieTable($content, $this->app); // {{cookie_table}} on the cookie policy page (2.14)
        $content = \Kaleta\Core\Facts::fill($content, $this->app);
        // add-on tokens (3.0) are not filled here: the content already holds what a visitor sent (the search query) – they are
        // filled in what editors wrote, in Build::html() and authored() (3.3.2, N38)
        $title = \Kaleta\Core\Facts::fillText($title, $this->app);
        if (is_string($meta['description'] ?? null)) {
            $meta['description'] = \Kaleta\Core\Facts::fillText($meta['description'], $this->app);
        }
        $seo = new Seo($this->app);
        $newsItem = $meta['article'] ?? null;
        unset($meta['article']);
        if ($status === 200 && empty($meta['noindex'])) {
            Stats::record($this->app, $newsItem === null ? null : (int) $newsItem['news_id']);
            // real-user speed (2.8) is measured on the same page views the statistics count – never in previews or the
            // builder (noindex), and not for signed-in users, whose pages carry the editing bar
            $meta['vitals'] = Stats::isOn($this->app) && $this->app->request->get('preview') === '' && $this->app->auth()->user() === null;
        }

        if (($meta['image'] ?? '') !== '' && !preg_match('#^https?://#', $meta['image'])) {
            // social networks accept only a full image URL
            $meta['image'] = $this->app->request->origin() . $this->app->url(ltrim((string) preg_replace('#^' . preg_quote($this->app->request->basePath(), '#') . '/#', '', $meta['image']), '/'));
        }
        $meta['breadcrumbs'] ??= $this->context()->breadcrumbs;
        $languages = $this->languages($newsItem);
        $languageSwitcher = $languages === [] ? '' : $this->view->render('jazyky', ['languages' => $languages]);
        // light / dark color scheme switcher for visitors – next to the languages (template, Navigation element)
        $colorScheme = in_array($this->app->settings()->get('dark_mode'), ['auto', 'dark'], true) && $this->app->settings()->bool('theme_switcher')
            ? $this->view->render('tema', ['selected' => $this->app->settings()->get('dark_mode') === 'dark' ? 'dark' : 'auto']) : '';
        $languagesHtml = $languageSwitcher . $colorScheme;
        // builder elements: Navigation adds the language switcher (optionally) and the color scheme switcher, Language
        // switcher builds from this list
        $this->context()->languageList = $languages;
        $this->context()->colorScheme = $colorScheme;
        // canonical URL: the path without parameters, with the page number for pagination (page 2 is not a copy of page 1)
        $listPageNumber = $this->app->request->getInt('page', 1);
        $canonicalUrl = $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')) . ($listPageNumber > 1 ? '?page=' . $listPageNumber : '');
        if ($this->sitePreview) {
            $meta['noindex'] = true; // the preview of drafts is never indexed nor cached
        }
        [$content, $parts, $meta] = $this->siteParts($content, $meta, $languageSwitcher, (string) parse_url($canonicalUrl, PHP_URL_PATH));
        $popups = (string) ($meta['popups'] ?? '');
        $commentWidget = (string) ($meta['comments'] ?? ''); // the comment widget of a shared preview (2.15)
        unset($meta['popups'], $meta['comments']);
        $html = $this->view->render('base', [
            'web' => $siteSettings,
            'title' => $title,
            'meta' => $meta + ['main' => false, 'description' => '', 'keywords' => $siteSettings->get('keywords'), 'image' => '', 'type' => 'website', 'noindex' => false],
            'content' => $content,
            // add-ons (3.0) may add to <head> and the end of <body> – never on a private page
            'head' => $seo->head($title, $meta + ['languages' => $languages], $newsItem) . (empty($meta['private']) ? \Kaleta\Extension\Registry::applyFilter('head', '') : ''),
            // a private page (meta private) carries no marketing code, cookie bar or pop-up; the
            // accessibility toolbar for visitors (2.14) is off by default and tracks nothing
            'foot' => (empty($meta['private']) ? $seo->foot() . $popups : '') . ($siteSettings->bool('accessibility_toolbar') ? $this->view->render('pristupnost') : '')
                . ($this->editHereUrl !== '' ? '<a class="ka-edit-here" href="' . e($this->editHereUrl) . '">' . e(t('Edit here')) . '</a>' : '')
                . $commentWidget . (empty($meta['private']) ? \Kaleta\Extension\Registry::applyFilter('footer', '') : ''),
            'pages' => $this->menuPages(),
            'menu' => $this->menu('main'),
            'menu_footer' => $this->menu('footer'),
            'menu_html' => \Kaleta\Core\Menu::html(...),
            'language' => Language::code(),
            'languages_html' => $languagesHtml,
            'with_news' => Extensions::isEnabled($siteSettings, 'news'), // News extension enabled (RSS links in the template)
            'parts' => $parts,
            'url' => $this->app->url(...),
            'canonical' => $canonicalUrl,
            'notice' => \Kaleta\Core\Hours::noticeBar($this->app), // exceptions to the opening hours, a few days ahead (2.10)
        ]);
        $html = ImageHtml::complete($this->app->db(), $html); // image dimensions and background color – less page jumping
        if ($this->sitePreview) {
            $html = (string) preg_replace('/<body[^>]*>/', '$0' . $this->previewBar(), $html, 1);
        }
        $html = $this->localizeSystemLinks($html);
        // image/web.js only on pages that need it (gallery and photos in text, video, sharing, tabs, carousel, before and after, modal, form,
        // counter, countdown, submenu – Esc closes it, popups, language versions – browser language on the first visit,
        // collection lists with filters or pages – swapped without a reload, the store locator – search, nearest, map, the
        // enquiry basket and comparing products). Contact clicks (2.12, Core\Conversions): a phone number, an e-mail address
        // or a WhatsApp link anywhere on the page – a footer with the phone number is enough – keeps the script too, but only
        // when a click would be counted (the statistics are on, a visitor, no preview: the same switch as the speed beacon)
        $countsClicks = !empty($meta['vitals']) && preg_match(\Kaleta\Core\Conversions::LINK_PATTERN, $html);
        if (!$countsClicks && !preg_match('/data-(insert|share|copy|tabs|carousel|before-after|form|booking|sent|counter|countdown|theme-option|collection|locator|product|basket|recaptcha)|popover role="dialog"|gallery|class="(?:text|lead)[" ][\s\S]*?<img|cookies-|<li class="submenu|data-popup=|rel="alternate" hreflang=/', $html)) {
            $html = (string) preg_replace('#<script src="[^"]*/image/web\.js[^"]*"[^>]*></script>\n?#', '', $html);
        }
        if (empty($meta['private'])) {
            $html = \Kaleta\Extension\Registry::applyFilter('page.html', $html); // add-ons (3.0), before the page is cached
        }
        // elements with a display condition (date, sign-in) are assembled anew every time – the cache would show them as they
        // were at the moment of saving
        if ($status === 200 && empty($meta['noindex']) && $this->app->request->get('preview') === '' && !($this->context?->withoutCache ?? false)) {
            Cache::save($this->app, $html, $newsItem === null ? null : (int) $newsItem['news_id']);
        }

        return Response::html($html, $status);
    }

    /** The bar of the whole-site preview: visitors see the published site; a link ends the preview. */
    private function previewBar(): string
    {
        return '<div class="ka-preview-bar" role="status" style="position:sticky;top:0;z-index:2147483000;display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;align-items:center;'
            . 'padding:0.5rem 1rem;background:#16181d;color:#fff;font:600 0.875rem/1.4 system-ui,sans-serif">'
            . '<span>' . e(t('Preview of drafts – visitors still see the published site.')) . '</span>'
            . '<a href="?preview_end=1" style="color:#fff;text-decoration:underline">' . e(t('End the preview')) . '</a></div>';
    }

    /**
     * Links to system URLs stored in the content (href="/news" from an older build or a starter site) in the form of the
     * version's language, so they do not go through a redirect (Core\Routes).
     */
    private function localizeSystemLinks(string $html): string
    {
        if (\Kaleta\Core\Routes::newsSlug($this->app->db()) === '' || !preg_match('#href="[^"]*/news#', $html)) {
            return $html;
        }
        $base = preg_quote($this->app->request->basePath() . ($this->app->languagePrefix !== '' ? '/' . $this->app->languagePrefix : ''), '#');

        return (string) preg_replace_callback('#href="' . $base . '/(news(?:[/?\#][^"]*)?)"#',
            fn (array $m): string => 'href="' . e($this->app->url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5))) . '"', $html);
    }
}
