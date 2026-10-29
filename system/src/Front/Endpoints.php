<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;

/**
 * The addresses of the public site that answer without the page frame, or with a short system page: files for browsers, search
 * engines and language models, the beacons of image/web.js, the Claude connection, bookings, the probe and cron. Kernel's route table
 * says which address and condition leads to which method.
 */
final class Endpoints
{
    public function __construct(private readonly App $app, private readonly PageFrame $frame, private readonly NewsRepository $news, private readonly Kernel $kernel)
    {
    }

    /** The web app manifest (/manifest.webmanifest). */
    public function manifest(): Response
    {
        return new Response(SiteIdentity::manifest($this->app->settings(), $this->app->request->basePath()), 200, ['Content-Type' => 'application/manifest+json; charset=utf-8']);
    }

    /** /favicon.ico */
    public function favicon(): Response
    {
        // browsers ask on their own; instead of a full 404 page a link to the site icon, or an empty response
        $icon = is_file(TALEA_ROOT . '/media/icon-32.png') ? $this->app->url('media/icon-32.png') : null;

        return $icon !== null ? Response::redirect($icon, 301) : new Response('', 204, ['Cache-Control' => 'public, max-age=86400']);
    }

    /** /og/<hash>.png */
    public function shareImage(string $hash): Response
    {
        // a share image drawn by the site (2.12, ShareImage); a hash the site did not make is a 404
        return ShareImage::serve($this->app, $hash) ?? $this->kernel->notFound();
    }

