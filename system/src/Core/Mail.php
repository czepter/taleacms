<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Sending e-mails: either with the server's mail() function or through the site's own SMTP server ("Settings → Mail",
 * Settings → Mail).
 *
 * SMTP is more reliable - messages go out from a verified mailbox (SPF, DKIM) and do not end up in spam. The client is
 * deliberately small and has no libraries: STARTTLS or SSL, AUTH LOGIN/PLAIN login, one connection is reused while
 * processing the mail queue.
 */
final class Mail
{
    /** Text of the last error (for the test e-mail and System status). */
    public static string $error = '';

    /**
     * The SMTP server refused the message or a recipient for good (a 5xx reply after MAIL FROM, e.g. a spam filter's
     * "550 5.7.1"): another attempt would get the same answer, so it is not retried.
     */
    public static bool $refused = false;

    /** The code of the last SMTP reply (0 = none yet). */
    private static int $code = 0;

    /** @var resource|null open SMTP connection */
    private static $connection = null;

    /** How long until a failed send is retried (minutes); after the last attempt the message stays in the queue as failed. */
    private const array RETRY_DELAYS = [5, 30, 120, 720];

    /**
     * Sends the message right away. When that fails (SMTP outage), it stores it in the queue and retries later - so
     * a registration confirmation or a new password is not lost. Every message has a log entry ("Settings → Mail").
     *
     * @param string $recipient one address, or several separated by commas (they all see each other in To)
     * @param array<string, string> $headers extra headers (e.g. List-Unsubscribe); 'Bcc' = hidden recipients separated by commas,
     *        they get the message without anyone seeing them (the header never goes out with it)
     * @param bool $queueOnFailure false = a one-off message that is not retried on error (test e-mail)
     */
    public static function send(Settings $siteSettings, string $recipient, string $subject, string $text, string $html = '', array $headers = [], bool $queueOnFailure = true): bool
    {
        if ($html === '' && Language::isRtl(Language::code())) {
            $html = self::rightToLeft($text, Language::code()); // a plain message in Arabic reads from the right in every mail client
        }
        $ok = self::deliver($siteSettings, $recipient, $subject, $text, $html, $headers);
        $error = self::$error;
        $refused = self::$refused && $queueOnFailure; // a refused test e-mail is answered on the spot, not in the problem log
        $queueOnFailure = $queueOnFailure && !self::$refused;
        try {
            $id = $siteSettings->db()->insert('mail', [
                'recipient' => mb_substr($recipient, 0, 190), 'subject' => mb_substr($subject, 0, 255), 'created_at' => date('Y-m-d H:i:s'), 'attempts' => 1,
                'sent_at' => $ok ? date('Y-m-d H:i:s') : null, 'error' => mb_substr($error, 0, 255),
                'body' => $ok || !$queueOnFailure ? null : json_encode(['to' => $recipient, 'text' => $text, 'html' => $html, 'headers' => $headers], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'next_attempt_at' => $ok || !$queueOnFailure ? null : date('Y-m-d H:i:s', time() + self::RETRY_DELAYS[0] * 60),
            ]);
            if ($refused) {
                // refused for good: reported now, as the last failed attempt of a queued message is
                Events::record($siteSettings->db(), 'mail.failed', 'error', mb_substr(t('E-mail “%s” could not be sent: %s', $subject, $error), 0, 255), ['mail' => $id]);
            }
            if (random_int(1, 50) === 1) {
                $siteSettings->db()->run('DELETE FROM {mail} WHERE created_at < NOW() - INTERVAL 30 DAY');
            }
        } catch (\Throwable $e) {
            // the mail log must not break sending (e.g. before the migration runs, the table does not exist yet)
            ErrorReport::capture($e, 'warning', ['tags' => ['mail' => 'log']]);
        }
        self::$error = $error;

        return $ok;
    }

    /**
     * The HTML part of a plain message in a right-to-left language: the same text with dir="rtl", the lines kept and the
     * addresses as links. Mail clients guess the direction of plain text line by line - a line that starts with a Latin name
     * or a number would otherwise turn to the left.
     */
    public static function rightToLeft(string $text, string $language): string
    {
        $body = preg_replace_callback('~https?://(?:(?!&quot;|&#039;)[^\s<>"])+~', fn (array $m): string => '<a href="' . $m[0] . '" dir="ltr">' . $m[0] . '</a>', e($text)) ?? e($text);

        return '<!DOCTYPE html><html lang="' . e($language) . '" dir="rtl"><head><meta charset="utf-8"></head>'
            . '<body><div dir="rtl" style="font:15px/1.6 Tahoma, Arial, sans-serif; text-align: right; white-space: pre-wrap;">' . $body . '</div></body></html>';
    }

    /** A message was queued by later() in this request: afterResponse() sends it. */
    private static bool $pending = false;

    /**
     * Puts the message into the queue (tl_mail) and sends it right after the response (afterResponse in admin.php; the
     * background jobs retry it like any queued message). The answer to the request then takes as long whether a message
     * was sent or not – the password reset does not reveal by its timing which accounts exist (3.3.3, N59).
     */
    public static function later(Settings $siteSettings, string $recipient, string $subject, string $text): void
    {
        try {
            $siteSettings->db()->insert('mail', [
                'recipient' => mb_substr($recipient, 0, 190), 'subject' => mb_substr($subject, 0, 255), 'created_at' => date('Y-m-d H:i:s'), 'attempts' => 0,
                'sent_at' => null, 'error' => '', 'next_attempt_at' => date('Y-m-d H:i:s'),
                'body' => json_encode(['text' => $text, 'html' => '', 'headers' => []], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            self::$pending = true;
        } catch (\Throwable $e) {
            ErrorReport::capture($e, 'warning', ['tags' => ['mail' => 'queue']]);
            self::send($siteSettings, $recipient, $subject, $text); // without the queue table (before a migration) right away
        }
    }

    /** After the page was sent (admin.php): delivers what later() queued in this request; what fails waits for a retry. */
    public static function afterResponse(App $app): void
    {
        if (!self::$pending) {
            return;
        }
        self::$pending = false;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            self::processQueue($app->settings());
        } catch (\Throwable $e) {
            ErrorReport::capture($e, 'warning', ['tags' => ['mail' => 'after-response']]); // the background jobs try again
        }
    }

    /** Another attempt at messages waiting in the queue; called from background tasks. Returns the number sent. */
    public static function processQueue(Settings $siteSettings, int $maxCount = 10): int
    {
        $db = $siteSettings->db();
        $sent = 0;
        foreach ($db->all('SELECT * FROM {mail} WHERE sent_at IS NULL AND body IS NOT NULL AND next_attempt_at <= NOW() ORDER BY mail_id LIMIT ' . max(1, $maxCount)) as $z) {
            $body = json_decode((string) $z['body'], true) ?: [];
            $attempt = (int) $z['attempts'] + 1;
            // claim the message first: a concurrent request (the background jobs, Mail::afterResponse) then does not send it a second time
            if ($db->run('UPDATE {mail} SET attempts = ?, next_attempt_at = ? WHERE mail_id = ? AND attempts = ? AND sent_at IS NULL',
                [$attempt, date('Y-m-d H:i:s', time() + (self::RETRY_DELAYS[$attempt - 1] ?? 0) * 60), $z['mail_id'], $z['attempts']])->rowCount() === 0) {
                continue;
            }
            // 'to' keeps a list longer than the log column; messages queued before it have only the column
            if (self::deliver($siteSettings, (string) ($body['to'] ?? $z['recipient']), $z['subject'], (string) ($body['text'] ?? ''), (string) ($body['html'] ?? ''), (array) ($body['headers'] ?? []))) {
                $db->update('mail', ['sent_at' => date('Y-m-d H:i:s'), 'body' => null, 'next_attempt_at' => null, 'error' => ''], ['mail_id' => $z['mail_id']]);
                $sent++;
            } else {
                $end = self::$refused || !isset(self::RETRY_DELAYS[$attempt - 1]);
                $db->update('mail', ['error' => mb_substr(self::$error, 0, 255)] + ($end ? ['body' => null, 'next_attempt_at' => null] : []), ['mail_id' => $z['mail_id']]);
                if ($end) {
                    // the subject and the error, not the recipient (2.8, Core\Events)
                    Events::record($db, 'mail.failed', 'error', mb_substr(t('E-mail “%s” could not be sent: %s', (string) ($z['subject'] ?? ''), self::$error), 0, 255), ['mail' => (int) $z['mail_id']]);
                    // a message is lost: the error tracking hears of it once per reason – never the recipient or the subject
                    ErrorReport::message('E-mail given up after ' . $attempt . ' attempts: ' . self::$error, 'warning',
                        ['tags' => ['mail_transport' => $siteSettings->get('mail_mode')], 'fingerprint' => ['mail-failed', ErrorReport::scrub(self::$error)]]);
                }
            }
        }

        return $sent;
    }

    /**
     * Sends one message right away, without the mail log and the retry queue – newsletters keep their own queue and
     * their recipients must not end up in the log (Core\Mailing).
     *
     * @param array<string, string> $headers
     */
    public static function deliverNow(Settings $siteSettings, string $recipient, string $subject, string $text, string $html, array $headers = []): bool
    {
        return self::deliver($siteSettings, $recipient, $subject, $text, $html, $headers);
    }

    /** @param array<string, string> $headers */
    private static function deliver(Settings $siteSettings, string $recipient, string $subject, string $text, string $html = '', array $headers = []): bool
    {
        self::$error = '';
        self::$refused = false;
        if (Demo::active()) {
            self::$error = 'Sending e-mail is switched off in the public demo.';

            return false; // nobody uses the demo to send e-mail to strangers
        }
        $from = $siteSettings->get('mail_from') !== '' ? $siteSettings->get('mail_from') : $siteSettings->get('site_email');
        $to = self::addresses($recipient);
        $bcc = isset($headers['Bcc']) ? self::addresses($headers['Bcc']) : [];
        if ($from === '' || $to === [] || (isset($headers['Bcc']) && $bcc === [])) {
            self::$error = $from === '' ? 'The site e-mail (Settings → General) and the sender address are both empty.' : 'The recipient address is not valid.';

            return false;
        }
        // From-Name: the sender name of this message only (a form's thank-you e-mail); the address stays the site's, so it is not taken for spam
        $ownName = trim((string) preg_replace('/[\x00-\x1F\x7F"<>]+/u', ' ', (string) ($headers['From-Name'] ?? '')));
        unset($headers['Bcc'], $headers['From-Name']);
        $name = $ownName !== '' ? mb_substr($ownName, 0, 120) : ($siteSettings->get('mail_from_name') !== '' ? $siteSettings->get('mail_from_name') : $siteSettings->get('site_name'));
        $h = ['From' => self::mailbox($name, $from), 'MIME-Version' => '1.0'] + $headers;
        if ($siteSettings->get('mail_reply_to') !== '') {
            $h['Reply-To'] ??= self::mailbox($siteSettings->get('mail_reply_to_name'), $siteSettings->get('mail_reply_to')); // a form's own Reply-To (the visitor) wins
        }
        if ($html === '') {
            $h['Content-Type'] = 'text/plain; charset=utf-8';
            $h['Content-Transfer-Encoding'] = 'base64';
            $body = chunk_split(base64_encode($text));
        } else {
            $boundary = 'talea-' . bin2hex(random_bytes(8));
            $h['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
                . "--{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--{$boundary}--\r\n";
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $rows = [];
        foreach ($h as $name => $value) {
            $rows[] = $name . ': ' . str_replace(["\r", "\n"], '', $value); // headers must not be splittable by an injected line break
        }

        if ($siteSettings->get('mail_mode') === 'smtp' && $siteSettings->get('smtp_host') !== '') {
            try {
                // hidden recipients are only in the envelope (RCPT TO), never in the message
                self::smtp($siteSettings, $from, [...$to, ...$bcc], array_merge(['Date: ' . date('r'), 'To: ' . implode(', ', $to), 'Subject: ' . $encodedSubject,
                    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr((string) strrchr($from, '@'), 1) ?: 'localhost') . '>'], $rows), $body);

                return true;
            } catch (\RuntimeException $e) {
                self::$error = $e->getMessage();
                self::close();

                return false;
            }
        }
        if (!function_exists('mail')) {
            self::$error = 'The mail() function is disabled on the server – set up sending via SMTP.';

            return false;
        }
        if ($bcc !== []) {
            $rows[] = 'Bcc: ' . implode(', ', $bcc); // the server's sendmail delivers to it and removes the header
        }
        $ok = @mail(implode(', ', $to), $encodedSubject, $body, implode("\r\n", $rows));
        if (!$ok) {
            self::$error = 'The server refused to send the message (the mail() function failed).';
        }

        return $ok;
    }

    /** "Name <address>" with the name MIME-encoded; only the address when there is no name. */
    private static function mailbox(string $name, string $address): string
    {
        return $name === '' ? $address : '=?UTF-8?B?' . base64_encode($name) . "?= <{$address}>";
    }

    /**
     * The addresses of a recipient list separated by commas; empty when any of them is not valid (nothing goes out to half
     * a list, and a line break or a quote can never smuggle in another header).
     *
     * @return list<string>
     */
    public static function addresses(string $list): array
    {
        $valid = [];
        foreach (explode(',', $list) as $address) {
            $address = trim($address);
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\x00-\x20\x7F"<>,]/', $address)) {
                return [];
            }
            $valid[strtolower($address)] ??= $address;
        }

        return array_values($valid);
    }

    /**
     * @param list<string> $recipients
     * @param list<string> $headers
     */
    private static function smtp(Settings $siteSettings, string $from, array $recipients, array $headers, string $body): void
    {
        if (!is_resource(self::$connection)) {
            self::connect($siteSettings);
        } else {
            self::statement('RSET', [250]);
        }
        self::statement('MAIL FROM:<' . $from . '>', [250]);
        foreach ($recipients as $recipient) {
            self::refusable('RCPT TO:<' . $recipient . '>', [250, 251]);
        }
        self::statement('DATA', [354]);
        // a line starting with a dot gets the dot doubled, a lone dot ends the message
        $message = preg_replace('/^\./m', '..', implode("\r\n", $headers) . "\r\n\r\n" . $body) ?? '';
        self::refusable(rtrim($message, "\r\n") . "\r\n.", [250], t('the message'));
    }

    /**
     * A statement whose 5xx reply is final: an unknown mailbox, or a message the server's filter refused. The name is what
     * the error calls it (the message, not its first header).
     *
     * @param list<int> $expected
     */
    private static function refusable(string $statement, array $expected, ?string $name = null): void
    {
        try {
            self::statement($statement, $expected, false, $name);
        } catch (\RuntimeException $e) {
            self::$refused = self::$code >= 500 && self::$code < 600;

            throw $e;
        }
    }

    private static function connect(Settings $siteSettings): void
    {
        $host = $siteSettings->get('smtp_host');
        $encryption = $siteSettings->get('smtp_encryption');
        $port = $siteSettings->int('smtp_port') ?: ($encryption === 'ssl' ? 465 : 587);
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new \RuntimeException('The SMTP server address is not valid.');
        }
        $connection = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $number, $error, 10);
        if ($connection === false) {
            throw new \RuntimeException(t('Could not connect to the SMTP server %s. Check the address, port and encryption; some hosts block outgoing SMTP.', "{$host}:{$port}" . ($error !== '' ? " ({$error})" : '')));
        }
        stream_set_timeout($connection, 15);
        self::$connection = $connection;
        self::response([220]);
        $me = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost');
        $options = self::statement($me, [250]);
        if ($encryption === 'tls') {
            self::statement('STARTTLS', [220]);
            if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                throw new \RuntimeException('The encrypted connection (STARTTLS) could not be established – the server probably has an invalid certificate.');
            }
            $options = self::statement($me, [250]);
        }
        if ($siteSettings->get('smtp_user') !== '') {
            try {
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $options)) {
                    self::statement('AUTH PLAIN ' . base64_encode("\0" . $siteSettings->get('smtp_user') . "\0" . $siteSettings->get('smtp_password')), [235], true);
                } else {
                    self::statement('AUTH LOGIN', [334]);
                    self::statement(base64_encode($siteSettings->get('smtp_user')), [334], true);
                    self::statement(base64_encode($siteSettings->get('smtp_password')), [235], true);
                }
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(t('The SMTP server refused the sign-in – check the user name and password (Gmail and Seznam need an app password).') . ' ' . $e->getMessage());
            }
        }
        register_shutdown_function(self::close(...));
    }

    /** @param list<int> $expected */
    private static function statement(string $statement, array $expected, bool $secret = false, ?string $name = null): string
    {
        self::$code = 0;
        if (@fwrite(self::$connection, $statement . "\r\n") === false) {
            throw new \RuntimeException('The connection to the SMTP server was interrupted.');
        }

        return self::response($expected, $name ?? ($secret ? '(credentials)' : strtok($statement, "\r\n ")));
    }

    /** @param list<int> $expected */
    private static function response(array $expected, string $commandName = 'connection'): string
    {
        $response = '';
        do {
            $row = fgets(self::$connection, 1024);
            if ($row === false) {
                throw new \RuntimeException('The SMTP server did not respond in time.');
            }
            $response .= $row;
        } while (isset($row[3]) && $row[3] === '-');
        self::$code = (int) substr($response, 0, 3);
        if (!in_array(self::$code, $expected, true)) {
            throw new \RuntimeException(t('SMTP server reply to %s: %s', $commandName, mb_substr(trim($response), 0, 200)));
        }

        return $response;
    }

    public static function close(): void
    {
        if (is_resource(self::$connection)) {
            @fwrite(self::$connection, "QUIT\r\n");
            @fclose(self::$connection);
        }
        self::$connection = null;
    }
}
