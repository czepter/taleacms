<?php
/**
 * Talea – a fake SMTP server for tests: accepts every message and writes it to <dir>/<n>.eml (the envelope recipient on the
 * first line as "X-Rcpt-To:"). A recipient whose address contains "odmitnout" is refused (550), to test failed deliveries,
 * and so is a message that contains "REFUSE-THIS-MESSAGE" (550 5.7.1, like a spam filter).
 * No TLS and no login – set smtp_encryption to "none" and leave smtp_user empty.
 *   php tools/fake-smtp.php <port> <dir>
 */

declare(strict_types=1);

[$port, $dir] = [(int) ($argv[1] ?? 2525), $argv[2] ?? sys_get_temp_dir() . '/talea-smtp'];
@mkdir($dir, 0777, true);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error) ?: exit("fake-smtp: $error\n");
$count = count(glob($dir . '/*.eml') ?: []);

while ($client = @stream_socket_accept($server, -1)) {
    $say = fn (string $line) => fwrite($client, $line . "\r\n");
    $say('220 fake-smtp ready');
    $to = [];
    while (($line = fgets($client)) !== false) {
        $command = strtoupper(substr(trim($line), 0, 4));
        if ($command === 'EHLO' || $command === 'HELO') {
            $say('250-fake-smtp');
            $say('250 8BITMIME');
        } elseif ($command === 'MAIL' || $command === 'RSET' || $command === 'NOOP') {
            $to = $command === 'MAIL' || $command === 'RSET' ? [] : $to;
            $say('250 OK');
        } elseif ($command === 'RCPT') {
            $address = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            if (str_contains($address, 'odmitnout')) {
                $say('550 5.1.1 Mailbox unavailable');
            } else {
                $to[] = $address;
                $say('250 OK');
            }
        } elseif ($command === 'DATA') {
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($row = fgets($client)) !== false && rtrim($row, "\r\n") !== '.') {
                $data .= str_starts_with($row, '..') ? substr($row, 1) : $row;
            }
            if (str_contains(base64_decode(preg_replace('/^.*?\r\n\r\n/s', '', $data) ?? '') . $data, 'REFUSE-THIS-MESSAGE')) {
                $say('550 5.7.1 Refused by local policy');
            } else {
                file_put_contents(sprintf('%s/%04d.eml', $dir, ++$count), 'X-Rcpt-To: ' . implode(', ', $to) . "\r\n" . $data);
                $say('250 OK queued');
            }
        } elseif ($command === 'QUIT') {
            $say('221 Bye');
            break;
        } else {
            $say('502 Not implemented');
        }
    }
    fclose($client);
}
