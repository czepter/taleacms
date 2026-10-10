<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Domain watch as a bundled add-on (issue #28; was Core\DomainWatch with calls in Health, Audit, Scheduler and Settings). Off by default:
 * nothing of it exists. Switched on: the job, the System status rows, the hand-over findings, the settings page, the tools for Claude,
 * the alert e-mail. The checks themselves are fed fixtures (a fake resolver, a fake certificate and registry), as in the add-on's unit tests
 * (extensions/domain_watch/tests/unit.php). The tests of the class run in order.
 */
#[Group('site')]
final class DomainWatchAddonTest extends SiteTestCase
{
    use AgentHelpers;

    /** One check with fixtures instead of the network, stored and alerted as the job does: the site "example.cz", the certificate and the domain with the days left given. */
    private function checkWith(int $certificateDays, bool $spf = true): void
    {
        $code = <<<'PHP'
$app = new Talea\Core\App(require "config.php"); $app->applyTimezone();
Talea\Extension\Registry::boot($app);
$api = new Talea\Extension\Api(Talea\Extension\Registry::get(), "domain_watch", $app, 2, ["outgoing_requests", "mail"]);
$now = time();
$dns = fn (string $name, int $type): array => match ($name) {
    "example.cz" => [["type" => "TXT", "txt" => __SPF__]],
    "_dmarc.example.cz" => [["type" => "TXT", "txt" => "v=DMARC1; p=none"]],
    "google._domainkey.example.cz" => [["type" => "TXT", "txt" => "v=DKIM1; k=rsa; p=MIGfMA0G"]],
    default => [],
};
$http = fn (string $url): array => [200, json_encode(["events" => [["eventAction" => "expiration", "eventDate" => gmdate("c", $now + 200 * 86400)]]])];
$watch = new TaleaAddon\DomainWatch\DomainWatch($dns, $http, fn (string $host): int => $now + __DAYS__ * 86400);
$result = $watch->collect(["site_host" => "www.example.cz", "https" => true, "mail_domain" => "example.cz", "smtp_host" => "smtp.gmail.com", "report_email" => "info@example.cz"], $now);
$watch->record($api, $result);
echo "recorded";
PHP;
        $code = str_replace(['__DAYS__', '__SPF__'], [(string) $certificateDays, $spf ? '"v=spf1 include:_spf.google.com ~all"' : '"nothing useful"'], $code);

        $this->assertStringContainsString('recorded', $this->site()->php($code), 'the check with fixtures ran');
    }

    public function testNothingOfItExistsWhileItIsOff(): void
    {
        $page = $this->assertPage('/admin.php?module=addons', 200, ['Domain watch', 'Official add-on, shipped with Talea', 'makes requests to other servers', 'sends e-mail'], message: 'the bundled add-on is listed with what it declares');
        $this->assertStringContainsString('Switch on', $page->body, 'and can be switched on');

        $status = $this->assertPage('/admin.php?module=status', 200, [], message: 'System status');
        $this->assertStringNotContainsString('Domain and mail', $status->body, 'no health rows');
        $this->assertStringNotContainsString('Check now', $status->body, 'no Check now button in System status');
        $this->assertStringNotContainsString('Domain, certificate and mail records', $status->body, 'no job');
        $this->assertDoesNotMatchRegularExpression('/"handover": ?"domain_watch/', $this->mcpText('site_audit', ['kind' => 'handover']), 'no hand-over findings');
        $this->assertSame(404, $this->site()->admin()->get('/admin.php?module=addons&action=page&p=domain_watch.settings')->status, 'no settings page');
        $this->assertStringNotContainsString('ext_domain_watch_status', $this->mcpRawText('get_health') . json_encode($this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')), 'no tool for Claude');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_settings WHERE name = 'domain_watch' OR name LIKE 'ext.domain_watch.%'"), 'no setting');
    }

    public function testTheResultOfTheOldSettingIsCarriedOverOnceWhenItIsSwitchedOn(): void
    {
        $old = (string) json_encode(['checked' => time() - 3600, 'site_host' => 'old.example.cz', 'report_email' => '', 'mail' => null, 'tls' => null, 'domain' => null, 'local' => true]);
        $this->site()->exec("INSERT INTO tl_settings (name, value) VALUES ('domain_watch', ?)", [$old]);

        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'domain_watch', 'on' => 1, 'trust' => 1], '/admin.php?module=addons');
        $this->assertSame('domain_watch', $this->sq("SELECT value FROM tl_settings WHERE name = 'addons_enabled'"), 'switched on');
        $this->assertSame($old, $this->sq("SELECT value FROM tl_settings WHERE name = 'ext.domain_watch.result'"), 'the cached result moved to the add-on\'s setting');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_settings WHERE name = 'domain_watch'"), 'the old key is gone');

        // once: switched off and on again with another old value, the add-on's own result stays
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'domain_watch', 'on' => 0], '/admin.php?module=addons');
        $this->site()->exec("INSERT INTO tl_settings (name, value) VALUES ('domain_watch', 'stale')");
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'domain_watch', 'on' => 1, 'trust' => 1], '/admin.php?module=addons');
        $this->assertSame($old, $this->sq("SELECT value FROM tl_settings WHERE name = 'ext.domain_watch.result'"), 'the second switch-on does not overwrite it');
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_settings WHERE name = 'domain_watch'"), 'and the old key is removed again');
    }

    public function testSwitchedOnItAddsItsJobItsRowsAndItsSettingsPage(): void
    {
        $status = $this->assertPage('/admin.php?module=status', 200, ['Domain and mail', 'Domain, certificate and mail records', 'runs on a local address'], message: 'rows and the job in System status (a local site is one ok row)');
        $this->assertStringNotContainsString('Check now', $status->body, 'the Check now button lives on the add-on\'s page');

        $page = '/admin.php?module=addons&action=page&p=domain_watch.settings';
        $this->assertPage($page, 200, ['Send an alert e-mail', 'Check now', 'Last checked'], message: 'the settings page');
        $this->adminPost($page, ['check_now' => 1], $page);
        $this->assertSame('true', $this->sq("SELECT JSON_EXTRACT(value, '$.local') FROM tl_settings WHERE name = 'ext.domain_watch.result'"), 'Check now stores the result; a local address is not checked');
        $this->assertStringContainsString('local address', $this->site()->admin()->get($page)->body, 'and says so');

        $this->site()->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'ext_domain_watch_check'");
        $this->site()->runTasks();
        $this->assertSame('1', $this->sq("SELECT COUNT(*) FROM tl_jobs WHERE name = 'ext_domain_watch_check' AND last_ok IS NOT NULL AND last_error = ''"), 'the daily job ran');

        $tool = $this->mcpData('ext_domain_watch_status');
        $this->assertTrue($tool['local'] ?? false, 'the tool for Claude reports the last result');
        $this->assertSame('ok', $tool['rows'][0]['status'] ?? '', 'with the rows of System status');
        $this->assertTrue(($this->mcpData('ext_domain_watch_check_now')['local'] ?? false), 'check_now runs the check');
    }

    public function testTheSameResultsAsBeforeForTheFakeDnsAndCertificate(): void
    {
        $this->checkWith(15);
        $rows = (string) $this->site()->admin()->get('/admin.php?module=status')->body;
        foreach (['SPF record', 'DMARC record', 'DKIM signature', 'Certificate', 'Domain registration', 'expires in 15 days'] as $text) {
            $this->assertStringContainsString($text, $rows, "System status: $text");
        }
        $audit = $this->mcpText('site_audit', ['kind' => 'handover']);
        $this->assertMatchesRegularExpression('/"handover": ?"domain_watch\.certificate"/', $audit, 'the certificate under 21 days is a hand-over finding');
        $this->assertDoesNotMatchRegularExpression('/"handover": ?"domain_watch\.(spf|dmarc)"/', $audit, 'SPF and DMARC are fine');
        $this->assertStringContainsString('The site certificate expires in 15 days', $audit);

        $this->checkWith(60, spf: false);
        $audit = $this->mcpText('site_audit', ['kind' => 'handover']);
        $this->assertMatchesRegularExpression('/"handover": ?"domain_watch\.spf"/', $audit, 'a missing SPF record is a finding (with the record to add)');
        $this->assertDoesNotMatchRegularExpression('/"handover": ?"domain_watch\.certificate"/', $audit, 'the certificate is fine again');
    }

    public function testANearExpirySendsOneAlertEmailAndTheSwitchTurnsItOff(): void
    {
        $site = $this->site();
        $site->setting('site_email', 'owner@example.test');
        $problems = "SELECT COUNT(*) FROM tl_mail WHERE subject LIKE '%problem%'";
        $events = "SELECT COUNT(*) FROM tl_events WHERE type = 'domain_watch.expiring'";
        $before = (int) $this->sq($problems);
        $base = (int) $this->sq($events); // the earlier test already raised one
        $site->exec("UPDATE tl_settings SET value = (SELECT COALESCE(MAX(id), 0) FROM tl_events) WHERE name = 'alerts_cursor'");
        $site->exec("UPDATE tl_settings SET value = '0' WHERE name = 'alerts_last_sent'");
        $site->exec("INSERT INTO tl_settings (name, value) SELECT 'alerts_cursor', (SELECT COALESCE(MAX(id), 0) FROM tl_events) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tl_settings WHERE name = 'alerts_cursor')");

        $this->checkWith(15);
        $this->assertSame($base + 1, (int) $this->sq($events), 'the near expiry is an event');
        $this->checkWith(14);
        $this->assertSame($base + 1, (int) $this->sq($events), 'but only once while it lasts');
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'alerts'");
        $site->runTasks();
        $this->assertSame($before + 1, (int) $this->sq($problems), 'the alert e-mail went out');
        $this->assertStringContainsString('The site certificate expires in 15 days', $this->sq("SELECT message FROM tl_events WHERE type = 'domain_watch.expiring' ORDER BY id LIMIT 1"), 'the event says what is about to expire');

        $this->checkWith(60);
        $this->checkWith(3);
        $this->assertSame($base + 2, (int) $this->sq($events), 'an expiry that was fine in between alerts again at once');

        $page = '/admin.php?module=addons&action=page&p=domain_watch.settings';
        $this->adminPost($page, ['_ext_settings' => 1], $page); // the checkbox is not sent: alerts off
        $this->checkWith(60);
        $this->checkWith(2);
        $this->assertSame($base + 2, (int) $this->sq($events), 'with the alerts switched off no event is recorded');
    }

    public function testSwitchedOffAgainTheResultStaysButNothingElse(): void
    {
        $this->adminPost('/admin.php?module=addons&action=toggle', ['slug' => 'domain_watch', 'on' => 0], '/admin.php?module=addons');
        $status = $this->assertPage('/admin.php?module=status', 200, [], message: 'System status');
        $this->assertStringNotContainsString('Domain and mail', $status->body, 'the rows are gone');
        $this->assertStringNotContainsString('Domain, certificate and mail records', $status->body, 'the job is gone');
        $this->assertDoesNotMatchRegularExpression('/"handover": ?"domain_watch/', $this->mcpText('site_audit', ['kind' => 'handover']), 'the findings are gone');
        $this->assertSame(404, $this->site()->admin()->get('/admin.php?module=addons&action=page&p=domain_watch.settings')->status, 'the settings page is gone');
        $this->assertNotSame('', $this->sq("SELECT value FROM tl_settings WHERE name = 'ext.domain_watch.result'"), 'the last result is kept');
    }
}
