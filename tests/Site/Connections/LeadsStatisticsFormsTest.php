<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Connections;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.3 - 2.8: leads, statistics, speed beacons, forms, embeds, page head code, accessibility and hand-over audits, CAPTCHA, GTM, website import,
 * migration report and enquiry import (was: section 45 of tools/test.sh). The OAuth client of section 43 is recreated for the last test.
 */
#[Group('site')]
final class LeadsStatisticsFormsTest extends SiteTestCase
{
    use ConnectionHelpers;

    private const string PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    /** @var array<string, string> hidden fields of the lead form (zdroj, prvek, as_cas, as_podpis) */
    private static array $form = [];
    /** @var resource|null */
    private static $captchaServer = null;

    public static function tearDownAfterClass(): void
    {
        self::stopCaptcha();
        parent::tearDownAfterClass();
    }

    // ---- helpers

    private function clearCache(): void
    {
        $this->site()->clearPageCache();
    }

    /** Fetches /leads-23 as a visitor and remembers the hidden form fields. */
    private function loadForm(): \Kaleta\Tests\Site\Support\Response
    {
        $this->clearCache();
        $response = $this->site()->client()->get('/leads-23');
        self::$form = [];
        foreach (['source', 'element', 'as_cas', 'as_podpis'] as $name) {
            self::$form[$name] = $response->field($name);
        }

        return $response;
    }

    /** Posts the lead form; returns the redirect address (old submit_form / captcha_post). @param array<string, mixed> $fields */
    private function submitForm(array $fields): string
    {
        return $this->site()->client()->post('/formular', self::$form + ['zpet' => '/leads-23'] + $fields)->redirect;
    }

    private function captchaPost(string $email, array $extra = []): string
    {
        return $this->submitForm(['p0' => ['Koupelna'], 'p2' => $email] + $extra);
    }

