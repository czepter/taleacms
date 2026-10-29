<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Mail;
use Talea\Tests\Support\FakeSettings;

/** Several recipients and a hidden copy (Bcc) in Core\Mail (upstream 3.15.2, #92). */
final class MailRecipientsTest extends TestCase
{
    public function testAddresses(): void
    {
        $this->assertSame(['a@example.com', 'B@example.com'], Mail::addresses('a@example.com, B@example.com,b@example.com'), 'a list separated by commas, duplicates ignoring case');
        $this->assertSame([], Mail::addresses("a@example.com, b@example.com\r\nBcc: c@example.com"), 'one invalid address refuses the whole list (no injected header)');
        $this->assertSame([], Mail::addresses(''), 'empty');
    }

    /** Over SMTP the visible recipients see each other in To, the hidden one is only in the envelope (RCPT TO). */
    public function testBccOnlyInTheEnvelope(): void
    {
        $dir = sys_get_temp_dir() . '/talea-mail-' . bin2hex(random_bytes(4));
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
        fclose($probe);
        $server = proc_open([PHP_BINARY, TALEA_ROOT . '/tools/fake-smtp.php', (string) $port, $dir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port, $e, $m, 0.2) === false; $i++) {
                usleep(50_000);
            }
            $settings = FakeSettings::site(['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'none', 'smtp_user' => '',
                'mail_from' => 'web@example.com', 'mail_from_name' => 'Jana Nováková', 'site_email' => 'web@example.com', // check-english: allow
                'mail_reply_to' => 'office@example.com', 'mail_reply_to_name' => 'Office']);
            $this->assertTrue(Mail::deliverNow($settings, 'first@example.com, second@example.com', 'Enquiry', 'text', '', ['Reply-To' => 'visitor@example.com', 'Bcc' => 'hidden@example.com']), Mail::$error);
            $this->assertTrue(Mail::deliverNow($settings, 'first@example.com', 'Notice', 'text', ''), Mail::$error);
            $this->assertFalse(Mail::deliverNow($settings, 'first@example.com', 'Spam', 'REFUSE-THIS-MESSAGE', ''));
            $this->assertTrue(Mail::$refused, 'a 5xx reply to the message is final: it is not retried');
            $this->assertStringContainsString('SMTP server reply to the message: 550 5.7.1', Mail::$error, 'the error names the message, not its first header');
            $this->assertFalse(Mail::deliverNow($settings, 'odmitnout@example.com', 'Unknown', 'text', '')); // check-english: allow
            $this->assertTrue(Mail::$refused, 'an unknown mailbox is final too');
            $this->assertTrue(Mail::deliverNow($settings, 'first@example.com', 'Again', 'text', ''), Mail::$error);
            $this->assertFalse(Mail::$refused);
            Mail::close();
            $files = [];
            for ($i = 0; $i < 50 && count($files) < 3; $i++) {
                $files = glob($dir . '/*.eml') ?: [];
                usleep(count($files) < 3 ? 100_000 : 0);
            }
            [$message, $notice] = array_map(fn ($file) => (string) file_get_contents($file), $files);
            $this->assertStringContainsString("\r\nFrom: =?UTF-8?B?" . base64_encode('Jana Nováková') . "?= <web@example.com>\r\n", $message, 'the sender name'); // check-english: allow
            $this->assertStringContainsString("\r\nReply-To: visitor@example.com\r\n", $message, 'the visitor of a form wins over the reply address of the settings');
            $this->assertStringContainsString("\r\nReply-To: =?UTF-8?B?" . base64_encode('Office') . "?= <office@example.com>\r\n", $notice, 'the reply address with its name');
            $this->assertStringContainsString("X-Rcpt-To: first@example.com, second@example.com, hidden@example.com\r\n", $message, 'all three in the envelope');
            $this->assertStringContainsString("\r\nTo: first@example.com, second@example.com\r\n", $message, 'the visible recipients in To');
            $this->assertSame(1, substr_count($message, 'hidden@example.com'), 'the hidden recipient nowhere in the message');
            $this->assertStringNotContainsStringIgnoringCase("\r\nBcc:", $message);
        } finally {
            proc_terminate($server);
            proc_close($server);
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}
