<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\LinksConnectors;

use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The whistleblowing channel: encrypted reports, follow-up with a code, limits, readers, the daily job (was: section 81, 2.14). */
#[Group('site')]
final class WhistleblowingTest extends SiteTestCase
{
    use FakeServices;

    private const string MODULE = '/admin.php?module=whistleblowing';

    /** @var resource|null the fake SMTP server (tools/fake-smtp.php is a command line script, not a php -S router) */
    private static $smtp = null;
    private static string $mailDir = '';
    /** @var array<string, string> */
    private static array $state = [];

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$smtp)) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
            self::$smtp = null;
        }
        parent::tearDownAfterClass();
    }

    private function startSmtp(): void
    {
        $port = $this->site()->freePort();
        self::$mailDir = $this->site()->workDir('smtp-wb');
        self::$smtp = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) $port, self::$mailDir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2) === false; $i++) {
            usleep(50_000);
        }
        $site = $this->site();
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'zadne', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $name => $value) {
            $site->setting($name, $value);
        }
        $site->exec("UPDATE ka_uzivatele SET email = 'wb-reader@example.cz' WHERE user = 'admin'");
    }

    private function year(): string
    {
        return substr(trim($this->site()->php('echo date("Y");')), 0, 4);
    }

    private function adminId(): string
    {
        return (string) $this->site()->value("SELECT idu FROM ka_uzivatele WHERE user = 'admin'");
    }

    /** Number of lines of the page containing the text (the old grep -c). */
    private function lineCount(Response $response, string $needle): int
    {
        return count(array_filter(explode("\n", $response->body), static fn (string $line): bool => str_contains($line, $needle)));
    }

    /** A report to the public form; the signed time fields are those of the form page. @param list<string> $files */
    private function report(string $text, array $files = []): Response
    {
        $send = ['as_cas' => self::$state['time'], 'as_podpis' => self::$state['signature'], 'text' => $text, 'name' => '', 'contact' => ''];
        if ($files === []) {
            return $this->site()->client('reporter')->upload('/_report', $send, []);
        }
        return $this->site()->client('reporter')->upload('/_report', $send, ['files[]' => $files[0]]);
    }

    private function caseNumber(Response $response): string
    {
        return preg_match('/ka-oznameni-cislo">([0-9-]*)/', $response->body, $m) === 1 ? $m[1] : '';
    }

    private function follow(string $number, string $code, string $reply = ''): Response
    {
        return $this->site()->client('reporter')->post('/_report/follow', ['number' => $number, 'code' => $code] + ($reply !== '' ? ['reply' => $reply] : []));
    }

    /** The old gate_mail: the decoded subject and the base64 body of a single-part message. */
    private function mailBody(string $file): string
    {
        [$headers, $body] = explode("\r\n\r\n", (string) file_get_contents($file), 2) + [1 => ''];
        preg_match('/^Subject: (.*)$/m', $headers, $subject);

        return 'Subject-Decoded: ' . mb_decode_mimeheader(trim($subject[1] ?? '')) . "\n" . base64_decode($body);
    }

    private function mailTo(string $address): ?string
    {
        for ($i = 0; $i < 40; $i++) {
            $found = null;
            foreach (glob(self::$mailDir . '/*.eml') ?: [] as $file) {
                if (str_contains((string) file_get_contents($file), 'X-Rcpt-To: ' . $address)) {
                    $found = $file;
                }
            }
            if ($found !== null) {
                return $found;
            }
            usleep(100_000);
        }

        return null;
    }

    public function testTheChannelIsOffUntilTheFeatureIsSwitchedOnAndSetUp(): void
    {
        $site = $this->site();
        $this->startSmtp();
        $this->assertPage('/_report', 404, message: 'whistleblowing: off by default – the public address is a 404');
        $this->assertPage(self::MODULE, 403, message: '3.2 whistleblowing: a feature that a new installation starts without – no admin module');
        $features = $site->admin()->get('/admin.php?module=extensions')->body;
        $this->assertStringContainsString('value="whistleblowing"', $features, '3.2: Features offers Whistleblowing');
        $this->assertStringContainsString('value="bookings"', $features, '3.2: Features offers Bookings');

        $before = $site->settingValue('extensions');
        self::$state['extensions'] = $before;
        $this->adminPost('/admin.php?module=extensions&action=save', ['tab' => 'extensions', 'rozsireni' => array_merge(explode(',', $before), ['whistleblowing']),
            'claude_destructive' => (string) $site->value("SELECT COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'claude_destructive'), '1')")], '/admin.php?module=extensions');
        $this->assertSame($before . ',whistleblowing', $site->settingValue('extensions'), '3.2 whistleblowing: switched on under Features, the other features kept');
        $this->assertPage(self::MODULE, 200, 'name="readers', message: 'whistleblowing: the module tells the administrator the channel is off and offers the setup');

        $this->adminPost('/admin.php?module=whistleblowing&action=settings', ['enabled' => '1', 'readers' => [$this->adminId()], 'retention' => '24', 'intro' => 'Oznámení řeší compliance officer.'], self::MODULE);
        $this->assertSame('1|' . $this->adminId() . '|24', $site->value("SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_enabled'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_readers'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'whistleblowing_retention_months'))"), 'whistleblowing: the setup is saved – on, the reader, the retention');
    }

    #[Depends('testTheChannelIsOffUntilTheFeatureIsSwitchedOnAndSetUp')]
    public function testAnAnonymousReportGetsACaseNumberAndACodeAndIsStoredEncrypted(): void
    {
        $site = $this->site();
        // 3.3.3 (N53): a site with a CAPTCHA still loads none on the channel – the provider would learn who reports
        $site->exec("INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_provider','turnstile'),('captcha_site_key','test-site'),('captcha_secret','test-secret') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)");
        $form = $this->assertPage('/_report', 200, 'name="text"', message: 'whistleblowing: the public form with the introduction');
        $this->assertStringContainsString('compliance officer', $form->body, 'whistleblowing: the introduction');
        $this->assertStringContainsString('noindex', $form->body, 'whistleblowing: the page is not indexed');
        foreach (['googletagmanager', 'data-souhlas=', 'cookies-lista', '<script src="https://', 'challenges.cloudflare.com', 'cf-turnstile'] as $tracker) {
            $this->assertStringNotContainsString($tracker, $form->body, 'whistleblowing: no tracking code, consent bar, CAPTCHA or third-party script (' . $tracker . ')');
        }
        self::$state['time'] = $form->field('as_cas');
        self::$state['signature'] = $form->field('as_podpis');
        $cv = $site->workDir('files') . '/cv.pdf';
        file_put_contents($cv, "%PDF-1.4 test CV\n");
        sleep(4); // the form is signed with its time; a too fast submission is refused as a bot

        $response = $this->report('Vedoucí skladu falšuje evidenci docházky.', [$cv]);
        $number = $this->caseNumber($response);
        $code = preg_match('/ka-oznameni-kod">([A-Z0-9-]*)/', $response->body, $m) === 1 ? $m[1] : '';
        self::$state['number'] = $number;
        self::$state['code'] = $code;
        $this->assertSame($this->year() . '-0001', $number, '3.3.3 whistleblowing: an anonymous report got the first case number of the year (no CAPTCHA answer needed)');
        $this->assertSame(200, $response->status, 'whistleblowing: the report was accepted');
        $this->assertSame(20, strlen(str_replace('-', '', $code)), 'whistleblowing: and a code of twenty characters');
        $site->exec("DELETE FROM ka_nastaveni WHERE promenna LIKE 'captcha_%'");

        $this->assertSame('64|0|0|0|1|received|1', $site->value("SELECT CONCAT(LENGTH(code_hash), '|', code_hash LIKE ?, '|', text LIKE '%docházky%', '|', text LIKE '%Vedouc%', '|', contact IS NULL, '|', status, '|', attachments IS NOT NULL) FROM ka_whistleblowing_cases WHERE number = ?", ['%' . str_replace('-', '', $code) . '%', $number]),
            'whistleblowing: only a hash of the code is stored; no plaintext of the report, no contact (anonymous)');
        $this->assertCount(1, glob($site->path('storage/oznameni/' . $this->year()) . '/*') ?: [], 'whistleblowing: the attachment is stored outside the web root');
        $this->assertSame('1|1|0', $site->value("SELECT CONCAT(COUNT(*), '|', MAX(message LIKE ?), '|', MAX(message LIKE '%docházky%')) FROM ka_events WHERE type = 'whistleblowing.received'", ['%' . $number . '%']), 'whistleblowing: the event names the case only');

        $file = $this->mailTo('wb-reader@example.cz');
        $this->assertNotNull($file, 'whistleblowing: the reader got an e-mail');
        $mail = $this->mailBody((string) $file);
        $this->assertStringContainsString($number, $mail, 'whistleblowing: the reader\'s e-mail names the case number');
        $this->assertDoesNotMatchRegularExpression('/docházky|dochazky/', $mail, 'whistleblowing: the e-mail carries no content');
    }

    #[Depends('testAnAnonymousReportGetsACaseNumberAndACodeAndIsStoredEncrypted')]
    public function testTheReporterFollowsUpWithTheCodeAndIsLockedOutAfterWrongCodes(): void
    {
        $site = $this->site();
        $number = self::$state['number'];
        $code = self::$state['code'];
        $view = $this->follow($number, $code);
        $this->assertSame(200, $view->status, 'whistleblowing: the follow-up with the code answers');
        $this->assertStringContainsString('data-stav="received"', $view->body, 'whistleblowing: it shows the status');
        $this->assertStringContainsString($number, $view->body, 'whistleblowing: it shows the case number');
        $this->assertStringContainsString('name="reply"', $view->body, 'whistleblowing: and a reply box');

        $reply = $this->follow($number, strtolower(str_replace('-', '', $code)), 'Doplňuji: děje se to každé pondělí.');
        $this->assertStringContainsString('ka-oznameni-zprava--reporter', $reply->body, 'whistleblowing: the reporter\'s message is shown');
        $this->assertSame('1|reporter|0', $site->value("SELECT CONCAT(COUNT(*), '|', MAX(sender), '|', MAX(text LIKE '%pondělí%')) FROM ka_whistleblowing_messages"), 'whistleblowing: the reporter added information (the code typed in lower case without dashes); the message is encrypted');

        $wrong = $this->follow($number, 'ABCDE-FGHJK-MNPQR-STUVW');
        $this->assertSame(403, $wrong->status, 'whistleblowing: a wrong code is refused');
        $this->assertSame(0, substr_count($wrong->body, 'data-stav='), 'whistleblowing: and shows no case');
        for ($i = 1; $i <= 9; $i++) {
            $this->follow($number, 'WRONG' . $i);
        }
        $this->assertSame(429, $this->follow($number, $code)->status, 'whistleblowing: after ten wrong codes the address waits an hour, even with the right code');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE number = ?', [$number]), 'whistleblowing: the case is still there');

        // 3.3.2 (N35): a wrong code leaves only a short keyed hash of the address with a daily salt, never the old unkeyed sha256
        $this->assertSame('10|1|8|0', $site->value("SELECT CONCAT(COUNT(*), '|', MIN(ip_adresa LIKE 'wb:%'), '|', MAX(LENGTH(ip_adresa)), '|', SUM(ip_adresa = LEFT(SHA2('kaleta|127.0.0.1', 256), 40))) FROM ka_kontrola_ip WHERE typ = 'oznameni'"), '3.3.2 whistleblowing: a wrong code is recorded as a short keyed bucket, not as a hash of the address');
        $site->exec("UPDATE ka_kontrola_ip SET cas = NOW() - INTERVAL 25 HOUR WHERE typ = 'oznameni' LIMIT 3");
        $this->follow($number, 'WRONG10');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'oznameni' AND cas < NOW() - INTERVAL 1 DAY"), '3.3.2 whistleblowing: rows older than a day are forgotten on the next write');
        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni'"); // an hour has passed
    }

    #[Depends('testTheReporterFollowsUpWithTheCodeAndIsLockedOutAfterWrongCodes')]
    public function testTheDailyLimitTheFloodMarkAndTheStorageCap(): void
    {
        $site = $this->site();
        // 3.3.2 (N28): five reports a day from one address bucket, then a kind "try again later" that keeps the text
        for ($i = 2; $i <= 5; $i++) {
            $this->report('Report number ' . $i);
        }
        $sixth = $this->report('The sixth report today');
        $this->assertSame(429, $sixth->status, '3.3.2 whistleblowing: the sixth report of the day from one address waits (status)');
        $this->assertSame(1, $this->lineCount($sixth, 'Další oznámení teď nemůžeme přijmout'), '3.3.2 whistleblowing: kindly');
        $this->assertSame(1, $this->lineCount($sixth, 'The sixth report today'), '3.3.2 whistleblowing: with the text kept');
        $this->assertSame('5|5', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_whistleblowing_cases), '|', (SELECT COUNT(*) FROM ka_kontrola_ip WHERE typ = 'oznameni-den' AND TIME(cas) = '00:00:00' AND ip_adresa LIKE 'wb:%'))"), '3.3.2 whistleblowing: five cases; the sent rows carry the day only');

        // the site-wide hourly cap: twenty reports in the last hour from anywhere – stamped with the time the site itself wrote for the
        // last report (the site's time zone), not MySQL's NOW(), which runs in UTC on CI
        $last = (string) $site->value('SELECT MAX(created_at) FROM ka_whistleblowing_cases');
        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'");
        for ($i = 1; $i <= 15; $i++) {
            $site->exec("INSERT INTO ka_whistleblowing_cases (number, created_at, status, feedback_due, text, code_hash) VALUES (?, ?, 'received', ? + INTERVAL 3 MONTH, 'x', REPEAT('b', 64))", ['1999-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), $last, $last]);
        }
        $flood = $this->report('Over the hourly cap');
        $floodNumber = $this->caseNumber($flood);
        // 3.3.3 (N57): over the hourly cap of the channel a genuine reporter is no longer refused – the case is accepted and marked for the readers
        $this->assertSame(200, $flood->status, '3.3.3 whistleblowing: over the hourly cap of the channel the report is accepted');
        $this->assertSame(1, $this->lineCount($flood, 'ka-oznameni-kod'), '3.3.3 whistleblowing: and gets a code');
        $this->assertSame('1|1', $site->value('SELECT CONCAT(flood, \'|\', (SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE flood = 1)) FROM ka_whistleblowing_cases WHERE number = ?', [$floodNumber]), '3.3.3 whistleblowing: marked as received during a flood');
        $this->assertPage(self::MODULE, 200, 'přijato během náporu', message: '3.3.3 whistleblowing: the list marks the case received during a flood');

        $site->exec('DELETE FROM ka_whistleblowing_cases WHERE number = ?', [$floodNumber]);
        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'");
        $site->exec("UPDATE ka_whistleblowing_cases SET created_at = NOW() - INTERVAL 2 HOUR WHERE number LIKE '1999-%'");
        $calm = $this->report('Under the hourly cap again');
        $calmNumber = $this->caseNumber($calm);
        $this->assertSame('200|0', $calm->status . '|' . $site->value('SELECT flood FROM ka_whistleblowing_cases WHERE number = ?', [$calmNumber]), '3.3.3 whistleblowing: below the hourly cap a report carries no flood mark');
        $site->exec('DELETE FROM ka_whistleblowing_cases WHERE number = ?', [$calmNumber]);
        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'");

        // the storage cap for attachments: above it the report goes through only without new attachments
        $big = $site->path('storage/oznameni/' . $this->year() . '/full.bin');
        $handle = fopen($big, 'w');
        ftruncate($handle, 1100 * 1048576);
        fclose($handle);
        $withFile = $this->report('With a file over the storage cap', [$site->workDir('files') . '/cv.pdf']);
        $without = $this->report('Without a file over the storage cap');
        $noFileNumber = $this->caseNumber($withFile);
        // 3.3.3 (N57): the text goes through without the attachment, and the reporter is told so
        $this->assertSame(200, $withFile->status, '3.3.3 whistleblowing: over the attachment storage cap the report is accepted (with a file)');
        $this->assertSame(1, $this->lineCount($withFile, 'Přílohy teď nemůžeme uložit'), '3.3.3 whistleblowing: the reporter is told kindly');
        $this->assertSame(1, $this->lineCount($withFile, 'ka-oznameni-kod'), '3.3.3 whistleblowing: and gets a code');
        $this->assertSame('1', (string) $site->value('SELECT attachments IS NULL FROM ka_whistleblowing_cases WHERE number = ?', [$noFileNumber]), '3.3.3 whistleblowing: without the attachment');
        $this->assertSame(200, $without->status, '3.3.3 whistleblowing: a report without a file is accepted over the storage cap');
        $this->assertSame(1, $this->lineCount($without, 'ka-oznameni-kod'), '3.3.3 whistleblowing: with a code');
        unlink($big);
        $site->exec('DELETE FROM ka_whistleblowing_cases WHERE number <> ?', [self::$state['number']]);
        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'oznameni-den'");
    }

    #[Depends('testTheDailyLimitTheFloodMarkAndTheStorageCap')]
    public function testTheReaderOpensTheCaseAnswersAndAnotherAdministratorSeesOnlyTheList(): void
    {
        $site = $this->site();
        $number = self::$state['number'];
        $id = (int) $site->value('SELECT id FROM ka_whistleblowing_cases WHERE number = ?', [$number]);
        $detail = $this->assertPage(self::MODULE . '&action=detail&id=' . $id, 200, 'falšuje evidenci docházky', message: 'whistleblowing: the reader opens the case with the decrypted report and the reporter\'s message');
        $this->assertStringContainsString('pondělí', $detail->body, 'whistleblowing: the detail lists the message');
        $this->assertStringContainsString('action=attachment', $detail->body, 'whistleblowing: and the attachment');
        $download = $site->admin()->get(self::MODULE . '&action=attachment&id=' . $id . '&index=0');
        $this->assertSame(200, $download->status, 'whistleblowing: the reader downloads the attachment (status)');
        $this->assertSame('%PDF-1.4', substr($download->body, 0, 8), 'whistleblowing: the reader downloads the attachment (content)');

        $this->adminPost('/admin.php?module=whistleblowing&action=reply', ['id' => (string) $id, 'text' => 'Děkujeme, prošetřujeme.'], self::MODULE);
        $this->assertSame('acknowledged|1|1', $site->value("SELECT CONCAT(status, '|', acknowledged_at IS NOT NULL, '|', (SELECT COUNT(*) FROM ka_whistleblowing_messages WHERE sender = 'handler' AND text NOT LIKE '%prošetřujeme%')) FROM ka_whistleblowing_cases WHERE id = ?", [$id]), 'whistleblowing: the handler\'s first answer acknowledges the receipt');
        $view = $this->follow($number, self::$state['code']);
        $this->assertStringContainsString('data-stav="acknowledged"', $view->body, 'whistleblowing: the reporter sees the new status');
        $this->assertStringContainsString('prošetřujeme', $view->body, 'whistleblowing: and the handler\'s answer');

        // another administrator is not a reader: the list with numbers and dates, no detail
        $this->adminPost('/admin.php?module=users&action=save', ['idu' => '0', 'jmeno' => 'Druhy', 'user' => 'druhy-spravce', 'password' => $site->password, 'admin' => '2'], self::MODULE);
        $second = $site->client('druhy');
        $site->signIn($second, 'druhy-spravce');
        $list = $second->get(self::MODULE);
        $other = $second->get(self::MODULE . '&action=detail&id=' . $id);
        $this->assertSame(200, $list->status, 'whistleblowing: another administrator sees the list');
        $this->assertGreaterThanOrEqual(1, $this->lineCount($list, $number), 'whistleblowing: with the case number');
        $this->assertSame(0, $this->lineCount($list, 'action=detail&amp;id=' . $id), 'whistleblowing: without a link');
        $this->assertSame(403, $other->status, 'whistleblowing: and cannot open the case');
        $this->assertSame(0, $this->lineCount($other, 'docházky'), 'whistleblowing: nor read it');

        $tools = json_encode($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        $this->assertStringNotContainsString('whistleblowing', $tools, 'MCP: tools/list has no whistleblowing tool');
        $this->assertSame(true, $site->mcpResult('site_info')['whistleblowing'] ?? null, 'MCP: site_info says only that the channel is on');
    }

    #[Depends('testTheReaderOpensTheCaseAnswersAndAnotherAdministratorSeesOnlyTheList')]
    public function testTheDailyJobRemindsAndPurgesAndTheFeatureOffClosesTheChannel(): void
    {
        $site = $this->site();
        $number = self::$state['number'];
        $id = (int) $site->value('SELECT id FROM ka_whistleblowing_cases WHERE number = ?', [$number]);
        // the daily job: a reminder of an overdue acknowledgement, a closed case past the retention deleted
        $site->exec("UPDATE ka_whistleblowing_cases SET created_at = NOW() - INTERVAL 8 DAY, acknowledged_at = NULL, status = 'received' WHERE id = ?", [$id]);
        $site->exec("INSERT INTO ka_whistleblowing_cases (number, created_at, status, acknowledged_at, feedback_due, closed_at, text, contact, attachments, code_hash) VALUES ('2023-0001', NOW() - INTERVAL 30 MONTH, 'closed', NOW() - INTERVAL 30 MONTH, NOW() - INTERVAL 27 MONTH, NOW() - INTERVAL 25 MONTH, 'x', NULL, NULL, REPEAT('a', 64))");
        $this->runJob('whistleblowing');
        $this->assertSame('1|0|1', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'whistleblowing.due' AND data LIKE '%\"deadline\":\"acknowledgement\"%' AND message LIKE ?), '|', (SELECT COUNT(*) FROM ka_whistleblowing_cases WHERE number = '2023-0001'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'whistleblowing.purged'))", ['%' . $number . '%']),
            'whistleblowing: the daily job records the overdue acknowledgement and deletes the closed case past the retention');
        $this->assertPage(self::MODULE, 200, 'po lhůtě', message: 'whistleblowing: the list highlights the overdue acknowledgement');

        // 3.2: the feature switched off closes the channel even with its own switch on; the cases stay
        $site->exec("UPDATE ka_nastaveni SET hodnota = ? WHERE promenna = 'extensions'", [self::$state['extensions']]);
        $this->assertPage('/_report', 404, message: '3.2 whistleblowing off: the public address is a 404 although the channel is set up');
        $this->assertPage(self::MODULE, 403, message: '3.2 whistleblowing off: the admin module is gone');
        $this->assertSame('off|1', ($site->mcpResult('site_info')['whistleblowing'] ?? null) === true ? 'on|1' : 'off|' . (int) ((int) $site->value('SELECT COUNT(*) FROM ka_whistleblowing_cases') > 0), '3.2 whistleblowing off: MCP site_info no longer says the channel is on; the cases stay');
        $site->setting('mail_mode', 'mail');
        $site->setting('smtp_host', '');
    }
}
