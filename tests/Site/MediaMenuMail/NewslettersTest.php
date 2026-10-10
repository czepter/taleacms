<?php

declare(strict_types=1);

namespace Talea\Tests\Site\MediaMenuMail;

use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Newsletters against the fake SMTP server tools/fake-smtp.php, then the health page and the password-reset mail
 * (was: section 35 of tools/test.sh; it also needed a second published news item from the earlier sections).
 */
#[Group('site')]
final class NewslettersTest extends SiteTestCase
{
    use Helpers;

    /** @var resource|null */
    private static $smtp = null;
    private static int $smtpPort = 0;
    /** @var array<string, mixed> state shared by the ordered tests: anna, petr, nl, nl2 */
    private static array $s = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$smtp !== null) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
            self::$smtp = null;
        }
        parent::tearDownAfterClass();
    }

    private function smtpDir(): string
    {
        return $this->site()->workDir('smtp');
    }

    /** @param array<string, mixed> $fields */
    private function newsletterAction(string $action, array $fields = []): void
    {
        if (isset($fields['id']) && is_int($fields['id']) && $fields['id'] > 0) {
            $fields['id'] = $this->site()->publicId('newsletters', $fields['id']); // the administration addresses a newsletter by its public id
        }
        $this->adminPost('/admin.php?module=newsletters&action=' . $action, $fields, '/admin.php?module=newsletters');
    }

    private function nl(): int
    {
        return (int) self::$s['nl'];
    }

    private function statusOf(int $id): string
    {
        return (string) $this->site()->value('SELECT status FROM tl_newsletters WHERE id = ?', [$id]);
    }

    /** The newest captured message for a recipient: its path, or '' (the old mail_to). */
    private function mailTo(string $address): string
    {
        $found = '';
        foreach (glob($this->smtpDir() . '/*.eml') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "X-Rcpt-To: $address")) {
                $found = $file;
            }
        }

        return $found;
    }

    /** Waits for the message: the fake server writes its file a moment after it accepted it. */
    private function waitForMail(string $address, string $needle = ''): string
    {
        for ($i = 0; $i < 50; $i++) {
            $file = $this->mailTo($address);
            if ($file !== '' && ($needle === '' || str_contains($this->eml($file), $needle))) {
                return $this->eml($file);
            }
            usleep(100_000);
        }
        $this->fail("No mail for $address arrived.");
    }

    /** The old eml(): headers, the decoded subject and the decoded text and HTML parts of a captured message. */
    private function eml(string $file): string
    {
        [$headers, $body] = explode("\r\n\r\n", (string) file_get_contents($file), 2) + ['', ''];
        $out = $headers . "\n";
        preg_match('/^Subject: (.*)$/m', $headers, $subject);
        $out .= 'Subject-Decoded: ' . mb_decode_mimeheader(trim($subject[1] ?? '')) . "\n";
        preg_match_all('/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $body, $parts);
        foreach ($parts[1] as $part) {
            $out .= base64_decode($part) . "\n";
        }
        if ($parts[1] === [] && preg_match('/^Content-Transfer-Encoding: base64/mi', $headers) === 1) {
            $out .= base64_decode((string) preg_replace('/\s+/', '', $body)) . "\n";
        }

        return $out;
    }

    /** grep -c: lines (of the text) that contain / start with the needle. */
    private function lines(string $text, string $needle, bool $atStart = false): int
    {
        return count(array_filter(explode("\n", $text), static fn (string $l): bool => $atStart ? str_starts_with($l, $needle) : str_contains($l, $needle)));
    }

    public function testTheListAndTheNewDraftFormOfAnEmptySite(): void
    {
        $site = $this->site();
        self::$smtpPort = $site->freePort();
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) self::$smtpPort, $this->smtpDir()], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::$smtp = $process;
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', self::$smtpPort, $e, $m, 0.2) === false; $i++) {
            usleep(50_000);
        }
        self::$s['anna'] = bin2hex(random_bytes(16));
        self::$s['petr'] = bin2hex(random_bytes(16));
        $site->exec("UPDATE tl_users SET email = 'admin@example.cz' WHERE username = 'admin'");
        $site->exec('DELETE FROM tl_subscribers');
        $site->exec("INSERT INTO tl_subscribers (email, status, token, created_at, confirmed_at) VALUES
            ('anna@example.cz', 1, ?, NOW(), NOW()), ('petr@example.cz', 1, ?, NOW(), NOW()), ('odmitnout@example.cz', 1, ?, NOW(), NOW()), ('ceka@example.cz', 0, ?, NOW(), NULL)",
            [self::$s['anna'], self::$s['petr'], bin2hex(random_bytes(16)), bin2hex(random_bytes(16))]);
        $site->setting('mail_mode', 'mail');
        $site->setting('tasks_last_run', '0');
        // the earlier sections had published more than one news item; the newsletter lists the latest two
        $category = (string) $site->value("SELECT name FROM tl_categories WHERE language = '' ORDER BY category_id LIMIT 1");
        $created = $this->mcpText('create_news', ['title' => 'Second news item for the newsletter', 'category' => $category, 'publish' => true]);
        $this->assertSame('2', (string) $site->value("SELECT COUNT(*) FROM tl_news WHERE visible = 1 AND deleted_at IS NULL"), 'two published news items exist: ' . $created);

        $this->assertPage('/admin.php?module=newsletters', 200, 'Write a newsletter', message: 'newsletters: empty list');
        $this->assertPage('/admin.php?module=newsletters&action=new', 200, 'name="subject"', message: 'newsletters: new draft form');
    }

    #[Depends('testTheListAndTheNewDraftFormOfAnEmptySite')]
    public function testDraftPreviewAndNoSendingWithoutSmtpOrCron(): void
    {
        $site = $this->site();
        $this->newsletterAction('save', ['id' => 0, 'subject' => 'Spring news', 'preheader' => 'What is new', 'intro' => "Hello,\n\nwe are sending news. More at https://example.cz/akce",
            'news_mode' => 'latest', 'news_count' => 2, 'button_label' => 'All news', 'button_url' => '/news']);
        self::$s['nl'] = (int) $site->value('SELECT id FROM tl_newsletters ORDER BY id DESC LIMIT 1');
        $nl = $this->nl();
        $this->assertSame('draft|Spring news|2', $site->value("SELECT CONCAT(status, '|', subject, '|', news_count) FROM tl_newsletters WHERE id = ?", [$nl]), 'newsletter draft saved');

        $preview = $this->assertPage("/admin.php?module=newsletters&action=preview&id=" . $this->site()->publicId('newsletters', $nl), 200, 'utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=spring-news', message: 'newsletter: e-mail preview');
        $text = $preview->body;
        $this->assertSame([2, 1, 1, 1], [$this->lines($text, 'Read more'), $this->lines($text, 'href="https://example.cz/akce"'), $this->lines($text, 'All news'), $this->lines($text, 'Unsubscribe')], 'preview: 2 news items, linked address, button and unsubscribe');

        $this->newsletterAction('send', ['id' => $nl, 'when' => 'now']);
        $this->assertSame('draft', $this->statusOf($nl), 'no sending without an SMTP server');

        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) self::$smtpPort, 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $k => $v) {
            $site->setting($k, $v);
        }
        $this->newsletterAction('send', ['id' => $nl, 'when' => 'now']);
        $this->assertSame('draft', $this->statusOf($nl), 'no sending while cron does not run');
        $this->assertPage("/admin.php?module=newsletters&action=edit&id=" . $this->site()->publicId('newsletters', $nl), 200, 'Cron has not called the tasks address in the last 30 minutes', message: 'newsletter form tells why it cannot send');
    }

    #[Depends('testDraftPreviewAndNoSendingWithoutSmtpOrCron')]
    public function testTestMailAndSendingByCron(): void
    {
        $site = $this->site();
        $nl = $this->nl();
        $site->runTasks();
        $this->newsletterAction('test', ['id' => $nl]);
        $admin = $this->waitForMail('admin@example.cz');
        $this->assertSame(1, preg_match_all('/^Subject-Decoded: \[Test\] Spring news$/m', $admin), 'test e-mail to the signed-in user');

        $this->newsletterAction('send', ['id' => $nl, 'when' => 'now']);
        $this->assertSame('sending|3|1', $site->value("SELECT CONCAT(status, '|', recipients, '|', html LIKE '%{{unsubscribe}}%') FROM tl_newsletters WHERE id = ?", [$nl]), 'sending started for confirmed subscribers only');

        $site->runTasks();
        $this->assertSame('sending|2|0|1', $site->value("SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', (SELECT COUNT(*) FROM tl_newsletter_queue WHERE newsletter_id = ? AND next_attempt > NOW())) FROM tl_newsletters WHERE id = ?", [$nl, $nl]), 'cron sends a batch: 2 delivered, the refused one waits for a retry');

        $anna = $this->waitForMail('anna@example.cz');
        $own = self::$s['anna'];
        $this->assertSame([1, 1, 3, 0], [
            $this->lines($anna, 'List-Unsubscribe: <' . $site->base . '/subscribe?unsubscribe=' . $own . '>', true),
            $this->lines($anna, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', true),
            $this->lines($anna, 'unsubscribe=' . $own),
            $this->lines($anna, self::$s['petr']),
        ], 'subscriber e-mail: one-click unsubscribe with the own link, no one else\'s');
        $this->assertSame([1, 1, 1], [
            preg_match_all('/^Subject-Decoded: Spring news$/m', $anna), $this->lines($anna, 'All news: http', true), $this->lines($anna, '<h1 '),
        ], 'subscriber e-mail: subject, text part and HTML part');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_mail WHERE recipient IN ('anna@example.cz', 'petr@example.cz')"), 'newsletter recipients are not in the mail log');
    }

    #[Depends('testTestMailAndSendingByCron')]
    public function testRefusedAddressIsGivenUpAndTheSentNewsletterIsReadOnly(): void
    {
        $site = $this->site();
        $nl = $this->nl();
        for ($i = 0; $i < 2; $i++) {
            $site->exec('UPDATE tl_newsletter_queue SET next_attempt = NOW() WHERE next_attempt IS NOT NULL');
            $site->runTasks();
        }
        $this->assertSame('sent|2|1|1', $site->value("SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', finished_at IS NOT NULL) FROM tl_newsletters WHERE id = ?", [$nl]), 'a refused address is given up after three attempts, the newsletter is sent');
        $this->assertPage('/admin.php?module=newsletters', 200, 'Sent', message: 'newsletters: list with counts');
        $sent = $this->assertPage("/admin.php?module=newsletters&action=edit&id=" . $this->site()->publicId('newsletters', $nl), 200, 'Recipients', message: 'a sent newsletter is read-only');
        $this->assertStringNotContainsString('name="subject"', $sent->body, 'a sent newsletter has no form');
    }

    #[Depends('testRefusedAddressIsGivenUpAndTheSentNewsletterIsReadOnly')]
    public function testOneClickUnsubscribeAndMcpTools(): void
    {
        $site = $this->site();
        $nl = $this->nl();
        $site->client()->post('/subscribe?unsubscribe=' . self::$s['anna'], 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_subscribers WHERE email = 'anna@example.cz'"), 'one-click unsubscribe from the mail client (RFC 8058)');

        $list = $site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        $this->assertStringContainsString('"name":"draft_newsletter"', json_encode($list), 'MCP: newsletter tools listed');

        $site->exec("DELETE FROM tl_subscribers WHERE email LIKE 'odmitnout%'");
        $draft = $site->mcpResult('draft_newsletter', ['subject' => 'News via Claude', 'intro' => "Hi,\n\na short message.", 'news_mode' => 'none', 'button_label' => 'Contact', 'button_url' => '/contact']);
        $nl2 = $site->rowId($draft['id']);
        $this->assertSame([ 'draft', 1], [$draft['status'], $this->lines((string) $draft['text'], 'Contact: http://127.0.0.1', true)], 'MCP: draft_newsletter returns the text version');

        $test = $site->mcpResult('send_test_newsletter', ['id' => $site->publicId('newsletters', $nl2)]);
        $this->assertSame('admin@example.cz', $test['sent_to'], 'MCP: test goes to the connected user');

        $scheduled = $site->mcpResult('send_newsletter', ['id' => $site->publicId('newsletters', $nl2), 'at' => '2099-01-01 08:00']);
        $this->assertSame(['scheduled', '2099-01-01 08:00'], [$scheduled['status'], $scheduled['scheduled_at']], 'MCP: send_newsletter schedules');

        $site->exec('UPDATE tl_newsletters SET scheduled_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$nl2]);
        $site->runTasks();
        $this->assertSame('sent|1|1', $site->value("SELECT CONCAT(status, '|', recipients, '|', sent_count) FROM tl_newsletters WHERE id = ?", [$nl2]), 'a due scheduled newsletter goes out on the next cron call');

        $all = $site->mcpResult('list_newsletters');
        $this->assertSame([1, null, 'sent'], [$all['confirmed_subscribers'], $all['sending_problem'] ?? null, $all['newsletters'][0]['status'] ?? null], 'MCP: list_newsletters with subscribers and no sending problem');

        $site->mcp('delete_newsletter', ['id' => $site->publicId('newsletters', $nl2)]);
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_newsletters WHERE id = ?', [$nl2]), 'MCP: delete_newsletter');

        $site->exec('UPDATE tl_newsletters SET finished_at = NOW() - INTERVAL 2 DAY WHERE id = ?', [$nl]);
        $site->runTasks();
        $this->assertSame('0|2', $site->value('SELECT COUNT(*) FROM tl_newsletter_queue WHERE newsletter_id = ?', [$nl]) . '|' . $site->value('SELECT sent_count FROM tl_newsletters WHERE id = ?', [$nl]), 'recipients are kept only a day after sending');
    }

    #[Depends('testOneClickUnsubscribeAndMcpTools')]
    public function testHealthPage(): void
    {
        $site = $this->site();
        // a leftover custom layout folder (an earlier section left one there)
        mkdir($site->path('layout/custom'), 0775, true);
        file_put_contents($site->path('layout/custom/base.php'), '<?php echo "CUSTOM TEMPLATE";');

        $status = $this->assertPage('/admin.php?module=status', 200, 'Cron', message: 'health: cron check');
        $this->assertStringContainsString('Domain and mail', $status->body, 'health: domain and mail watch group');
        $this->assertStringContainsString('action=domain_check', $status->body, 'health: Check now button');
        $this->assertStringContainsString('runs on a local address', $status->body, 'health: nothing checked on a local address');

        $this->adminPost('/admin.php?module=settings&action=domain_check', [], '/admin.php?module=settings');
        $after = $this->assertPage('/admin.php?module=status', 200, 'Last checked', message: 'health: Check now stores the result and reports the local address');
        $this->assertSame('true', $site->value("SELECT JSON_EXTRACT(value, '$.local') FROM tl_settings WHERE name = 'domain_watch'"), 'health: the check result is cached in the domain_watch setting');
        $this->assertStringContainsString('custom in the layout/ folder', $after->body, 'health: a leftover custom layout is reported');
    }

    #[Depends('testHealthPage')]
    public function testPasswordResetLinkGoesThroughTheQueueAndAnUnknownNameLeavesNoTrace(): void
    {
        $site = $this->site();
        // 3.3.3 (N59): the reset link is queued and sent right after the response – it still arrives at once
        $site->exec('DELETE FROM tl_mail');
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'reset'");
        foreach (glob($this->smtpDir() . '/*.eml') ?: [] as $file) {
            unlink($file);
        }
        $known = $this->resetRequest($site->client('reset-known'), 'admin');
        $mail = $this->waitForMail('admin@example.cz', 'action=password&token=');

        $this->assertSame('200|1|1|1|1|1', $known->status . '|' . $site->value("SELECT CONCAT(COUNT(*), '|', SUM(sent_at IS NOT NULL), '|', SUM(body IS NULL), '|', MIN(attempts)) FROM tl_mail WHERE recipient = 'admin@example.cz'") . '|' . preg_match_all('/^.*action=password&token=[a-f0-9]{64}.*$/m', $mail), '3.3.3: the reset link went through the queue and was delivered by the time the answer was complete');

        $unknown = $this->resetRequest($site->client('reset-unknown'), 'nikdo-takovy');
        $this->assertSame('200|same|1', $unknown->status . '|' . ($unknown->text() === $known->text() ? 'same' : 'different') . '|' . $site->value('SELECT COUNT(*) FROM tl_mail'), '3.3.3: an unknown name gets the same page and queues nothing');
    }

    private function resetRequest(Http $client, string $name): \Talea\Tests\Site\Support\Response
    {
        $csrf = $client->get('/admin.php?action=password')->csrf();

        return $client->post('/admin.php?action=password', ['_csrf' => $csrf, 'user_id' => $name]);
    }
}