    /** /robots.txt */
    public function robots(): Response
    {
        $seo = new Seo($this->app);

        return new Response($seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** /sitemap.xml */
    public function sitemap(): Response
    {
        $seo = new Seo($this->app);

        return new Response(Cache::text($this->app, 'sitemap', $seo->sitemapXml(...)), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** /feed.json, the JSON feed of news */
    public function jsonFeed(): Response
    {
        $seo = new Seo($this->app);
        $json = Cache::text($this->app, 'feed.json|' . Language::siteColumn(), fn (): string => (string) json_encode($seo->jsonFeed($this->news->listPublished(1, 20, true)[0]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return new Response($json, 200, ['Content-Type' => 'application/feed+json; charset=utf-8']);
    }

    /** /.well-known/security.txt, only with the setting security_contact */
    public function securityTxt(): Response
    {
        $request = $this->app->request;
        $siteSettings = $this->app->settings();

        return new Response(Seo::securityTxt($siteSettings->get('security_contact'), $request->origin() . $this->app->url(''),
            array_values(array_unique(array_merge([Language::defaults($siteSettings)], Language::additional($siteSettings)))), time()), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** /llms.txt, only with the setting llms_txt */
    public function llmsTxt(): Response
    {
        $seo = new Seo($this->app);

        return new Response(Cache::text($this->app, 'llms|' . Language::siteColumn(), $seo->llmsTxt(...)), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** The IndexNow key file /<indexnow_key>.txt; null for any other .txt */
    public function indexNowKey(string $file): ?Response
    {
        $indexNowKey = $this->app->settings()->get('indexnow_key');
        if ($indexNowKey !== '' && $file === $indexNowKey) {
            return new Response($indexNowKey, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return null;
    }

    /** POST /consent */
    public function consent(): Response
    {
        $request = $this->app->request;
        // cookie consent log: without the IP address, only a random identifier from the visitor's cookie
        $category = implode(',', array_intersect(explode(',', $request->post('category')), ['analytics', 'marketing'])) ?: 'none';
        $antispam = new \Talea\Core\Antispam($this->app->db(), $this->app->settings());
        if ($this->app->settings()->bool('cookies_log') && preg_match('/^[a-f0-9]{32}$/', $request->post('id')) && $antispam->count($request->ip(), 'consent', 0, 60) < 20) {
            $antispam->write($request->ip(), 'consent', 0);
            $this->app->db()->insert('consents', ['visitor_token' => $request->post('id'), 'created_at' => date('Y-m-d H:i:s'), 'categories' => $category]);
        }

        return new Response('', 204);
    }

    /** POST /popup */
    public function popup(): Response
    {
        $request = $this->app->request;
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

    /** POST /fleet/pair, /fleet/heartbeat, /fleet/unpair and /fleet/kit */
    public function fleet(string $action): Response
    {
        $request = $this->app->request;
        // the fleet console (2.9): sites pair with it and report to it, every request signed by the site's own key;
        // 2.16: a paired site asks for the shared design kit the same way
        $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
        $signature = (string) ($request->serverValues()['HTTP_X_TALEA_SIGNATURE'] ?? '');

        return match ($action) {
            'pair' => \Talea\Fleet\Console::pair($this->app, $body, $signature),
            'heartbeat' => \Talea\Fleet\Console::heartbeat($this->app, $body, $signature),
            'kit' => \Talea\Fleet\Kit::answer($this->app, $body, $signature),
            default => \Talea\Fleet\Console::unpair($this->app, $body, $signature),
        };
    }

    /** POST /vitals */
    public function vitals(): Response
    {
        // real-user speed (2.8): one beacon per page view from image/vitals.js, aggregated per page and day without cookies
        return \Talea\Core\WebVitals::record($this->app);
    }

    /** POST /conversion */
    public function conversion(): Response
    {
        // contact clicks (2.12): a beacon from image/web.js for a click on a phone number, an e-mail address or a WhatsApp
        // link, counted as a lead per page and day without cookies
        return \Talea\Core\Conversions::record($this->app);
    }

    /** POST /experiment */
    public function experiment(): Response
    {
        // A/B tests: a beacon from image/web.js per view and per goal, counted per day and variant without cookies
        return \Talea\Builder\Experiments::record($this->app);
    }

    /** The Claude connection (MCP) */
    public function mcp(): Response
    {
        return (new \Talea\Mcp\Server($this->app))->handle();
    }

    /** /download/<token> */
    public function download(string $token): Response
    {
        // a gated download (2.11, Core\Documents): the file arrives by e-mail after a form is sent, as a signed link
        return \Talea\Core\Documents::gatedDownload($this->app, $token) ?? $this->kernel->notFound();
    }

    /** /subscribe */
    public function subscribe(): Response
    {
        $request = $this->app->request;
        // subscribe: sign-up from the element (only with Newsletter enabled); confirmation and unsubscribe by a link from the
        // e-mail always work – even after the extension is disabled, unsubscribing from already sent e-mails must work
        $subscriptionLink = $request->get('confirm') !== '' || $request->get('unsubscribe') !== '';
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

    /** POST /form (only with Enquiries on) */
    public function form(): Response
    {
        return (new Forms($this->app))->process();
    }

    /** POST /_booking: the booking itself */
    public function booking(): Response
    {
        return (new Booking($this->app))->process();
    }

    /** /_booking/days and /_booking/slots: the free days and times as JSON */
    public function bookingAvailability(string $what): Response
    {
        return (new Booking($this->app))->availability($what);
    }

    /** /_booking/cancel/<token>: the customer's cancel page */
    public function bookingCancel(string $token): Response
    {
        [$heading, $content, $status] = (new Booking($this->app))->cancelPage($token);
        $this->frame->context()->types['button'] = true;

        return $this->frame->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true], $status);
    }

    /** /_booking/choose/<token> */
    public function bookingChoose(string $token): Response
    {
        [$heading, $content, $status] = (new Booking($this->app))->choosePage($token);
        $this->frame->context()->types['button'] = true;

        return $this->frame->page($heading, '<header class="listing-header"><h1>' . e($heading) . '</h1></header>' . $content . '<p><a href="' . e($this->app->url('')) . '">' . e(t('Back to the home page')) . '</a></p>', ['noindex' => true], $status);
    }

    /** /_booking/ics/<token>: the appointment as an .ics file */
    public function bookingIcs(string $token): Response
    {
        return (new Booking($this->app))->ics($token) ?? $this->kernel->notFound();
    }

    /** /health */
    public function health(): Response
    {
        // for an orchestrator (docker HEALTHCHECK, a load balancer): 200 when the database answers and no migration is waiting, else 503 with one word why
        try {
            $pending = \Talea\Core\Migrator::pending($this->app->db()) !== [];
        } catch (\Throwable) {
            return new Response("database\n", 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        return new Response($pending ? "migrations pending\n" : "ok\n", $pending ? 503 : 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** /tasks?token=… */
    public function tasks(): Response
    {
        $request = $this->app->request;
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

    /** /screen/<secret> */
    public function screen(string $secret): Response
    {
        // screen mode (2.11): the kiosk page for a TV in the reception – only with the mode on and the right secret, otherwise 404
        return Screen::opens($this->app->settings()->bool('screen_mode'), $this->app->settings()->get('screen_secret'), $secret) ? Screen::response($this->app) : $this->kernel->notFound();
    }

    /** /status.json?token=… */
    public function status(): Response
    {
        $request = $this->app->request;
        $token = $this->app->settings()->get('health_token');
        if ($token === '' || !hash_equals($token, $request->get('token'))) {
            return Response::json(['error' => 'Invalid token.'], 403);
        }
        // monitoring always gets the texts in English - they must not change with the language of the shown site version
        $checks = Language::runWith('en', fn (): array => \Talea\Core\Health::checks($this->app));

        return Response::json(['status' => \Talea\Core\Health::summary($checks), 'version' => TALEA_VERSION, 'time' => date('c'), 'checks' => $checks]);
    }

    /** POST /_comment */
    public function comment(): Response
    {
        // a comment on a draft from a shared preview link that allows comments (2.15, Core\DraftComments)
        return (new DraftComments($this->app))->post();
    }
}
