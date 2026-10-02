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
 *   /novinky                    news listing
 *   /novinky/<seo-link>         news item (+ .md for language models)
 *   /novinky/kategorie/<seo>    news in a category
 *   /novinky/stitek/<seo>       news with a tag
 *   /hledani?q=...              search
 *   /rss.xml                    RSS feed of news
 *   /<slug>                     page
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

    /** System URL in a foreign form (/novinky on the English site) – redirect to the valid one (Core\Routes). */
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
        // after a system update (automatic too) the database is updated right on the first visit, not only after the
        // administrator signs in
        if (\Kaleta\Core\Migration::pending($app->settings())) {
            // a failed migration must not bring down the whole site: it is logged and the site keeps running (database
            // changes are additive only); the administrator sees it in the administration and can install a fix
            \Kaleta\Core\Migration::safe($app->db(), $app->settings());
        }
        // language version: /en/novinky/x -> language "en", path "/novinky/x"; URLs from $app->url() then get the prefix
        // automatically
        $language = Language::defaults($app->settings());
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $app->request->path(), $m) && in_array($m[1], Language::additional($app->settings()), true)) {
            $language = $m[1];
            $app->languagePrefix = $m[1];
            $app->request->setPath($m[2] ?? '/');
        }
        Language::setSite($app->settings(), $language);
        // system URLs in the version's language (/news ↔ /novinky): the internal form is used from here on, a foreign form
        // redirects
        [$internal, $canonicalUrl] = \Kaleta\Core\Routes::internalPath($app->request->path(), $language, $app->db());
        if ($canonicalUrl !== $app->request->path() && !$app->request->isPost()) {
            $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
            $this->redirect = Response::redirect($app->url(ltrim($internal, '/')) . ($query !== '' ? '?' . $query : ''), 301);
        }
        $app->request->setPath($internal);
        // themeless: the front templates are the system's own, the look comes from the design system and the builder
        $this->view = new View([KALETA_SYSTEM . '/views/front']);
        $this->startSitePreview();
        $this->news = new NewsRepository($app->db(), $app->settings(), $app->request->basePath());
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
        if ($request->getInt('p') > 0 && $request->path() === '/' && Extensions::isEnabled($this->app->settings(), 'presmerovani')) {
            $target = $this->app->db()->one('SELECT idp, na_adresu FROM {presmerovani} WHERE z_adresy = ?', ['?p=' . $request->getInt('p')]);
            if ($target !== null) {
                $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$target['idp']]);

                return Response::redirect(preg_match('#^https?://#i', $target['na_adresu']) ? $target['na_adresu'] : $this->app->url($target['na_adresu']), 301);
            }
        }
        if (($cached = Cache::load($this->app)) !== null) {
            return $cached;
        }
        $path = $request->path();
        if ($path === '/' || $path === '/index.php') {
            return $this->home();
        }
        $withNews = Extensions::isEnabled($this->app->settings(), 'novinky');
        if (!$withNews && ($path === '/novinky' || str_starts_with($path, '/novinky/') || $path === '/rss.xml' || $path === '/feed.json')) {
            return $this->notFound(); // the News extension is disabled: the data stay, but are not on the site
        }
        if ($path === '/novinky') {
            return $this->showNewsList();
        }
        if (preg_match('#^/novinky/kategorie/([a-z0-9-]+)$#', $path, $m)) {
            return $this->category($m[1]);
        }
        if (preg_match('#^/novinky/stitek/([a-z0-9-]+)$#', $path, $m)) {
            return $this->tag($m[1]);
        }
        if (preg_match('#^/novinky/([a-z0-9-]+)\.md$#', $path, $m) && $this->app->settings()->bool('markdown_news')) {
            $newsItem = $this->news->bySlug($m[1]);

            return $newsItem === null
                ? $this->notFound()
                : new Response((new Seo($this->app))->newsItemMarkdown($newsItem), 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'X-Robots-Tag' => 'noindex']);
        }
        if (preg_match('#^/novinky/([a-z0-9-]+)$#', $path, $m)) {
            return $this->newsItem($m[1]);
        }
        if ($path === '/hledani') {
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
            $icon = is_file(KALETA_ROOT . '/media/ikona-32.png') ? $this->app->url('media/ikona-32.png') : null;

            return $icon !== null ? Response::redirect($icon, 301) : new Response('', 204, ['Cache-Control' => 'public, max-age=86400']);
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
        if ($path === '/souhlas' && $request->isPost()) {
            // cookie consent log: without the IP address, only a random identifier from the visitor's cookie
            $category = implode(',', array_intersect(explode(',', $request->post('kategorie')), ['analytika', 'marketing'])) ?: 'nic';
            $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
            if ($this->app->settings()->bool('cookies_log') && preg_match('/^[a-f0-9]{32}$/', $request->post('id')) && $antispam->count($request->ip(), 'souhlas', 0, 60) < 20) {
                $antispam->write($request->ip(), 'souhlas', 0);
                $this->app->db()->insert('souhlasy', ['id_souhlasu' => $request->post('id'), 'cas' => date('Y-m-d H:i:s'), 'kategorie' => $category]);
            }

            return new Response('', 204);
        }
        if ($path === '/popup' && $request->isPost()) {
            // popup counters: views, closes, conversions – without cookies and without data about the visitor
            $column = ['zobrazeni' => 'zobrazeni', 'zavreni' => 'zavreni', 'konverze' => 'konverze'][$request->post('udalost')] ?? null;
            $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
            if ($column !== null && $request->postInt('id') > 0 && $antispam->count($request->ip(), 'popup', 0, 60) < 60) {
                $antispam->write($request->ip(), 'popup', 0);
                $this->app->db()->run('UPDATE {popupy} SET ' . $column . ' = ' . $column . ' + 1 WHERE idpp = ? AND aktivni = 1', [$request->postInt('id')]);
            }

            return new Response('', 204);
        }
        if (in_array($path, ['/fleet/pair', '/fleet/heartbeat', '/fleet/unpair'], true) && $request->isPost() && Extensions::isEnabled($this->app->settings(), 'fleet')) {
            // the fleet console (2.9): sites pair with it and report to it, every request signed by the site's own key
            $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
            $signature = (string) ($request->serverValues()['HTTP_X_KALETA_SIGNATURE'] ?? '');

            return match ($path) {
                '/fleet/pair' => \Kaleta\Fleet\Console::pair($this->app, $body, $signature),
                '/fleet/heartbeat' => \Kaleta\Fleet\Console::heartbeat($this->app, $body, $signature),
                default => \Kaleta\Fleet\Console::unpair($this->app, $body, $signature),
            };
        }
        if ($path === '/vitals' && $request->isPost()) {
            // real-user speed (2.8): one beacon per page view from image/vitals.js, aggregated per page and day without cookies
            return \Kaleta\Core\WebVitals::record($this->app);
        }
        if ($path === '/mcp') {
            return (new \Kaleta\Mcp\Server($this->app))->handle();
        }
        // odber: sign-up from the element (only with Newsletter enabled); confirmation and unsubscribe by a link from the
        // e-mail always work – even after the extension is disabled, unsubscribing from already sent e-mails must work
        $subscriptionLink = $request->get('potvrdit') !== '' || $request->get('odhlasit') !== '';
        if ($path === '/odber' && ($subscriptionLink || Extensions::isEnabled($this->app->settings(), 'newsletter'))) {
            $subscription = new Subscription($this->app);
            if ($request->isPost() && !$subscriptionLink) {
                $back = $request->post('zpet');
                $back = preg_match('~^/[^\s\\\\?#]*$~', $back) && !str_starts_with($back, '//') ? $back : $this->app->url('');
                $anchor = preg_match('/^[a-z0-9-]{1,60}$/', $request->post('kotva')) ? '#' . $request->post('kotva') : '';

                return Response::redirect($back . '?odber=' . $subscription->subscribe() . $anchor, 303);
            }
            [$heading, $content] = $subscription->link();

            return $this->page($heading, '<header class="vypis-hlavicka"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Zpět na úvod')) . '</a></p>', ['noindex' => true]);
        }
        if ($path === '/formular' && Extensions::isEnabled($this->app->settings(), 'poptavky')) {
            return (new Forms($this->app))->process();
        }
        if ($path === '/ulohy' && $request->get('probe') !== '') {
            // 2.8: right after an update the Updater asks whether the new version runs – a one-time code, valid only during the update
            $probe = $this->app->settings()->get('update_probe');

            return $probe !== '' && hash_equals($probe, $request->get('probe'))
                ? new Response('KALETA-PROBE ' . KALETA_VERSION . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store'])
                : new Response(t('Invalid token.') . "\n", 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/ulohy') {
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
        if ($path === '/stav.json') {
            $token = $this->app->settings()->get('health_token');
            if ($token === '' || !hash_equals($token, $request->get('token'))) {
                return Response::json(['chyba' => 'Invalid token.'], 403);
            }
            // monitoring always gets the texts in Czech - they must not change with the language of the shown site version
            $checks = Language::runWith('cs', fn (): array => \Kaleta\Core\Health::checks($this->app));

            return Response::json(['stav' => \Kaleta\Core\Health::summary($checks), 'verze' => KALETA_VERSION, 'cas' => date('c'), 'kontroly' => $checks]);
        }

        // a hidden page is visible only in the builder preview (whoever can edit pages) and via a signed preview link
        // (?nahled_klic=…, Core\Preview)
        $showHidden = $this->sitePreview || ($request->get('stavba') === 'koncept' && ($this->app->auth()->hasModule('pages') || $request->get('nahled_klic') !== ''));
        $page = $this->app->db()->one('SELECT * FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND smazano IS NULL' . ($showHidden ? '' : ' AND zobrazit = 1'), [ltrim($path, '/'), Language::siteColumn()]);
        if ($page !== null && !$page['zobrazit'] && !$this->canSeeDraft('stranka:' . (int) $page['ids'])) {
            $page = null;
        }
        if ($page !== null) {
            if ((int) $page['ids'] === $this->homePageId() && !$showHidden) {
                return Response::redirect($this->app->url(''), 301); // the home page has only one URL – the site root
            }

            return $this->showPage($page, ltrim($path, '/'));
        }
        if (preg_match('#^/_sekce/([a-z0-9-]{1,40})$#', $path, $m) && $this->app->auth()->hasModule('pages')) {
            return $this->previewSection($m[1]);
        }
        if (preg_match('#^/_popup/(\d+)$#', $path, $m)) {
            return $this->previewPopup((int) $m[1]);
        }
        if (preg_match('#^/_komponenta/(\d+)$#', $path, $m) && $this->app->auth()->isAdmin()) {
            return $this->previewComponent((int) $m[1]);
        }
        if (preg_match('#^/([a-z0-9-]{1,110})/([a-z0-9-]{1,160})$#', $path, $m) && $m[1] !== 'novinky') {
            return $this->showCollectionItem($m[1], $m[2]);
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
        $html = \Kaleta\Builder\Build::html(['deti' => [$section['prvek']]], $k);
        $classes = '';
        foreach ($section['tridy'] as $t) {
            $classes .= \Kaleta\Builder\Style::css('.' . $t, \Kaleta\Builder\Library::CLASSES[$t] ?? []);
        }
        $k->classes = []; // class styles above come from the library, not from the site database (the class may not exist on the site yet)
        $siteSettings = $this->app->settings();
        $css = \Kaleta\Builder\DesignSystem::css(\Kaleta\Builder\DesignSystem::load($siteSettings), $this->app->request->basePath()) . \Kaleta\Builder\Build::css($this->app->db(), $k) . '@layer tridy {' . $classes . '}';
        $layout = $this->app->url('image/sablona.css');

        return new Response('<!doctype html><html lang="' . e(Language::code()) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<link rel="stylesheet" href="' . e($layout) . '"><link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '"><style>' . $css . 'body{margin:0}</style></head>'
            . '<body><main class="stavba">' . $html . '</main></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
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
        $html = \Kaleta\Builder\Build::html(\Kaleta\Builder\Build::fromJson($component['stavba_koncept'] ?? $component['stavba']) ?? ['deti' => []], $k);
        [$k->item, $k->editor] = [null, false];

        return $this->page($component['nazev'], $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $html]), ['stavba' => true, 'noindex' => true]);
    }

    /**
     * Collection item page (/<collection>/<item>) by the item template from the builder. In the editor the administrator
     * sees the template draft (?stavba=koncept&editor=1), and when the collection has no items yet, a sample with field
     * labels (/<collection>/_ukazka).
     */
    private function showCollectionItem(string $collectionSlug, string $seo): Response
    {
        $db = $this->app->db();
        $r = $this->app->request;
        $collection = \Kaleta\Builder\Collections::bySlug($db, $collectionSlug);
        // item template in the shown site version's language; a language without its own template uses the default language's
        $template = $collection !== null ? \Kaleta\Builder\Collections::inLanguage($db, $collection, Language::siteColumn()) : null;
        // template draft: the administrator, or a signed preview of exactly this template (Core\Preview, target
        // kolekce:<idk>[:<language>])
        $draft = $template !== null && $this->wantsDraft() && ($this->app->auth()->isAdmin() || $this->canSeeDraft(\Kaleta\Builder\Collections::templateKey($template)));
        if ($collection === null || (!$collection['detail'] && !$draft)) {
            return $this->notFound();
        }
        $item = $db->one('SELECT * FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND jazyk = ? AND smazano IS NULL' . ($draft ? '' : ' AND zobrazit = 1'), [$collection['idk'], $seo, Language::siteColumn()]);
        if ($item === null && !($draft && $seo === '_ukazka')) {
            return $this->notFound();
        }
        if ($item !== null) {
            $item['data'] = json_decode((string) $item['data'], true) ?: [];
        }
        $build = \Kaleta\Builder\Build::fromJson($draft ? ($template['stavba_koncept'] ?? $template['stavba']) : $template['stavba'])
            ?? \Kaleta\Builder\Build::fromJson($draft ? ($collection['stavba_koncept'] ?? $collection['stavba']) : $collection['stavba'])
            ?? \Kaleta\Builder\Collections::defaultTemplate($collection);
        // the collection level links to the page with the same slug (e.g. /navod above /navod/<article>) when it exists on
        // the site – with its title; in another language version to its translation (page slugs are unique across
        // languages: /de/vergleich)
        $parentPage = null;
        $main = $db->one('SELECT ids, preklad_z, jazyk, seo_link, titulek, zobrazit FROM {stranky} WHERE seo_link = ? AND smazano IS NULL', [$collection['seo_link']]);
        if ($main !== null && $main['jazyk'] === Language::siteColumn()) {
            $parentPage = $main['zobrazit'] ? $main : null;
        } elseif ($main !== null) {
            $original = (int) ($main['preklad_z'] ?: $main['ids']);
            $parentPage = $db->one('SELECT seo_link, titulek FROM {stranky} WHERE (ids = ? OR preklad_z = ?) AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL LIMIT 1', [$original, $original, Language::siteColumn()]);
        }
        $this->breadcrumbs([$parentPage !== null && $parentPage['titulek'] !== '' ? (string) $parentPage['titulek'] : $collection['nazev'], $parentPage !== null ? $this->app->url((string) $parentPage['seo_link']) : ''],
            [$item['nazev'] ?? t('Sample item'), '']);
        $this->collectionItem = $item !== null ? [(int) $collection['idk'], (string) $collection['seo_link'], (string) $item['seo_link']] : null;
        $k = $this->context();
        $k->item = $item !== null ? \Kaleta\Builder\Collections::values($collection, $item, $this->app->url(...)) : \Kaleta\Builder\Collections::sample($collection);
        $k->editor = $draft && $r->get('editor') === '1';
        $k->source = 'kolekce:' . (int) $collection['idk'];
        $this->pageCollection = (string) $collection['seo_link'];
        $html = \Kaleta\Builder\Build::html($build, $k);
        [$k->item, $k->editor] = [null, false];

        // description and image for search engines and sharing: the item's first longer text and first image
        $description = '';
        $image = '';
        foreach ($collection['pole'] as $field) {
            $h = (string) ($item['data'][$field['klic']] ?? '');
            if ($description === '' && in_array($field['typ'], ['radky', 'html'], true) && $h !== '') {
                $description = mb_strimwidth(trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)), 0, 300, '…');
            }
            if ($image === '' && $field['typ'] === 'obrazek' && $h !== '') {
                $image = preg_match('#^https?://#', $h) ? $h : $this->app->request->origin() . $this->app->url(ltrim($h, '/'));
            }
        }

        // the item's own SEO fields (1.9) win over what is derived from its fields
        if ($item !== null && $item['popis'] !== '') {
            $description = (string) $item['popis'];
        }
        if ($item !== null && $item['obrazek'] !== '') {
            $image = preg_match('#^https?://#', $item['obrazek']) ? (string) $item['obrazek'] : $this->app->request->origin() . $this->app->url(ltrim((string) $item['obrazek'], '/'));
        }
        $title = $item !== null && $item['seo_titulek'] !== '' ? (string) $item['seo_titulek'] : (string) ($item['nazev'] ?? $collection['nazev']);

        return $this->page($title, $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $html]), [
            'popis' => $description, 'obrazek' => $image, 'stavba' => true, 'noindex' => $draft || !empty($item['noindex']),
            'polozka' => $item !== null ? ['kolekce' => $collection, 'polozka' => $item] : null,
        ]);
    }

    /** Home page ID in the shown site version's language (counterpart of the page from Settings); 0 = the news listing is home. */
    private function homePageId(): int
    {
        $id = $this->app->settings()->int('home_page');
        if ($id === 0 || Language::siteColumn() === '') {
            return $id;
        }

        return (int) $this->app->db()->value('SELECT ids FROM {stranky} WHERE preklad_z = ? AND jazyk = ?', [$id, Language::siteColumn()]);
    }

    private function home(): Response
    {
        $page = ($id = $this->homePageId()) > 0 ? $this->app->db()->one('SELECT * FROM {stranky} WHERE ids = ? AND zobrazit = 1', [$id]) : null;

        if ($page === null && !Extensions::isEnabled($this->app->settings(), 'novinky')) {
            // without a home page and without news: the site's first published page
            $page = $this->app->db()->one('SELECT * FROM {stranky} WHERE zobrazit = 1 AND smazano IS NULL AND jazyk = ? ORDER BY poradi, ids LIMIT 1', [Language::siteColumn()]);
        }

        return $page !== null ? $this->showPage($page, '', true) : (Extensions::isEnabled($this->app->settings(), 'novinky') ? $this->showNewsList(true) : $this->notFound());
    }

    /** @param array<string, mixed> $page */
    private function showPage(array $page, string $path, bool $home = false): Response
    {
        $this->counterpart = ['stranky', 'ids', $page, ''];
        $this->isHome = $home;
        if (!$home) {
            // subpage: parent pages in the breadcrumbs too (by slug sluzby/kuchyne → sluzby)
            $levels = [];
            $segments = explode('/', (string) $page['seo_link']);
            for ($i = 1; $i < count($segments); $i++) {
                $parent = $this->app->db()->one('SELECT titulek, seo_link FROM {stranky} WHERE seo_link = ? AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL', [implode('/', array_slice($segments, 0, $i)), $page['jazyk']]);
                if ($parent !== null) {
                    $levels[] = [$parent['titulek'], $this->app->url($parent['seo_link'])];
                }
            }
            $this->breadcrumbs(...[...$levels, [$page['titulek'], '']]);
        }
        // title and data for search engines and sharing (custom title, image, noindex – as with news)
        $title = $page['seo_titulek'] !== '' ? $page['seo_titulek'] : ($home ? '' : $page['titulek']);
        $meta = [
            'popis' => $page['popis'] !== '' ? $page['popis'] : ($home ? $this->app->settings()->get('site_description') : ''),
            'hlavni' => $home, 'obrazek' => $page['obrazek'], 'noindex' => (bool) $page['noindex'],
            'kod_hlavicky' => (string) ($page['kod_hlavicky'] ?? ''), // code in <head> of this page only (2.3)
        ];
        // preview of the draft build for the editor: ?stavba=koncept (only whoever can edit pages), &editor=1 adds markers
        // for selecting elements
        $draft = $this->wantsDraft() && $this->canSeeDraft('stranka:' . (int) $page['ids']);
        $build = \Kaleta\Builder\Build::fromJson($draft ? ($page['stavba_koncept'] ?? $page['stavba']) : $page['stavba']);
        if ($build !== null) {
            $k = $this->context();
            $k->editor = $draft && $this->app->request->get('editor') === '1' && $this->app->request->get('cast') === '';
            $k->source = 'stranka:' . (int) $page['ids'];
            $html = \Kaleta\Builder\Build::html($build, $k);
            $k->editor = false;
            if (!$draft && $this->app->auth()->hasModule('pages')) {
                $this->editHereUrl = $this->app->url('admin.php?module=pages&action=builder&id=' . (int) $page['ids']);
            }

            if ($meta['popis'] === '') {
                $meta['popis'] = self::descriptionFrom($html);
            }

            return $this->page($title, $this->view->render('stranka', ['stranka' => $page, 'uvod' => $home, 'stavba' => $html]), [
                'stavba' => true, 'noindex' => $draft || $meta['noindex'],
            ] + $meta);
        }
        if (($form = $this->editInPlace('stranka', $page, $path)) !== null) {
            return $this->page($page['titulek'], $form, ['noindex' => true]);
        }

        if ($meta['popis'] === '') {
            $meta['popis'] = self::descriptionFrom((string) $page['text']);
        }

        return $this->page($title, $this->view->render('stranka', ['stranka' => $page, 'uvod' => $home, 'stavba' => null]), $meta);
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
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = $this->news->listPublished($pageNumber);
        if (!$home) {
            $this->breadcrumbs([t('Novinky'), '']);
        }

        return $this->page($home ? '' : t('Novinky'), $this->view->render('vypis', ['nadpis' => t('Novinky'), 'popis' => ''] + $this->listVariables($news, $total, $pageNumber, $home ? '' : 'novinky')), [
            'hlavni' => $home,
            // without a site description: the list's own summary (the site name and the latest headlines)
            'popis' => $this->app->settings()->get('site_description') !== '' ? $this->app->settings()->get('site_description')
                : mb_strimwidth(t('Novinky') . ' – ' . $this->app->settings()->get('site_name') . ($news !== [] ? ': ' . implode(' · ', array_column(array_slice($news, 0, 3), 'titulek')) : ''), 0, 160, '…'),
            'cast' => 'vypis',
        ]);
    }

    private function category(string $seo): Response
    {
        $category = $this->app->db()->one('SELECT * FROM {kategorie} WHERE seo_link = ? AND jazyk = ?', [$seo, Language::siteColumn()]);
        if ($category === null) {
            return $this->notFound();
        }
        $this->counterpart = ['kategorie', 'idt', $category, 'novinky/kategorie/'];
        $this->breadcrumbs([t('Novinky'), $this->app->url('novinky')], [$category['nazev'], '']);
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = $this->news->inCategory((int) $category['idt'], $pageNumber);

        return $this->page(
            $category['nazev'],
            $this->view->render('vypis', ['nadpis' => $category['nazev'], 'popis' => \Kaleta\Core\Html::safe((string) $category['popis'])] + $this->listVariables($news, $total, $pageNumber, 'novinky/kategorie/' . $seo)),
            ['popis' => strip_tags($category['popis']), 'cast' => 'vypis'],
        );
    }

    private function tag(string $seo): Response
    {
        $tag = $this->app->db()->one('SELECT * FROM {stitky} WHERE seo_link = ?', [$seo]);
        if ($tag === null) {
            return $this->notFound();
        }
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = $this->news->withTag((int) $tag['ids'], $pageNumber);
        // a tag with a description is a topic page: intro and its own description for search engines
        $colorScheme = trim((string) $tag['popis']) !== '';

        return $this->page($colorScheme ? $tag['nazev'] : t('Štítek') . ' ' . $tag['nazev'], $this->view->render('vypis', [
            'nadpis' => ($colorScheme ? '' : '#') . $tag['nazev'], 'popis' => $colorScheme ? \Kaleta\Core\Html::safe((string) $tag['popis']) : '',
        ] + $this->listVariables($news, $total, $pageNumber, 'novinky/stitek/' . $seo)), [
            'popis' => $colorScheme ? mb_strimwidth(trim(strip_tags((string) $tag['popis'])), 0, 300, '…') : '',
            'cast' => 'vypis',
        ]);
    }

    private function newsItem(string $seo): Response
    {
        $preview = $this->app->request->get('nahled') === '1' && $this->app->auth()->user() !== null;
        $newsItem = $this->news->bySlug($seo, $preview);
        if ($newsItem === null) {
            return $this->notFound();
        }
        if ($newsItem['jazyk'] !== Language::siteColumn()) {
            // the news item belongs to a different language version than the one the request came from
            if ($newsItem['jazyk'] !== '' && !in_array($newsItem['jazyk'], Language::additional($this->app->settings()), true)) {
                return $this->notFound(); // its language version is disabled: a redirect would lead back to the same URL
            }
            $this->app->languagePrefix = $newsItem['jazyk'];

            return Response::redirect($this->app->url('novinky/' . $newsItem['seo_link']) . ($preview ? '?nahled=1' : ''), 301);
        }
        // editing directly on the site works with the raw text from the database (without the outline and embedded players)
        $raw = $this->app->auth()->user() === null ? null : $this->app->db()->one('SELECT * FROM {novinky} WHERE idc = ?', [$newsItem['idc']]);
        if ($raw !== null && ($form = $this->editInPlace('novinka', $raw, 'novinky/' . $newsItem['seo_link'])) !== null) {
            return $this->page($newsItem['titulek'], $form, ['noindex' => true]);
        }
        if (!$preview) {
            $this->app->db()->run('UPDATE {novinky} SET visit = visit + 1 WHERE idc = ?', [$newsItem['idc']]);
        }

        $this->breadcrumbs([t('Novinky'), $this->app->url('novinky')], [$newsItem['tema_jm'], $this->app->url('novinky/kategorie/' . $newsItem['tema_seo'])], [$newsItem['titulek'], '']);
        $newsItem['faq_html'] = (new View([KALETA_SYSTEM . '/views/front']))->render('faq', ['faq' => Seo::faq($newsItem['faq'])]);
        $newsItem = (new NewsText($this->app))->complete($newsItem);
        $newsItem['stitky'] = $this->app->db()->all('SELECT s.nazev, s.seo_link FROM {stitky} s JOIN {novinky_stitky} cs ON cs.ids = s.ids WHERE cs.idc = ? ORDER BY s.nazev', [$newsItem['idc']]);

        $content = $this->view->render('novinka', [
            'novinka' => $newsItem,
            'url' => $this->app->url(...),
            'souvisejici' => $this->app->settings()->bool('related_news_auto') ? $this->news->similar($newsItem) : [],
        ]);

        return $this->page($newsItem['seo_titulek'] !== '' ? $newsItem['seo_titulek'] : $newsItem['titulek'], $content, [
            'clanek' => $newsItem,
            'popis' => $newsItem['seo_popis'] !== '' ? $newsItem['seo_popis'] : mb_strimwidth(trim(strip_tags($newsItem['uvod'])), 0, 300, '…'),
            'klicova_slova' => $newsItem['t_slova'],
            'obrazek' => $newsItem['obrazek'],
            'noindex' => (bool) $newsItem['noindex'],
            'typ' => 'article',
            'cast' => 'novinka',
        ]);
    }

    private function search(): Response
    {
        $q = mb_substr($this->app->request->get('q'), 0, 100);
        // search is the site's most expensive query and is not cached: at most 30 searches per minute from one address
        $antispam = new \Kaleta\Core\Antispam($this->app->db(), $this->app->settings());
        if (mb_strlen($q) >= 3) {
            if ($antispam->count($this->app->request->ip(), 'hledani', 0, 1) >= 30) {
                return new Response(t('Too many searches in a row. Please try again in a moment.'), 429, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '60']);
            }
            $antispam->write($this->app->request->ip(), 'hledani', 0);
        }
        $pageNumber = max(1, $this->app->request->getInt('strana', 1));
        [$news, $total] = mb_strlen($q) >= 3 && Extensions::isEnabled($this->app->settings(), 'novinky') ? $this->news->search($q, $pageNumber) : [[], 0];
        // pages and collection items with their own page – regardless of diacritics, with a snippet (news are found by the
        // fulltext above)
        $pages = [];
        if (mb_strlen($q) >= 3 && $pageNumber === 1) {
            $db = $this->app->db();
            $home = $this->homePageId();
            $candidates = array_map(fn (array $s): array => ['titulek' => $s['titulek'], 'adresa' => (int) $s['ids'] === $home ? '' : $s['seo_link'], 'text' => (string) $s['text']],
                $db->all('SELECT ids, titulek, seo_link, text FROM {stranky} WHERE zobrazit = 1 AND noindex = 0 AND smazano IS NULL AND jazyk = ? ORDER BY poradi LIMIT 500', [Language::siteColumn()]));
            foreach ($db->all('SELECT p.nazev, p.seo_link, p.data, k.seo_link AS kolekce, k.pole FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.detail = 1 AND p.zobrazit = 1 AND p.noindex = 0 AND p.jazyk = ? ORDER BY p.poradi LIMIT 2000', [Language::siteColumn()]) as $p) {
                $data = json_decode((string) $p['data'], true);
                // only text fields are searched – image paths and link URLs would add noise to the results and snippets
                $textFields = array_column(array_filter(json_decode((string) $p['pole'], true) ?: [], fn (array $f): bool => in_array($f['typ'] ?? '', ['text', 'radky', 'html'], true)), 'klic');
                $candidates[] = ['titulek' => $p['nazev'], 'adresa' => $p['kolekce'] . '/' . $p['seo_link'],
                    'text' => implode(' ', array_filter(array_intersect_key(is_array($data) ? $data : [], array_flip($textFields)), 'is_string'))];
            }
            $pages = array_map(fn (array $v): array => ['titulek' => $v['titulek'], 'seo_link' => $v['adresa'], 'uryvek' => $v['uryvek']], \Kaleta\Core\Search::find($q, $candidates));
        }

        return $this->page(
            t('Vyhledávání'),
            $this->view->render('vypis', ['nadpis' => t('Vyhledávání'), 'popis' => '', 'hledano' => $q, 'nalezeneStranky' => $pages] + $this->listVariables($news, $total, $pageNumber, 'hledani', ['q' => $q])),
            ['noindex' => true],
        );
    }

    private function rss(): Response
    {
        $xml = Cache::text($this->app, 'rss|' . Language::siteColumn(), fn (): string => $this->view->render('rss', [
            'web' => $this->app->settings(),
            'novinky' => $this->news->listPublished(1, 20)[0],
            'adresa' => $this->app->request->origin() . $this->app->url(''),
        ]));

        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    private function notFound(): Response
    {
        // before the site answers 404, it tries a redirect from an old URL (manual, after import and after a slug change)
        $target = Extensions::isEnabled($this->app->settings(), 'presmerovani')
            ? $this->app->db()->one('SELECT * FROM {presmerovani} WHERE z_adresy = ?', [trim($this->app->request->path(), '/')])
            : null;
        if ($target !== null) {
            $this->app->db()->run('UPDATE {presmerovani} SET pocet = pocet + 1 WHERE idp = ?', [$target['idp']]);

            return Response::redirect(preg_match('#^https?://#i', $target['na_adresu']) ? $target['na_adresu'] : $this->app->url($target['na_adresu']), (int) ($target['typ'] ?? 301) === 302 ? 302 : 301);
        }

        // overview of not-found URLs for the administrator (Redirects); bots probing other systems are not recorded
        $path = mb_substr(trim($this->app->request->path(), '/'), 0, 255);
        if (($refused = \Kaleta\Core\Firewall::notFound($this->app, $path)) !== null) {
            return $refused; // 2.8: the fifth probe for another system in an hour blocks the address
        }
        if ($path !== '' && $this->app->request->get('cast') === '' && !\Kaleta\Core\NotFound::isBot($path) && mb_check_encoding($path, 'UTF-8')) {
            try {
                if ((int) $this->app->db()->value('SELECT COUNT(*) FROM {nenalezeno}') < 2000 || $this->app->db()->value('SELECT 1 FROM {nenalezeno} WHERE cesta = ?', [$path]) !== null) {
                    $this->app->db()->run('INSERT INTO {nenalezeno} (cesta, pocet, naposledy) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE pocet = pocet + 1, naposledy = NOW()', [$path]);
                    if ((int) $this->app->db()->value('SELECT pocet FROM {nenalezeno} WHERE cesta = ?', [$path]) === \Kaleta\Core\NotFound::SPIKE) {
                        \Kaleta\Core\Events::record($this->app->db(), 'notfound.spike', 'warning', t('/%s was requested %d times and there is no page or redirect.', mb_substr($path, 0, 150), \Kaleta\Core\NotFound::SPIKE), ['path' => $path]);
                    }
                }
            } catch (\Throwable) {
                // the overview is only an aid - a write error must not change the response
            }
        }

        return $this->page(t('Page not found'), $this->view->render('nenalezeno', ['url' => $this->app->url(...), 'stranky' => $this->menuPages(), 'novinky' => Extensions::isEnabled($this->app->settings(), 'novinky')]), ['noindex' => true, 'cast' => 'nenalezeno'], 404);
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
            'novinky' => $news,
            'celkem' => $total,
            'strana' => $pageNumber,
            'stran' => max(1, (int) ceil($total / $this->news->perPage())),
            'strankaUrl' => fn (int $s): string => $this->app->url($path) . (($query = http_build_query($params + ($s > 1 ? ['strana' => $s] : []))) !== '' ? '?' . $query : ''),
            'hledano' => null,
            'nalezeneStranky' => [],
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
            $original = (int) ($newsItem['preklad_z'] ?: $newsItem['idc']);
            $translations = array_map(fn (string $seo): string => 'novinky/' . $seo, $this->app->db()->pairs('SELECT jazyk, seo_link FROM {novinky} WHERE (idc = ? OR preklad_z = ?) AND visible = 1 AND datum <= NOW()', [$original, $original]));
        } elseif ($this->collectionItem !== null) {
            [$idk, $collection, $seo] = $this->collectionItem;
            $translations = array_map(fn (string $s): string => $collection . '/' . $s, $this->app->db()->pairs('SELECT jazyk, seo_link FROM {kolekce_polozky} WHERE idk = ? AND seo_link = ? AND zobrazit = 1', [$idk, $seo]));
        } elseif ($this->counterpart !== null && !$this->isHome) {
            // category or page: the original + its translations
            [$table, $key, $row, $path] = $this->counterpart;
            $original = (int) ($row['preklad_z'] ?: $row[$key]);
            $condition = $table === 'stranky' ? ' AND zobrazit = 1' : '';
            $translations = array_map(fn (string $seo): string => $path . $seo, $this->app->db()->pairs("SELECT jazyk, seo_link FROM {{$table}} WHERE ({$key} = ? OR preklad_z = ?){$condition}", [$original, $original]));
        }
        $root = $this->app->request->basePath() . '/';
        $result = [];
        foreach ([Language::defaults($siteSettings), ...$additional] as $code) {
            $column = Language::column($siteSettings, $code);
            $prefix = $column === '' ? '' : $column . '/';
            $result[$code] = [
                'nazev' => Language::AVAILABLE[$code][0],
                'url' => $root . $prefix . ($translations[$column] ?? ''),
                'aktivni' => $code === Language::code(),
                'preklad' => isset($translations[$column]),
            ];
        }

        return $result;
    }

    /**
     * Editing a page or news item directly on the site. Without the permission it does nothing; with it, it prepares the
     * „Upravit zde“ (Edit here) link, and with ?upravit=text it returns a form with the editor instead of the content.
     * The administration saves it (action save_text).
     *
     * @param array<string, mixed> $record row of ka_stranky or ka_novinky
     */
    private function editInPlace(string $type, array $record, string $path): ?string
    {
        $auth = $this->app->auth();
        if ($auth->user() === null || !($type === 'novinka' ? $auth->canEditArticle($record) : $auth->hasModule('pages'))) {
            return null;
        }
        $url = $this->app->url($path);
        if ($this->app->request->get('upravit') !== 'text') {
            // the draft is visible only in the preview – without it „Upravit zde“ would end on the Not found page
            $this->editHereUrl = $url . ($this->app->request->get('nahled') === '1' ? '?nahled=1&upravit=text' : '?upravit=text');

            return null;
        }

        return $this->view->render('upravit', [
            'app' => $this->app, 'typ' => $type, 'zaznam' => $record,
            'zpet' => $url . ($this->app->request->get('nahled') === '1' ? '?nahled=1' : ''),
            'akce' => $this->app->url('admin.php?module=' . ($type === 'novinka' ? 'news' : 'pages') . '&action=save_text'),
            'chyba' => $this->app->request->get('chyba') === '1',
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
                fn (array $s): array => ['titulek' => $s['titulek'], 'seo_link' => (int) $s['ids'] === $home ? '' : $s['seo_link'], 'uvod' => (int) $s['ids'] === $home],
                $this->app->db()->all('SELECT ids, titulek, seo_link FROM {stranky} WHERE zobrazit = 1 AND v_menu = 1 AND jazyk = ? ORDER BY poradi, titulek', [Language::siteColumn()]),
            );
        }

        return $this->menuPages;
    }

    /** Breadcrumb navigation of the shown page (Breadcrumbs element and BreadcrumbList): Home and the given levels. */
    private function breadcrumbs(array ...$levels): void
    {
        $this->context()->breadcrumbs = [[t('Úvod'), $this->app->url('')], ...$levels];
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
     * part's draft (?cast=<type>&stavba=koncept&editor=1).
     *
     * @param array<string, mixed> $meta
     * @return array{0: string, 1: array{hlavicka: ?string, paticka: ?string}, 2: array<string, mixed>}
     */
    /**
     * The whole-site preview (Core\Preview target "web", from preview_link or Site appearance): every page, site part and
     * collection template shows its draft and the site uses the draft look. The signed link sets a cookie, so the preview
     * stays while the visitor clicks through the site, until the link expires or ?nahled_konec=1 ends it. An administrator
     * in the builder (?stavba=koncept) sees the draft look too.
     */
    private function startSitePreview(): void
    {
        $r = $this->app->request;
        $cookiePath = $r->basePath() . '/';
        if ($r->get('nahled_konec') === '1') {
            setcookie('ka_nahled', '', ['expires' => 1, 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Lax']);
            unset($_COOKIE['ka_nahled']);
        }
        $key = $r->get('nahled_klic') !== '' ? $r->get('nahled_klic') : (string) ($_COOKIE['ka_nahled'] ?? '');
        if ($key !== '' && \Kaleta\Core\Preview::verify($this->app->db(), $this->app->settings(), 'web', $key)) {
            $this->sitePreview = true;
            if ($r->get('nahled_klic') === $key && !headers_sent()) {
                setcookie('ka_nahled', $key, ['expires' => (int) strtok($key, '.'), 'path' => $cookiePath, 'httponly' => true, 'samesite' => 'Lax', 'secure' => $r->isHttps()]);
            }
        }
        if ($this->sitePreview || ($r->get('stavba') === 'koncept' && $this->app->auth()->isAdmin())) {
            \Kaleta\Core\Look::activate($this->app->settings());
        }
    }

    /** Draft instead of the published build: the builder and single previews (?stavba=koncept), or the whole-site preview. */
    private function wantsDraft(): bool
    {
        return $this->sitePreview || $this->app->request->get('stavba') === 'koncept';
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
        if (str_starts_with($target, 'cast:') || str_starts_with($target, 'kolekce:') || str_starts_with($target, 'popup:') ? $auth->isAdmin() : $auth->hasModule('pages')) {
            return true;
        }
        $key = $this->app->request->get('nahled_klic');

        return $key !== '' && \Kaleta\Core\Preview::verify($this->app->db(), $this->app->settings(), $target, $key);
    }

    private function siteParts(string $content, array $meta, string $languageSwitcher, string $path): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $k = $this->context();
        $k->menu = ['hlavni' => $this->menu('hlavni'), 'paticka' => $this->menu('paticka')];
        $k->path = $path;
        $k->languages = $languageSwitcher;
        $preview = isset(\Kaleta\Builder\SiteParts::TYPES[$r->get('cast')]) && $r->get('stavba') === 'koncept'
            && ($this->app->auth()->isAdmin() || $this->canSeeDraft('cast:' . $r->get('cast') . ':' . Language::siteColumn() . ($r->get('varianta') !== '' ? ':' . $r->get('varianta') : ''))) ? $r->get('cast') : '';
        $editor = $r->get('editor') === '1' && ($preview !== '' || ($r->get('stavba') === 'koncept' && $r->get('cast') === ''));
        $language = Language::siteColumn();
        // a site page can have its own header and footer variant; in the variant editor the ?varianta= parameter decides
        $ids = ($this->counterpart[0] ?? '') === 'stranky' ? (int) $this->counterpart[2]['ids'] : null;
        $previewVariant = preg_match(\Kaleta\Builder\SiteParts::VARIANT_PATTERN, $r->get('varianta')) ? $r->get('varianta') : '';
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
            $k->source = 'cast:' . $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
            $html = \Kaleta\Builder\Build::html($build, $k);
            $k->editor = false;

            return $html;
        };

        $wrapper = $meta['cast'] ?? null;
        unset($meta['cast']);
        if ($wrapper !== null) {
            $k->content = $content;
            if (($html = $render($wrapper)) !== null) {
                $content = $html;
                $meta['stavba'] = true;
            }
            $k->content = '';
        }
        $parts = ['hlavicka' => $render('hlavicka'), 'paticka' => $render('paticka')];
        $meta['popupy'] = $this->popups($k, in_array($wrapper, ['novinka', 'vypis'], true));
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
        }
        if ($preview !== '') {
            $meta['noindex'] = true;
        }

        return [$content, $parts, $meta];
    }

    /**
     * Popups for the shown page (Builder\Popups): enabled and published, by the server rules. A draft preview
     * (?popup=<id>&stavba=koncept or /_popup/<id>) adds the given popup even when disabled and opens it immediately.
     */
    private function popups(\Kaleta\Builder\Context $k, bool $news): string
    {
        $r = $this->app->request;
        if ($r->get('nahled') === 'vzhled' || ($r->get('editor') === '1' && !$this->previewPopup)) {
            return ''; // the preview in Appearance and the builder canvas (outside the popup builder) show the page without popups
        }
        $db = $this->app->db();
        $preview = $this->previewPopup ?: ($r->get('stavba') === 'koncept' && preg_match('/^\d{1,9}$/', $r->get('popup')) && $this->canSeeDraft('popup:' . $r->get('popup')) ? (int) $r->get('popup') : 0);
        try {
            $popups = \Kaleta\Builder\Popups::forPage($db, ['ids' => ($this->counterpart[0] ?? '') === 'stranky' ? (int) $this->counterpart[2]['ids'] : null,
                'kolekce' => $this->pageCollection, 'novinky' => $news, 'jazyk' => Language::code(), 'dnes' => date('Y-m-d')]);
            $draft = $preview > 0 ? \Kaleta\Builder\Popups::byId($db, $preview) : null;
        } catch (\Throwable $e) {
            error_log('Pop-up okna: ' . $e->getMessage()); // site before migration

            return '';
        }
        if ($draft !== null) {
            $popups = [...array_filter($popups, fn (array $p): bool => $p['idpp'] !== $preview), ['stavba' => $draft['stavba_koncept'] ?? $draft['stavba'], 'nahled' => true] + $draft];
            $k->withoutCache = true;
        }
        $html = '';
        foreach ($popups as $p) {
            $build = \Kaleta\Builder\Build::fromJson($p['stavba']);
            if ($build === null) {
                continue;
            }
            if ($p['pravidla']['od'] !== '' || $p['pravidla']['do'] !== '') {
                $k->withoutCache = true; // a popup with a date range must not stay in the page cache after it ends
            }
            $k->source = 'popup:' . $p['idpp'];
            // signed-in users (administrators, editors) view the popups, but are not counted in the counters
            $html .= \Kaleta\Builder\Popups::wrapper($p, \Kaleta\Builder\Build::html($build, $k), $this->app->auth()->user() === null ? $this->app->url('popup') : '', !empty($p['nahled']));
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
        if ($p === null || $this->app->request->get('stavba') !== 'koncept' || !$this->canSeeDraft('popup:' . $idpp)) {
            return $this->notFound();
        }
        if ($this->app->request->get('editor') === '1' && $this->app->auth()->isAdmin()) {
            $k = $this->context();
            $k->editor = true;
            $k->source = 'popup:' . $idpp;
            $html = \Kaleta\Builder\Build::html(\Kaleta\Builder\Build::fromJson($p['stavba_koncept'] ?? $p['stavba']) ?? ['deti' => []], $k);
            $k->editor = false;
            $content = '<div class="ka-popup-platno">' . \Kaleta\Builder\Popups::editorWrapper($p, $html) . '</div>';
        } else {
            $this->previewPopup = $idpp;
            $content = '<div class="ka-popup-platno"></div>';
        }

        return $this->page($p['nazev'], $this->view->render('stranka', ['stranka' => ['titulek' => ''], 'uvod' => false, 'stavba' => $content]), ['stavba' => true, 'noindex' => true]);
    }

    private function page(string $title, string $content, array $meta = [], int $status = 200): Response
    {
        if ($this->pastEnd) {
            $this->pastEnd = false;

            return $this->notFound();
        }
        $siteSettings = $this->app->settings();
        $seo = new Seo($this->app);
        $newsItem = $meta['clanek'] ?? null;
        unset($meta['clanek']);
        if ($status === 200 && empty($meta['noindex'])) {
            Stats::record($this->app, $newsItem === null ? null : (int) $newsItem['idc']);
            // real-user speed (2.8) is measured on the same page views the statistics count – never in previews or the
            // builder (noindex), and not for signed-in users, whose pages carry the editing bar
            $meta['vitals'] = Stats::isOn($this->app) && $this->app->request->get('nahled') === '' && $this->app->auth()->user() === null;
        }

        if (($meta['obrazek'] ?? '') !== '' && !preg_match('#^https?://#', $meta['obrazek'])) {
            // social networks accept only a full image URL
            $meta['obrazek'] = $this->app->request->origin() . $this->app->url(ltrim((string) preg_replace('#^' . preg_quote($this->app->request->basePath(), '#') . '/#', '', $meta['obrazek']), '/'));
        }
        $meta['drobecky'] ??= $this->context()->breadcrumbs;
        $languages = $this->languages($newsItem);
        $languageSwitcher = $languages === [] ? '' : $this->view->render('jazyky', ['jazyky' => $languages]);
        // light / dark color scheme switcher for visitors – next to the languages (template, Navigation element)
        $colorScheme = in_array($this->app->settings()->get('dark_mode'), ['auto', 'tmavy'], true) && $this->app->settings()->bool('theme_switcher')
            ? $this->view->render('tema', ['vychozi' => $this->app->settings()->get('dark_mode') === 'tmavy' ? 'tmavy' : 'auto']) : '';
        $languagesHtml = $languageSwitcher . $colorScheme;
        // builder elements: Navigation adds the language switcher (optionally) and the color scheme switcher, Language
        // switcher builds from this list
        $this->context()->languageList = $languages;
        $this->context()->colorScheme = $colorScheme;
        // canonical URL: the path without parameters, with the page number for pagination (page 2 is not a copy of page 1)
        $listPageNumber = $this->app->request->getInt('strana', 1);
        $canonicalUrl = $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')) . ($listPageNumber > 1 ? '?strana=' . $listPageNumber : '');
        if ($this->sitePreview) {
            $meta['noindex'] = true; // the preview of drafts is never indexed nor cached
        }
        [$content, $parts, $meta] = $this->siteParts($content, $meta, $languageSwitcher, (string) parse_url($canonicalUrl, PHP_URL_PATH));
        $popups = (string) ($meta['popupy'] ?? '');
        unset($meta['popupy']);
        $html = $this->view->render('base', [
            'web' => $siteSettings,
            'titulek' => $title,
            'meta' => $meta + ['hlavni' => false, 'popis' => '', 'klicova_slova' => $siteSettings->get('keywords'), 'obrazek' => '', 'typ' => 'website', 'noindex' => false],
            'obsah' => $content,
            'hlava' => $seo->head($title, $meta + ['jazyky' => $languages], $newsItem),
            'pata' => $seo->foot() . $popups . ($this->editHereUrl !== '' ? '<a class="ka-upravit-zde" href="' . e($this->editHereUrl) . '">' . e(t('Edit here')) . '</a>' : ''),
            'stranky' => $this->menuPages(),
            'menu' => $this->menu('hlavni'),
            'menu_paticka' => $this->menu('paticka'),
            'menu_html' => \Kaleta\Core\Menu::html(...),
            'jazyk' => Language::code(),
            'jazyky_html' => $languagesHtml,
            'sNovinkami' => Extensions::isEnabled($siteSettings, 'novinky'), // News extension enabled (RSS links in the template)
            'casti' => $parts,
            'url' => $this->app->url(...),
            'kanonicka' => $canonicalUrl,
        ]);
        $html = ImageHtml::complete($this->app->db(), $html); // image dimensions and background color – less page jumping
        if ($this->sitePreview) {
            $html = (string) preg_replace('/<body[^>]*>/', '$0' . $this->previewBar(), $html, 1);
        }
        $html = $this->localizeSystemLinks($html);
        // image/web.js only on pages that need it (gallery and photos in text, video, sharing, tabs, carousel, modal, form,
        // counter, countdown, submenu – Esc closes it, popups, language versions – browser language on the first visit)
        if (!preg_match('/data-(vlozit|sdilet|kopirovat|zalozky|karusel|formular|odeslano|pocitadlo|odpocet|tema-volba)|popover role="dialog"|galerie|class="(?:text|perex)[" ][\s\S]*?<img|cookies-|<li class="podmenu|data-popup=|rel="alternate" hreflang=/', $html)) {
            $html = (string) preg_replace('#<script src="[^"]*/image/web\.js[^"]*"[^>]*></script>\n?#', '', $html);
        }
        // elements with a display condition (date, sign-in) are assembled anew every time – the cache would show them as they
        // were at the moment of saving
        if ($status === 200 && empty($meta['noindex']) && $this->app->request->get('nahled') === '' && !($this->context?->withoutCache ?? false)) {
            Cache::save($this->app, $html, $newsItem === null ? null : (int) $newsItem['idc']);
        }

        return Response::html($html, $status);
    }

    /** The bar of the whole-site preview: visitors see the published site; a link ends the preview. */
    private function previewBar(): string
    {
        return '<div class="ka-nahled-lista" role="status" style="position:sticky;top:0;z-index:2147483000;display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;align-items:center;'
            . 'padding:0.5rem 1rem;background:#16181d;color:#fff;font:600 0.875rem/1.4 system-ui,sans-serif">'
            . '<span>' . e(t('Preview of drafts – visitors still see the published site.')) . '</span>'
            . '<a href="?nahled_konec=1" style="color:#fff;text-decoration:underline">' . e(t('End the preview')) . '</a></div>';
    }

    /**
     * Links to system URLs stored in the content (href="/novinky" from an older build or a starter site) in the form of the
     * version's language, so they do not go through a redirect (Core\Routes).
     */
    private function localizeSystemLinks(string $html): string
    {
        $language = $this->app->languagePrefix !== '' ? $this->app->languagePrefix : Language::defaults($this->app->settings());
        if (!\Kaleta\Core\Routes::isEnglish($language) || !preg_match('#href="[^"]*/(?:novinky|hledani)#', $html)) {
            return $html;
        }
        $base = preg_quote($this->app->request->basePath() . ($this->app->languagePrefix !== '' ? '/' . $this->app->languagePrefix : ''), '#');

        return (string) preg_replace_callback('#href="' . $base . '/((?:novinky|hledani)(?:[/?\#][^"]*)?)"#',
            fn (array $m): string => 'href="' . e($this->app->url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5))) . '"', $html);
    }
}
