<?php

declare(strict_types=1);

namespace Talea\Tests\Site\MediaMenuMail;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * The whole newsletter journey of a visitor, end to end over HTTP against the fake SMTP server: sign up with the element on a page,
 * the confirmation e-mail, confirming, a newsletter frozen and sent by cron, unsubscribing by the List-Unsubscribe header (one click,
 * RFC 8058) and by the link in the e-mail – while the site is in maintenance mode – and the next newsletter, which must skip them,
 * with the counts the administration and Claude see.
 */
#[Group('site')]
final class NewsletterJourneyTest extends SiteTestCase
{
    /** @var resource|null */
    private static $smtp = null;
    /** @var array<string, string> the e-mails the subscribers got, by address */
    private static array $mail = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$smtp !== null) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
            self::$smtp = null;
        }
        parent::tearDownAfterClass();
    }

    public function testSignUpWithTheElementAndConfirmByTheLinkFromTheEmail(): void
    {
        $site = $this->site();
        $port = $site->freePort();
        self::$smtp = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) $port, $site->workDir('smtp')], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port, $e, $m, 0.2) === false; $i++) {
            usleep(50_000);
        }
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.com', 'page_cache' => '1'] as $k => $v) {
            $site->setting($k, $v);
        }
        $site->exec('DELETE FROM tl_subscribers');
        $site->mcp('build_from_html', ['title' => 'Join us', 'html' => '<section><h1>Join us</h1><p>News by e-mail.</p></section>']);
        $page = $site->publicId('pages', (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'join-us'"));
        $site->mcp('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'content' => ['text' => 'Join us']], ['type' => 'newsletter_signup'],
        ]]]]]);
        $site->exec("UPDATE tl_pages SET visible = 1 WHERE slug = 'join-us'");
        $site->clearPageCache();

        foreach (['anna@example.com', 'ben@example.com'] as $email) {
            $this->signUp($email);
            $confirmation = $this->waitForMail($email, 'subscribe?confirm=');
            $this->assertSame(1, preg_match('#(/subscribe\?confirm=[a-f0-9]{32})#', $confirmation, $link), "the confirmation e-mail to $email carries the link");
            $visitor = $site->client('confirm-' . $email);
            $this->assertStringContainsString('Confirm my subscription', $visitor->get($link[1])->body, 'the link from the e-mail only offers the confirmation');
            $this->assertSame('0', (string) $site->value('SELECT status FROM tl_subscribers WHERE email = ?', [$email]), 'opening the link confirms nothing');
            $this->assertStringContainsString('Subscription confirmed', $visitor->post($link[1])->body, "$email confirms with the button");
        }
        $this->assertSame('2', (string) $site->value('SELECT COUNT(*) FROM tl_subscribers WHERE status = 1 AND confirmed_at IS NOT NULL'), 'both are confirmed subscribers');
    }

    #[Depends('testSignUpWithTheElementAndConfirmByTheLinkFromTheEmail')]
    public function testCronSendsTheFrozenNewsletterWithEachOnesOwnUnsubscribeLink(): void
    {
        $site = $this->site();
        $site->runTasks(); // cron runs: the site may send newsletters
        $id = $this->sendNewsletter('First letter');
        $site->runTasks();
        $this->assertSame('sent|2|2|0', $this->counts($id), 'cron sent the newsletter to both confirmed subscribers');

        foreach (['anna@example.com', 'ben@example.com'] as $email) {
            self::$mail[$email] = $this->waitForMail($email, 'First letter');
            $token = (string) $site->value('SELECT token FROM tl_subscribers WHERE email = ?', [$email]);
            $this->assertStringContainsString('List-Unsubscribe: <' . $site->base . '/subscribe?unsubscribe=' . $token . '>', self::$mail[$email], "$email gets the own one-click address");
        }
    }

    /**
     * Maintenance mode hides the site from visitors, but the Docker cron goes on sending newsletters, and withdrawing consent must work:
     * a mail client's one-click POST that gets a 503 tells the person "unsubscribed" while the site keeps mailing them.
     */
    #[Depends('testCronSendsTheFrozenNewsletterWithEachOnesOwnUnsubscribeLink')]
    public function testUnsubscribingByTheHeaderAndByTheLinkWorksInMaintenanceModeAndTheFrozenNewsletterSkipsThem(): void
    {
        $site = $this->site();
        $site->setting('maintenance', '1');
        $second = $this->sendNewsletter('Second letter');
        $this->cron();
        $this->assertSame('sent|2|2|0', $this->counts($second), 'the Docker cron sends a newsletter in maintenance mode too');
        foreach (['anna@example.com', 'ben@example.com'] as $email) {
            self::$mail[$email] = $this->waitForMail($email, 'Second letter');
        }
        $third = $this->sendNewsletter('Third letter');
        $this->assertSame('sending|2|0|0', $this->counts($third), 'the third newsletter is frozen with both subscribers');

        // Anna: the mail client's one-click unsubscribe (List-Unsubscribe-Post), no cookies, no page visit before
        $this->assertSame(1, preg_match('/^List-Unsubscribe: <([^>]+)>/m', self::$mail['anna@example.com'], $header), 'the header carries the address');
        $oneClick = $site->client('mail-client')->post(substr($header[1], strlen($site->base)), 'List-Unsubscribe=One-Click', ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(200, $oneClick->status, 'the one-click unsubscribe is answered in maintenance mode');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_subscribers WHERE email = 'anna@example.com'"), 'one click from the mail client unsubscribes in maintenance mode');

        // Ben: the link in the e-mail (text part), which shows a button, then the button
        $this->assertSame(1, preg_match('#^Unsubscribe: \S+(/subscribe\?unsubscribe=[a-f0-9]{32})$#m', self::$mail['ben@example.com'], $link), 'the text part carries the unsubscribe link');
        $ben = $site->client('ben');
        $this->assertStringContainsString('Do you really no longer want to receive news at ben@example.com?', $ben->get($link[1])->body, 'the unsubscribe link opens in maintenance mode');
        $this->assertStringContainsString('We have removed ben@example.com from the subscriber list.', $ben->post($link[1])->body, 'the button unsubscribes in maintenance mode');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_subscribers'), 'nobody is subscribed any more');
        $this->assertSame(503, $site->client('maintenance-visitor')->get('/join-us')->status, 'the rest of the site stays closed to visitors');
        $this->assertSame(503, $site->client('maintenance-visitor')->get('/subscribe?confirm=' . str_repeat('a', 32))->status, 'so is confirming a sign-up');

        $this->cron();
        $this->assertSame('sent|0|0|0', $this->counts($third), 'the frozen newsletter goes to nobody who left, and its counts add up');
        $this->assertSame([0, 0], [$this->mailCount('anna@example.com', 'Third letter'), $this->mailCount('ben@example.com', 'Third letter')], 'neither got the third letter');
        $site->setting('maintenance', '0');
    }

    #[Depends('testUnsubscribingByTheHeaderAndByTheLinkWorksInMaintenanceModeAndTheFrozenNewsletterSkipsThem')]
    public function testTheNextNewsletterHasNobodyToGoToAndTheCountsAreRight(): void
    {
        $site = $this->site();
        $site->runTasks();
        $draft = $site->mcpResult('draft_newsletter', ['subject' => 'Third letter', 'intro' => 'Hello again.', 'news_mode' => 'none']);
        $answer = $site->mcp('send_newsletter', ['id' => $draft['id']]);
        $this->assertStringContainsString('There are no confirmed subscribers yet.', (string) json_encode($answer), 'nobody left to send to');
        $all = $site->mcpResult('list_newsletters');
        $this->assertSame(0, $all['confirmed_subscribers'], 'Claude sees no confirmed subscriber');
        $list = $site->admin()->get('/admin.php?module=subscribers')->body;
        $this->assertSame([false, false], [str_contains($list, 'anna@example.com'), str_contains($list, 'Export confirmed (CSV)')], 'the administration lists nobody and offers no export');
    }

    private function signUp(string $email): void
    {
        $site = $this->site();
        $visitor = $site->client('sign-up-' . $email);
        $form = (string) preg_replace('#</form>.*#s', '', (string) strstr($visitor->get('/join-us')->body, 'class="tl-newsletter"'));
        $this->assertNotSame('', $form, 'the page shows the sign-up element');
        $fields = ['email' => $email];
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form, $hidden, PREG_SET_ORDER);
        foreach ($hidden as $h) {
            $fields[$h[1]] = html_entity_decode($h[2]);
        }
        $fields['website'] = ''; // the honeypot stays empty
        sleep(1); // the form refuses a submit faster than its minimum time (TALEA_ANTISPAM_MIN)
        $answer = $visitor->post('/subscribe', $fields);
        $this->assertStringEndsWith('/join-us?subscription=ok#' . ($fields['anchor'] ?? ''), $answer->redirect, "$email signs up");
    }

    private function sendNewsletter(string $subject): int
    {
        $site = $this->site();
        $draft = $site->mcpResult('draft_newsletter', ['subject' => $subject, 'intro' => 'Hello, here is our news.', 'news_mode' => 'none']);
        $sent = $site->mcpResult('send_newsletter', ['id' => $draft['id']]);
        $this->assertSame('sending', $sent['status'] ?? null, "$subject starts sending");

        return $site->rowId($draft['id']);
    }

    /** The Docker cron (system/docker.php cron): it runs the jobs whatever the site's maintenance mode. */
    private function cron(): void
    {
        $this->site()->cli(['system/docker.php', 'cron'], ['TALEA_BACKGROUND' => '0']);
    }

    private function counts(int $id): string
    {
        return (string) $this->site()->value("SELECT CONCAT(status, '|', recipients, '|', sent_count, '|', failed_count) FROM tl_newsletters WHERE id = ?", [$id]);
    }

    /** Captured messages to the address whose decoded text contains the needle. */
    private function mailCount(string $address, string $needle): int
    {
        $count = 0;
        foreach (glob($this->site()->workDir('smtp') . '/*.eml') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            if (str_contains($raw, "X-Rcpt-To: $address") && str_contains($this->eml($raw), $needle)) {
                $count++;
            }
        }

        return $count;
    }

    /** Waits for the message: the fake server writes its file a moment after it accepted it. */
    private function waitForMail(string $address, string $needle): string
    {
        for ($i = 0; $i < 50; $i++) {
            foreach (glob($this->site()->workDir('smtp') . '/*.eml') ?: [] as $file) {
                $raw = (string) file_get_contents($file);
                if (str_contains($raw, "X-Rcpt-To: $address") && str_contains($text = $this->eml($raw), $needle)) {
                    return $text;
                }
            }
            usleep(100_000);
        }
        $this->fail("No mail with \"$needle\" for $address arrived.");
    }

    /** Headers, the decoded subject and the decoded text and HTML parts of a captured message. */
    private function eml(string $raw): string
    {
        [$headers, $body] = explode("\r\n\r\n", $raw, 2) + ['', ''];
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
}