    private function startCaptcha(): void
    {
        $dir = $this->site()->workDir('captcha');
        file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$answer = $_POST['response'] ?? '';
header('Content-Type: application/json');
echo json_encode(($_POST['secret'] ?? '') !== 'test-secret' ? ['success' => false] : match ($answer) { 'pass' => ['success' => true, 'score' => 0.9], 'low' => ['success' => true, 'score' => 0.2], default => ['success' => false] });
PHP);
        $port = $this->site()->port('captcha');
        self::$captchaServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, 'router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir);
        for ($i = 0; $i < 100; $i++) {
            if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2) !== false) {
                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('The fake CAPTCHA provider did not start.');
    }

    private static function stopCaptcha(): void
    {
        if (self::$captchaServer !== null) {
            proc_terminate(self::$captchaServer);
            proc_close(self::$captchaServer);
            self::$captchaServer = null;
        }
    }

    /** One field of a tool's JSON result (old import_field): arrays come back as compact JSON. */
    private function resultField(string $tool, array $arguments, string $key): string
    {
        return $this->fieldOf($this->toolText($tool, $arguments), $key);
    }

    private function fieldOf(string $text, string $key): string
    {
        $value = (json_decode($text, true) ?: [])[$key] ?? '';

        return is_array($value) ? (string) json_encode($value) : (string) $value;
    }

    /** Runs a paged website import / report call until the phase is not $while any more; returns the last answer text. */
    private function pump(string $tool, array $first, string $idKey, string $while, array $next = []): string
    {
        $text = $this->toolText($tool, $first);
        $id = $this->fieldOf($text, $idKey);
        for ($i = 0; $i < 20 && $this->fieldOf($text, 'phase') === $while; $i++) {
            $text = $this->toolText($tool, [$idKey => $id] + $next);
        }

        return $text;
    }

    private function oldPage(string $title, string $description, string $body): string
    {
        return sprintf('<!doctype html><html><head><title>%s | Old Oak</title><meta name="description" content="%s"></head><body><header><nav><a href="/">Old home</a> <a href="/about-us/">About</a></nav></header><main><h1>%s</h1>%s</main><footer>Old footer 1990</footer></body></html>', $title, $description, $title, $body);
    }

    // ---- tests, in the order of the old section

    public function testACampaignVisitOnAPhoneCountsInTheStatistics(): void
    {
        $site = $this->site();
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'formular'");
        $site->client()->get('/?utm_source=facebook&utm_medium=paid&utm_campaign=autumn', [], self::PHONE);

        $this->assertSame('1|1', $site->value("SELECT CONCAT(COALESCE((SELECT SUM(visits) FROM ka_stats_campaigns WHERE campaign = 'facebook / paid / autumn'), 0), '|', COALESCE((SELECT SUM(visits) FROM ka_stats_devices WHERE device = 'phone'), 0) > 0)"),
            'a visit from a campaign on a phone counts in the statistics');
    }

    #[Depends('testACampaignVisitOnAPhoneCountsInTheStatistics')]
    public function testFormWithTickedOptionsEmbedAndPageHeadCode(): void
    {
        $site = $this->site();
        $page = $this->firstId($site->mcp('vytvor_stranku', ['title' => 'Leads 23', 'visible' => true]));
        $this->assertGreaterThan(0, $page, 'the lead page was created');
        $site->mcp('stavba_uloz', ['id' => $page, 'publikovat' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'form', 'content' => ['name' => 'Poptavka 23', 'fields' => [
                ['label' => 'Sluzby', 'type' => 'checkboxes', 'required' => true, 'checkbox_options' => "Kuchyne\nKoupelna"],
                ['label' => 'Produkt', 'type' => 'hidden', 'value' => 'Dubovy stul'],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
            ]]],
            ['type' => 'embed', 'content' => ['address' => 'https://calendly.com/acme/consultation', 'title' => 'Book a consultation']],
            ['type' => 'embed', 'content' => ['address' => 'https://evil.example/x']],
        ]]]]]);
        // set in the administration, never through MCP (2.5.1)
        $site->exec('UPDATE ka_pages SET head_code = ? WHERE page_id = ?', ['<meta name="kaleta-test" content="23">', $page]);

        $form = $this->loadForm();
        $this->assertStringContainsString('type="checkbox" name="p0[]" value="Kuchyne"', $form->body, 'ticked options on the page');
        $this->assertStringNotContainsString('Dubovy stul', $form->body, 'the hidden value is not on the page');
        $this->assertStringContainsString('data-vlozit="https://calendly.com/acme/consultation?embed_type=Inline&amp;hide_gdpr_banner=1"', $form->body, 'Embed: a known service after a click');
        $this->assertStringNotContainsString('evil.example', $form->body, 'Embed: anything else not at all');
        $this->assertStringContainsString('<meta name="kaleta-test" content="23">', $form->body, 'code in the head of the page');
        $this->assertStringNotContainsString('kaleta-test', $site->client()->get('/')->body, 'code in the head of one page only');

        $answer = $this->answerRaw($site->mcp('update_page', ['id' => $page, 'head_code' => '<script>x()</script>']));
        $this->assertStringContainsString('only in the administration', $answer, 'MCP cannot set head code, not even with full access (2.5.1)');
        $answer = $this->answerRaw($site->mcp('update_settings', ['settings' => ['head_code' => '<script>x()</script>', 'marketing_code' => '<script>y()</script>']]));
        $this->assertStringContainsString('set only in the administration', $answer, 'MCP refuses code for the whole site with a reason');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_settings WHERE name IN ('head_code','marketing_code') AND value LIKE '%<script>%'"), 'MCP cannot set code for the whole site (2.5.1)');
    }

    #[Depends('testFormWithTickedOptionsEmbedAndPageHeadCode')]
    public function testEnquiryOriginAndStatisticsOverMcpAndAdmin(): void
    {
        $site = $this->site();
        sleep(4); // the form must have been open a few seconds (anti-spam time signature)
        $this->submitForm(['p0' => ['Koupelna'], 'p2' => 'petr@example.cz', 'ka_vstup' => '/sluzby', 'ka_kampan' => 'utm_source=google&utm_medium=cpc&utm_campaign=kuchyne', 'ka_odkud' => 'google.com']);
        $this->assertSame('/sluzby|google.com|utm_source=google&utm_medium=cpc&utm_campaign=kuchyne', $site->value("SELECT CONCAT(landing_page, '|', referrer, '|', campaign) FROM ka_enquiries WHERE email = 'petr@example.cz'"),
            'an enquiry carries the first page, the campaign and the referring site of the visit');

        $text = $this->toolText('get_stats', ['days' => 7]);
        $this->assertStringContainsString('"campaign":"google / cpc / kuchyne"', $text, 'get_stats: campaigns');
        $this->assertStringContainsString('"device":"phone"', $text, 'get_stats: devices');
        $this->assertStringContainsString('"path":"/sluzby"', $text, 'get_stats: the first pages of leads');
        $this->assertPage('/admin.php?module=stats&days=7', 200, 'google / cpc / kuchyne', message: 'Statistics: pages, campaigns and first pages that bring leads');
    }

    #[Depends('testEnquiryOriginAndStatisticsOverMcpAndAdmin')]
    public function testRealUserSpeedBeacon(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $this->clearCache();
        $this->assertMatchesRegularExpression('#<script src="/image/vitals.js\?v=[^"]*" defer data-vitals="/vitals"></script>#', $visitor->get('/sluzby')->body, '2.8: the speed beacon script loads deferred with the statistics on');
        $this->assertStringNotContainsString('blocking="render" data-vitals', $visitor->get('/sluzby')->body, '2.8: the beacon script does not block rendering');
        $this->assertStringNotContainsString('vitals.js', $site->admin()->get('/sluzby')->body, '2.8: no speed beacon for signed-in users');

        $beacon = $visitor->post('/vitals', ['path' => '/sluzby', 'lcp' => 1800, 'cls' => 0.05, 'inp' => 120]);
        $this->assertSame(204, $beacon->status, '2.8: a beacon answers 204');
        $visitor->post('/vitals', ['path' => '/neexistuje-vitals', 'lcp' => 1800]);                            // a page the statistics never saw
        $visitor->post('/vitals', ['path' => '/sluzby', 'lcp' => 1800], [], 'curl/8.0');                       // a bot
        $visitor->post('/vitals', ['path' => '/sluzby', 'lcp' => 999999, 'cls' => 'abc']);                     // out of range, not numeric
        $this->assertSame('/sluzby:cls:2:1,/sluzby:inp:2:1,/sluzby:lcp:3:1', $site->value("SELECT GROUP_CONCAT(CONCAT(path, ':', metric, ':', bucket, ':', samples) ORDER BY metric) FROM ka_web_vitals"),
            '2.8: the beacon lands in histogram buckets per metric; made-up pages, bots and nonsense do not');

        $stats = $site->admin()->get('/admin.php?module=stats&days=7')->body;
        $this->assertStringContainsString('href="/sluzby"', $stats, '2.8: Statistics list the page');
        $this->assertMatchesRegularExpression('#2[.,]0 s <span class="stitek stitek-vydano">#', $stats, '2.8: Statistics show p75 LCP with the rating');
        $this->assertMatchesRegularExpression('#150 ms <span class="stitek stitek-vydano">#', $stats, '2.8: Statistics show p75 INP with the rating');
        $text = $this->toolText('get_stats', ['days' => 7]);
        $this->assertStringContainsString('"web_vitals":[{"path":"/sluzby","samples":1,"lcp_p75":2000', $text, '2.8: get_stats carries web_vitals');
        $this->assertStringContainsString('"lcp_rating":"good"', $text, '2.8: get_stats LCP rating');
        $this->assertStringContainsString('"cls_p75":0.05', $text, '2.8: get_stats CLS');
        $this->assertStringContainsString('"inp_p75":150', $text, '2.8: get_stats INP');

        // 3.2: the old setting still works over MCP and switches the Statistics feature
        $site->mcp('update_settings', ['settings' => ['stats' => '0']]);
        $this->clearCache();
        $this->assertSame('0', (string) $site->value("SELECT FIND_IN_SET('statistika', value) FROM ka_settings WHERE name = 'extensions'"), '3.2: update_settings stats=0 switches the Statistics feature off');
        $this->assertStringNotContainsString('vitals', $visitor->get('/sluzby')->body, '2.8: statistics off - no beacon script on the page');
        $visitor->post('/vitals', ['path' => '/sluzby', 'lcp' => 1800]);
        $this->assertSame('3', (string) $site->value('SELECT SUM(samples) FROM ka_web_vitals'), '2.8: statistics off - a beacon is not counted');
        $text = $this->toolText('update_settings', ['settings' => ['stats' => true]]);
        $this->clearCache();
        $this->assertStringContainsString('"stats":"1"', $text, '3.2: update_settings stats=true answers 1');
        $this->assertSame('1', (string) $site->value("SELECT (LENGTH(value) - LENGTH(REPLACE(value, 'statistika', ''))) DIV LENGTH('statistika') FROM ka_settings WHERE name = 'extensions'"),
            '3.2: update_settings stats=true switches the Statistics feature on again, once');

        // the audit: p75 LCP from 2.0 s (30 measurements 35 days ago) to 3.0 s (30 today) is flagged, /sluzby with one measurement is not
        $site->exec("INSERT INTO ka_web_vitals (day, path, metric, bucket, samples) VALUES (CURDATE() - INTERVAL 35 DAY, '/audit-pomalu', 'lcp', 3, 30), (CURDATE(), '/audit-pomalu', 'lcp', 5, 30)");
        $text = $this->toolText('site_audit', ['kind' => 'speed']);
        $this->assertStringContainsString('"path":"/audit-pomalu"', $text, '2.8: the site audit flags the slowed page');
        $this->assertMatchesRegularExpression('#3[.,]0 s#', $text, '2.8: the audit names the new p75 LCP');
        $this->assertStringNotContainsString('/sluzby', $text, '2.8: the site audit flags only a page that got worse by more than 25 %');
        $site->exec("DELETE FROM ka_web_vitals WHERE path = '/audit-pomalu'");
    }

    #[Depends('testRealUserSpeedBeacon')]
    public function testRequiredGroupAndOfferedOptionsOnly(): void
    {
        $site = $this->site();
        $this->assertMatchesRegularExpression('#result=pole&field=0#', $this->submitForm(['p2' => 'tick@example.cz']), 'a required group needs at least one ticked option');
        $this->submitForm(['p0' => ['Kuchyne', 'Podvrh'], 'p1' => 'Hacked', 'p2' => 'tick@example.cz']);
        $this->assertSame('[["Sluzby","Kuchyne"],["Produkt","Dubovy stul"],["Email","tick@example.cz"]]', $site->value("SELECT data FROM ka_enquiries WHERE email = 'tick@example.cz'"),
            "ticked options (only offered ones) and the form's own hidden value are saved");
    }

    #[Depends('testRequiredGroupAndOfferedOptionsOnly')]
    public function testAccessibilityAndHandOverAudits(): void
    {
        $site = $this->site();
        $site->mcp('vytvor_stranku', ['title' => 'Access 23', 'visible' => true, 'text' => '<p>Prices: <a href="/sluzby">click here</a>.</p><table><tr><td>1</td></tr></table>']);
        $text = $this->toolText('site_audit', ['kind' => 'accessibility']);
        $this->assertStringContainsString('click here', $text, 'site audit: link texts');
        $this->assertStringContainsString('header cells', $text, 'site audit: tables');
        $this->assertStringContainsString('accessibility statement', $text, 'site audit: the accessibility statement');

        // 2.4 for agencies
        $this->assertPage('/admin.php?module=roles&action=new&preset=client', 200, 'name="name" value="Klient"', message: '2.4: ready-made Client role fills the form');
        $text = $this->toolText('site_audit', ['kind' => 'handover']);
        $this->assertMatchesRegularExpression('#"handover": ?"agency"#', $text, 'hand-over check: agency contact missing');
        $this->assertMatchesRegularExpression('#"handover": ?"smtp"#', $text, 'hand-over check: SMTP missing');

        $site->mcp('update_settings', ['settings' => ['agency_name' => 'Studio Test', 'agency_email' => 'help@studio.example', 'agency_phone' => '+420 777 123 456']]);
        $login = $site->client()->get('/admin.php')->body;
        $this->assertStringContainsString('Studio Test', $login, 'sign-in screen shows whom to ask for help');
        $this->assertStringContainsString('mailto:help@studio.example', $login, 'sign-in screen: the agency e-mail');
        $this->assertStringContainsString('tel:+420777123456', $login, 'sign-in screen: the agency phone');
        $this->assertPage('/admin.php?module=pages', 200, 'class="agentura"', message: '2.4: admin footer shows the agency');
        $this->assertDoesNotMatchRegularExpression('#"handover": ?"agency"#', $this->toolText('site_audit', ['kind' => 'handover']), 'hand-over check: the agency contact is set');
    }

    #[Depends('testRequiredGroupAndOfferedOptionsOnly')]
    public function testCookieBarAsksConsentForLeadOrigins(): void
    {
        $site = $this->site();
        $site->mcp('update_settings', ['settings' => ['cookies_mode' => 'vestavena', 'lead_attribution' => '1']]);
        $this->clearCache();
        $body = $site->client()->get('/leads-23')->body;
        $this->assertStringContainsString('data-kategorie="marketing"', $body, 'cookie bar: marketing consent');
        $this->assertStringContainsString('ka-puvod', $body, 'cookie bar: lead origin script');
        $this->assertStringContainsString('globalPrivacyControl', $body, 'cookie bar: Global Privacy Control');
        $this->assertStringContainsString('name="ka_vstup"', $body, 'cookie bar: the form carries the origin field');
        $site->mcp('update_settings', ['settings' => ['lead_attribution' => '0']]);
    }

    #[Depends('testRequiredGroupAndOfferedOptionsOnly')]
    public function testCaptchaOnTopOfTheBuiltInProtection(): void
    {
        $site = $this->site();
        $this->startCaptcha();
        foreach ([['captcha_provider', 'turnstile'], ['captcha_site_key', 'test-site'], ['captcha_secret', 'test-secret']] as [$name, $value]) {
            $site->setting($name, $value);
        }
        $site->exec("DELETE FROM ka_ip_checks WHERE type IN ('formular','odber')");

        $form = $this->loadForm();
        $this->assertStringContainsString('class="ka-captcha cf-turnstile" data-sitekey="test-site"', $form->body, 'CAPTCHA: the Turnstile widget in the form');
        $this->assertSame(1, substr_count($form->body, 'challenges.cloudflare.com/turnstile/v0/api.js'), 'CAPTCHA: its script once');
        sleep(4);
        $this->assertStringContainsString('result=captcha', $this->captchaPost('fail@example.cz', ['cf-turnstile-response' => 'wrong']), 'CAPTCHA: a failed check is refused');
        $this->assertStringContainsString('result=captcha', $this->captchaPost('none@example.cz'), 'CAPTCHA: a form without the answer is refused');
        $this->captchaPost('pass@example.cz', ['cf-turnstile-response' => 'pass']);
        $this->assertSame('pass@example.cz', $site->value("SELECT GROUP_CONCAT(email ORDER BY email) FROM ka_enquiries WHERE email IN ('fail@example.cz','none@example.cz','pass@example.cz')"),
            'CAPTCHA: a passed check saves the enquiry, the failed ones not');

        $site->setting('captcha_provider', 'recaptcha');
        $form = $this->loadForm();
        $this->assertStringContainsString('name="g-recaptcha-response" value="" data-recaptcha="test-site"', $form->body, 'reCAPTCHA v3 field');
        $this->assertStringContainsString('recaptcha/api.js?render=test-site', $form->body, 'reCAPTCHA v3 script');
        sleep(4);
        $this->assertStringContainsString('result=captcha', $this->captchaPost('low@example.cz', ['g-recaptcha-response' => 'low']), 'reCAPTCHA v3: a low score is refused');

        self::stopCaptcha();
        $this->captchaPost('down@example.cz', ['g-recaptcha-response' => 'pass']);
        $site->setting('captcha_fail_open', '0');
        $this->assertStringContainsString('result=captcha', $this->captchaPost('closed@example.cz', ['g-recaptcha-response' => 'pass']), 'CAPTCHA: fail closed when the owner chose so');
        $this->assertSame('down@example.cz', $site->value("SELECT GROUP_CONCAT(email) FROM ka_enquiries WHERE email IN ('down@example.cz','closed@example.cz')"),
            "CAPTCHA: when the provider is down the owner's choice decides");

        $site->mcp('update_settings', ['settings' => ['captcha_secret' => 'stolen', 'captcha_provider' => 'hcaptcha']]);
        $this->assertSame('test-secret', $site->settingValue('captcha_secret'), 'CAPTCHA: Claude cannot set the secret key');
        $this->assertStringNotContainsString('test-secret', $this->answerRaw($site->mcp('update_settings', [])), 'CAPTCHA: Claude cannot read the secret key');
        $site->exec("DELETE FROM ka_settings WHERE name LIKE 'captcha_%'");
    }

    #[Depends('testRequiredGroupAndOfferedOptionsOnly')]
    public function testGoogleTagManagerWithConsentMode(): void
    {
        $site = $this->site();
        // 3.3.2 (N27): Claude can no longer set GTM or Matomo - they load script their owner chooses
        $answer = $this->answerRaw($site->mcp('update_settings', ['settings' => ['gtm_id' => 'GTM-EVIL1', 'matomo_url' => 'https://evil.example/', 'matomo_id' => '1', 'ga4_id' => 'G-ABCD1234']]));
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_settings WHERE name IN ('gtm_id','matomo_url','matomo_id') AND value <> ''"), 'MCP: gtm_id and matomo_* are refused');
        $this->assertSame('G-ABCD1234', $site->settingValue('ga4_id'), 'MCP: ga4_id is still accepted');
        $this->assertStringContainsString('Google Tag Manager and Matomo load script', $answer, 'MCP: the refusal gives a reason');
        $site->mcp('update_settings', ['settings' => ['ga4_id' => '']]);

        $site->setting('gtm_id', 'GTM-TEST123');
        $site->mcp('update_settings', ['settings' => ['cookies_mode' => 'vestavena']]);
        $this->clearCache();
        $body = $site->client()->get('/leads-23')->body;
        $this->assertStringContainsString('<script type="text/plain" data-gtm>(function(w,d,s,l,i)', $body, 'GTM: the container waits for the cookie bar');
        $this->assertStringContainsString("gtag('consent','default',{ad_storage:'denied'", $body, 'GTM: consent mode default');
        $this->assertStringContainsString("'dataLayer','GTM-TEST123'", $body, 'GTM: the container id');
        $this->assertStringContainsString('data-kategorie="analytika"', $body, 'GTM: analytics category in the bar');
        $this->assertStringContainsString('data-kategorie="marketing"', $body, 'GTM: marketing category in the bar');

        $site->mcp('update_settings', ['settings' => ['cookies_mode' => 'zadna']]);
        $this->clearCache();
        $body = $site->client()->get('/leads-23')->body;
        $this->assertStringContainsString('<script>(function(w,d,s,l,i)', $body, 'GTM: without a cookie bar the container loads right away');
        $this->assertStringNotContainsString("gtag('consent','default'", $body, 'GTM: without a cookie bar no consent mode');
        $site->setting('gtm_id', '');
        $site->mcp('update_settings', ['settings' => ['cookies_mode' => 'vestavena']]);
        $this->clearCache();
    }

    #[Depends('testRequiredGroupAndOfferedOptionsOnly')]
    public function testWebsiteImportMigrationReportAndEnquiryImport(): void
    {
        $site = $this->site();
        $old = $site->workDir('oldsite');
        foreach (['about-us', 'blog/first-post', 'img', 'contact'] as $dir) {
            @mkdir($old . '/' . $dir, 0775, true);
        }
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 90));
        imagepng($image, $old . '/img/team.png');
        file_put_contents($old . '/router.php', '<?php return false;');
        $port = $site->freePort();
        $origin = 'http://127.0.0.1:' . $port;
        file_put_contents($old . '/robots.txt', "User-agent: *\nSitemap: $origin/sitemap.xml\n");
        $sitemap = static fn (array $paths): string => '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', array_map(static fn ($p) => "<url><loc>$origin$p</loc></url>", $paths)) . '</urlset>';
        file_put_contents($old . '/sitemap.xml', $sitemap(['/', '/about-us/', '/blog/first-post/']));
        file_put_contents($old . '/index.html', $this->oldPage('Welcome', 'The old home page', '<p>Old Oak makes furniture by hand in our workshop near the river, since many years, for homes and offices alike.</p>'));
        file_put_contents($old . '/about-us/index.html', $this->oldPage('About us', 'Who we are', '<p>We build oak furniture since 1990, for homes and offices across the region and beyond it, always by hand.</p><img src="/img/team.png" alt="Our team"><p><a href="/blog/first-post/">Read our story</a></p><div class="cookie-notice">We use cookies</div><p>Our tools id="</p><p title="><svg onload=alert(1)>">and</p><img src="/img/team.png" alt="q><svg onload=alert(2)>"><video src="/film.mp4" controls></video>'));
        file_put_contents($old . '/blog/first-post/index.html', '<!doctype html><html><head><title>Our first post | Old Oak</title><meta property="article:published_time" content="2024-05-06T09:00:00+02:00"></head><body><article><h1>Our first post</h1><p>Today we opened the new workshop for visitors, come and see how a table is made from a single oak.</p></article></body></html>');
        $site->startPhp($old, 'router.php', [], $port);

        $text = $this->pump('import_website', ['url' => $origin], 'import_id', 'finding');
        $this->assertSame('preview', $this->fieldOf($text, 'phase'), 'website import: the pages are shown before importing');
        $this->assertSame('3', $this->fieldOf($text, 'found'), 'website import: three pages found in the sitemap');
        $this->assertStringContainsString('/about-us', $text, 'website import: the preview lists the about page');
        $importId = $this->fieldOf($text, 'import_id');
        $this->toolText('import_website', ['import_id' => $importId, 'confirm' => true]);
        $text = $this->toolText('import_website', ['import_id' => $importId]);
        for ($i = 0; $i < 20 && $this->fieldOf($text, 'phase') === 'importing'; $i++) {
            $text = $this->toolText('import_website', ['import_id' => $importId]);
        }
        $this->assertSame('done', $this->fieldOf($text, 'phase'), 'website import finished');

        $this->assertSame('About us:0|Our first post:0:2024-05-06', $site->value("SELECT CONCAT((SELECT CONCAT(title, ':', visible) FROM ka_pages WHERE slug = 'about-us'), '|', (SELECT CONCAT(title, ':', visible, ':', DATE(published_at)) FROM ka_news WHERE title = 'Our first post'))"),
            'website import: pages hidden, the post as a hidden news item');
        $about = (string) $site->value("SELECT CONCAT(text, ' ', IFNULL(build, '')) FROM ka_pages WHERE slug = 'about-us'");
        $this->assertStringContainsString('oak furniture', $about, 'website import: the content is imported');
        $this->assertStringContainsString('media/', $about, 'website import: the image is in Media');
        $this->assertDoesNotMatchRegularExpression('#Old footer|Old home|We use cookies|127\.0\.0\.1#', $about, 'website import: no header, footer or cookie bar');
        $this->assertStringContainsString('"type":"heading"', $about, 'website import: the content is in the builder');
        // 3.3.2 (N23, N30): markup in attribute values of the old site stays text, and an imported page never gets Custom HTML
        $aboutText = (string) $site->value("SELECT text FROM ka_pages WHERE slug = 'about-us'");
        $this->assertStringNotContainsString('<svg', $aboutText, 'website import: attribute text never becomes markup');
        $this->assertStringContainsString('alt="q&gt;&lt;svg onload=alert(2)&gt;"', $aboutText, 'website import: attribute text stays escaped text');
        $this->assertStringNotContainsString('"type":"custom_html"', $about, 'website import: no Custom HTML from the old site');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_redirects WHERE from_path = 'blog/first-post' AND to_path LIKE 'novinky/%'"), 'website import: the old address of the post redirects');

        $text = $this->pump('import_website', ['url' => $origin], 'import_id', 'finding');
        $importId = $this->fieldOf($text, 'import_id');
        $this->toolText('import_website', ['import_id' => $importId, 'confirm' => true]);
        $text = $this->toolText('import_website', ['import_id' => $importId]);
        for ($i = 0; $i < 20 && $this->fieldOf($text, 'phase') === 'importing'; $i++) {
            $text = $this->toolText('import_website', ['import_id' => $importId]);
        }
        $this->assertSame('{"new_pages":0,"new_news":0,"images":0,"redirects":0,"skipped":3,"failed":0}', $this->fieldOf($text, 'result'), 'website import: running it again skips what is already there');

        // 2.7: the migration report - a fourth old page with a form that nothing on the new site answers
        file_put_contents($old . '/contact/index.html', $this->oldPage('Contact', 'Write to us', '<p>Write to us about a table, a chair or a whole kitchen and we answer within two working days, promised.</p><form action="/send"><input name="email"><textarea name="message"></textarea></form>'));
        file_put_contents($old . '/sitemap.xml', $sitemap(['/', '/about-us/', '/blog/first-post/', '/contact/']));
        $report = $this->reportDone($origin);
        $this->assertSame('{"addresses":4,"checked":4,"ok":1,"redirected":0,"not_published":2,"missing":1,"errors":1,"warnings":3}', $this->fieldOf($report, 'summary'),
            'migration report: four old addresses - the imported ones not published yet, the contact page missing');
        $this->assertStringContainsString('/contact', $report, 'migration report: the contact address is listed');
        $this->assertMatchesRegularExpression('#form_missing|missing#', $report, 'migration report: the missing page is flagged');
        $this->assertStringContainsString('site_checks', $report, 'migration report: the checks of the whole site');

        $site->mcp('save_redirect', ['from' => '/contact', 'to' => '/about-us']);
        $site->exec("UPDATE ka_pages SET visible = 1 WHERE slug = 'about-us'");
        $this->assertSame('{"addresses":4,"checked":4,"ok":2,"redirected":1,"not_published":1,"missing":0,"errors":1,"warnings":2}', $this->fieldOf($this->reportDone($origin), 'summary'),
            'migration report: after a redirect and publishing, the contact address redirects (but the form is gone)');
        $site->exec("UPDATE ka_pages SET visible = 0 WHERE slug = 'about-us'");
        $site->exec("DELETE FROM ka_redirects WHERE from_path = 'contact'");

        // 2.7: old form entries (e.g. Breakdance submissions) come over into Enquiries, once
        $entries = [
            ['date' => '2025-03-14 09:30', 'form' => 'Contact', 'page' => '/contact', 'fields' => ['Name' => 'Jana Old', 'E-mail' => 'jana.old@example.cz', 'Message' => 'A table please']],
            ['date' => '2025-03-15 10:00', 'form' => 'Contact', 'fields' => [['label' => 'Phone', 'value' => '777 000 111']]],
        ];
        $first = $this->toolText('import_enquiries', ['source' => 'breakdance', 'entries' => $entries]);
        $this->assertSame('2|2:jana.old@example.cz:1', $this->fieldOf($first, 'imported') . '|' . $site->value("SELECT CONCAT(COUNT(*), ':', MAX(email), ':', MIN(status)) FROM ka_enquiries WHERE source = 'import:breakdance'"),
            'import_enquiries: two old entries imported');
        $second = $this->toolText('import_enquiries', ['source' => 'breakdance', 'entries' => $entries]);
        $this->assertSame('0:2', $this->fieldOf($second, 'imported') . ':' . $this->fieldOf($second, 'already_imported'), 'import_enquiries: a second run skips them');
    }

    /** Starts a migration report and polls it until it is done; returns the last answer text. */
    private function reportDone(string $origin): string
    {
        $text = $this->toolText('migration_report', ['url' => $origin]);
        $id = $this->fieldOf($text, 'report_id');
        for ($i = 0; $i < 20 && $this->fieldOf($text, 'phase') !== 'done'; $i++) {
            $text = $this->toolText('migration_report', ['report_id' => $id]);
        }

        return $text;
    }

    public function testSignInThroughTheConsentPageAndDisconnectingAnApp(): void
    {
        $site = $this->site();
        $client = $this->registerClient();
        $this->exchangeCode($client, $this->authorizationCode($site->admin(), $client, 'abc'));
        $this->assertGreaterThan(0, (int) $site->value('SELECT COUNT(*) FROM ka_api_tokens WHERE client_id = ?', [$client]), 'the connected app has tokens');

        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'login'"); // the sign-in limit per address was used up by the account lock test
        $visitor = $site->client('consent');
        $visitor->get('/oauth/authorize?response_type=code&client_id=' . $client . '&redirect_uri=' . self::REDIRECT_URI . '&code_challenge=' . $this->pkceChallenge() . '&code_challenge_method=S256&state=nove');
        $csrf = $visitor->get('/admin.php?action=oauth')->csrf();
        $redirect = $visitor->post('/admin.php', ['_csrf' => $csrf, 'username' => 'admin', 'password' => $site->password])->redirect;
        $this->assertStringContainsString('action=oauth', $redirect, 'a signed-out person returns to the consent page after signing in');

        $account = $site->admin()->get('/admin.php?action=account');
        $this->assertStringContainsString('Připojené aplikace', $account->body, 'the connected app in My account');
        $site->admin()->post('/admin.php?action=account', ['_csrf' => $account->csrf(), 'odpojit_klient' => $client]);
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_api_tokens WHERE client_id = ?', [$client]), 'disconnecting the app deletes its tokens');
    }
}
