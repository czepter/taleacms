<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FleetTest extends TestCase
{
    /** 2.9: fleet console – keys, pairing key, attention */
    public function testConsole(): void
    {
        $fleetPair = sodium_crypto_sign_keypair();
        $fleetPub = base64_encode(sodium_crypto_sign_publickey($fleetPair));
        $fleetSig = base64_encode(sodium_crypto_sign_detached('{"a":1}', sodium_crypto_sign_secretkey($fleetPair)));
        $this->assertSame([true, false, false, false, true, false, 8], [\Talea\Fleet\Keys::verify('{"a":1}', $fleetSig, $fleetPub), \Talea\Fleet\Keys::verify('{"a":2}', $fleetSig, $fleetPub),
            \Talea\Fleet\Keys::verify('{"a":1}', $fleetSig, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))), \Talea\Fleet\Keys::verify('{"a":1}', 'junk', $fleetPub),
            \Talea\Fleet\Keys::isPublicKey($fleetPub), \Talea\Fleet\Keys::isPublicKey('abc'), strlen(\Talea\Fleet\Keys::fingerprint($fleetPub))], '2.9: Fleet\Keys – a signature fits only its message and key');
        $this->assertSame([true, false, false, false, false, false, false], array_map(\Talea\Fleet\Http::allowedUrl(...),
            ['https://console.example.com', 'http://console.example.com', 'http://127.0.0.1:8312', 'http://web.test', 'ftp://example.com', 'https://user:pw@example.com', 'javascript:alert(1)']), '2.9 / 3.3.2: Fleet\Http – https only; plain http (also to local addresses) just in the automated tests (TALEA_FLEET_LOCAL)');
        $this->assertSame([[null, null, null, null, null, ['93.184.216.34', 443, '93.184.216.34', 'https://93.184.216.34/x']], [0, 0], true], [
            array_map(fn (string $url): ?array => \Talea\Fleet\Http::pin($url), ['https://127.0.0.1:8443/', 'https://10.0.0.5/admin', 'https://[::1]/', 'https://169.254.169.254/', 'https://[64:ff9b::a00:1]/', 'https://93.184.216.34/x']),
            array_column(\Talea\Fleet\Http::probe(['http://10.0.0.5:8080/admin', 'gopher://example.com/']), 'status'), \Talea\Fleet\Http::allowedUrl('http://example.com/', true)], '3.3.2: Fleet\Http pins public addresses only – loopback, private, link-local and NAT64 are refused; the uptime check of a refused address is 0 without a request');
        $fleetKey = \Talea\Fleet\Link::makeKey('https://console.example.com/', str_repeat('ab', 16), $fleetPub, 'Agency console');
        $this->assertSame([['url' => 'https://console.example.com', 'code' => str_repeat('ab', 16), 'key' => $fleetPub, 'name' => 'Agency console'], null, null, null], [
            \Talea\Fleet\Link::parseKey(" \n" . chunk_split($fleetKey, 40, "\n")), \Talea\Fleet\Link::parseKey('talea-console:junk'), \Talea\Fleet\Link::parseKey(str_repeat('ab', 16)),
            \Talea\Fleet\Link::parseKey(\Talea\Fleet\Link::makeKey('http://console.example.com', str_repeat('ab', 16), $fleetPub, 'x'))], '2.9: Fleet\Link – the pairing key carries the console address, the one-time code and the console key');
        $fleetNow = 1_800_000_000;
        $fleetRow = fn (array $more, array $beat = []): array => $more + ['up' => 1, 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600), 'status' => 'ok', 'heartbeat' => (string) json_encode($beat + ['last_backup' => $fleetNow - 3600, 'cron_last_run' => $fleetNow - 300])];
        $this->assertSame([['score' => 0, 'reasons' => [], 'accepted_reasons' => [], 'items' => []], ['down', 'errors'], ['not_reporting'], ['no_heartbeat'], ['jobs_failing', 'no_backup', 'enquiries']], [
            \Talea\Fleet\Console::attention($fleetRow([]), $fleetNow),
            \Talea\Fleet\Console::attention($fleetRow(['up' => 0, 'status' => 'error']), $fleetNow)['reasons'],
            \Talea\Fleet\Console::attention($fleetRow(['last_seen' => date('Y-m-d H:i:s', $fleetNow - 30 * 3600)]), $fleetNow)['reasons'],
            \Talea\Fleet\Console::attention($fleetRow(['last_seen' => null, 'heartbeat' => null]), $fleetNow)['reasons'],
            \Talea\Fleet\Console::attention($fleetRow([], ['last_backup' => $fleetNow - 9 * 86400, 'enquiries_unanswered' => 2, 'jobs_failing' => ['mail']]), $fleetNow)['reasons'],
        ], '2.9: attention – what is wrong with a site, weighted');
        $fleetClean = \Talea\Fleet\Console::clean(['version' => '2.9.0', 'name' => str_repeat('x', 400), 'secret' => 'drop me', 'problems' => [['check' => 'Mail', 'status' => 'warning', 'detail' => ['deep' => ['deeper' => ['deepest' => 1]]]]], 'visits_7_days' => 12.7]);
        $this->assertSame([false, 255, '2.9.0', 12, 'Mail'], [isset($fleetClean['secret']), mb_strlen($fleetClean['name']), $fleetClean['version'], $fleetClean['visits_7_days'], $fleetClean['problems'][0]['check']], '2.9: a heartbeat keeps only the known keys with sane values');
    }

    /** 2.16: shared design kit of a fleet (Fleet\Kit) */
    public function testDesignKit(): void
    {
        $kit = \Talea\Fleet\Kit::class;
        $kitManifest = $kit::compose(['colors' => ['primary' => '#AA0000', 'text' => 'red'], 'custom_fonts' => [['name' => 'X', 'file' => 'media/x.woff2']], 'font_heading' => 'custom-1', 'nonsense' => 1],
            ['kit-band' => ['style' => [], 'css' => 'padding: 2rem; background: url(http://evil/x.png); color: red'], 'Bad Name' => ['style' => [], 'css' => 'color: red']],
            [['name' => 'Kit card', 'properties' => '[{"key":"title","label":"Title","type":"text","default":"Hi"}]', 'build' => json_encode(['v' => 1, 'children' => [['type' => 'section', 'children' => [
                    ['type' => 'heading', 'content' => ['text' => 'Hi'], 'attributes' => ['onclick' => 'alert(1)', 'data-x' => 'y']], ['type' => 'custom_html', 'content' => ['code' => '<script>alert(1)</script>']]]]]]), 'build_draft' => null],
                ['name' => 'Kit card', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"section"}]}', 'build_draft' => null], // the same key again: the first one stays
                ['name' => '', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"section"}]}', 'build_draft' => null], // no name, no key
                ['name' => 'Only code', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"custom_html","content":{"code":"<script>x()</script>"}}]}', 'build_draft' => null]], // nothing survives without admin rights
            [['name' => 'Kit banner', 'element' => '{"type":"section","children":[{"type":"text","content":{"html":"<p>Hello<script>x()</script></p>"}}]}'], ['name' => 'Broken', 'element' => '{"type":"nonsense"}']]);
        $this->assertSame(['#aa0000', '#16181d', [], 'modern', false, ['kit-band'], 'padding: 2rem; color: red;', ['kit-card'], 1, ['data-x' => 'y'], ['title'], ['kit-banner'], '<p>Hello</p>', 'design system, 1 class, 1 component, 1 section'], [
            $kitManifest['design_system']['colors']['primary'], $kitManifest['design_system']['colors']['text'], $kitManifest['design_system']['custom_fonts'], $kitManifest['design_system']['font_heading'], isset($kitManifest['design_system']['nonsense']),
            array_keys($kitManifest['classes']), $kitManifest['classes']['kit-band']['css'],
            array_column($kitManifest['components'], 'key'), count($kitManifest['components'][0]['build']['children'][0]['children']), $kitManifest['components'][0]['build']['children'][0]['children'][0]['attributes'], array_column($kitManifest['components'][0]['properties'], 'key'),
            array_column($kitManifest['sections'], 'key'), $kitManifest['sections'][0]['element']['children'][0]['content']['html'], $kit::summary($kitManifest)], '2.16 Kit::compose – the design system is sanitized and comes without custom fonts, a class needs a valid name and keeps only safe declarations, a component loses the custom HTML element and a script attribute, a repeated or empty key and a build with nothing left are dropped, a section is cleaned like a build');
        $this->assertSame([['classes' => [], 'components' => [], 'sections' => []], ['classes' => [], 'components' => [], 'sections' => []], true], [$kit::sanitize(['foo' => 1, 'classes' => 'x', 'components' => 'y']), $kit::sanitize(null), preg_replace('/"id":"[a-z0-9]+"/', '', $kit::encode($kit::sanitize($kitManifest))) === preg_replace('/"id":"[a-z0-9]+"/', '', $kit::encode($kitManifest))], '2.16 Kit::sanitize – unknown keys and wrong shapes are dropped, the result always has the three lists; a sanitized manifest sanitizes to itself except for fresh element ids');
        $kitRefs = $kit::compose(null, [], [
            ['component_id' => 7, 'name' => 'Inner card', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"section","children":[{"type":"text","content":{"html":"<p>x</p>"}}]}]}', 'build_draft' => null],
            ['component_id' => 9, 'name' => 'Outer card', 'properties' => '[]', 'build' => json_encode(['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'component', 'content' => ['component' => '7']], ['type' => 'component', 'content' => ['component' => '55']]]]]]), 'build_draft' => null]], []);
        $this->assertSame(['@inner-card', '', ''], [$kitRefs['components'][1]['build']['children'][0]['children'][0]['content']['component'], $kitRefs['components'][1]['build']['children'][0]['children'][1]['content']['component'],
                $kit::sanitize(['components' => [['key' => 'x', 'name' => 'X', 'build' => ['v' => 1, 'children' => [['type' => 'component', 'content' => ['component' => '3']]]]]]])['components'][0]['build']['children'][0]['content']['component']], '2.16 Kit – a component used inside a kit component travels by the kit key, a foreign integer never; a manifest from outside is cleaned the same way');
        $this->assertSame([['version' => 2, 'sha256' => str_repeat('a', 64)], null, null, null, null, null, null], [
            $kit::announced(['version' => 2, 'sha256' => str_repeat('a', 64)], 1), $kit::announced(['version' => 1, 'sha256' => str_repeat('a', 64)], 1), $kit::announced(['version' => '2', 'sha256' => str_repeat('a', 64)], 1),
            $kit::announced(['version' => 2, 'sha256' => 'xyz'], 1), $kit::announced(['version' => 2], 1), $kit::announced(null, 0), $kit::announced(['version' => 5_000_000, 'sha256' => str_repeat('a', 64)], 0)], '2.16 Kit::announced – only a well-formed announcement of a newer version is fetched');
        // the console signs the kit with its own key; the site checks the signature and the announced hash before it reads the manifest
        $kitSettings = static function (): \Talea\Core\Settings {
            $s = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(\Talea\Core\Settings::class, 'values'))->setValue($s, ['site_key_secret' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
            (new \ReflectionProperty(\Talea\Core\Settings::class, 'db'))->setValue($s, new \Talea\Core\Db('mysql:host=127.0.0.1;dbname=none', '', '')); // never connects: the key is set

            return $s;
        };
        $kitConsole = $kitSettings();
        $kitJson = $kit::encode($kitManifest);
        $kitSha = hash('sha256', $kitJson);
        $kitBody = (string) json_encode(['ok' => true, 'version' => 3, 'sha256' => $kitSha, 'manifest' => $kitJson], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $kitAnswer = fn (string $body, string $signature): array => ['status' => 200, 'body' => $body, 'json' => json_decode($body, true), 'signature' => $signature, 'error' => ''];
        $kitSignature = \Talea\Fleet\Keys::sign($kitConsole, $kitBody);
        $kitRefused = function (array $answer, string $key, int $version, string $sha): ?string {
            try {
                \Talea\Fleet\Kit::verifyAnswer($answer, $key, $version, $sha);

                return null;
            } catch (\RuntimeException $e) {
                return explode(' – ', $e->getMessage())[0];
            }
        };
        $kitTampered = str_replace('#aa0000', '#bb0000', $kitBody);
        $kitForged = (string) json_encode(['ok' => true, 'version' => 3, 'sha256' => hash('sha256', str_replace('#aa0000', '#bb0000', $kitJson)), 'manifest' => str_replace('#aa0000', '#bb0000', $kitJson)]);
        $this->assertSame(['kit-card', 'The kit is not signed by the console', 'The kit is not signed by the console', 'The console handed over a different kit version than it announced', 'The kit does not match the hash the console announced', 'The console did not hand over the kit (No answer.).', true, true, true], [
            $kit::verifyAnswer($kitAnswer($kitBody, $kitSignature), \Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha)['components'][0]['key'],
            $kitRefused($kitAnswer($kitTampered, $kitSignature), \Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
            $kitRefused($kitAnswer($kitBody, $kitSignature), \Talea\Fleet\Keys::publicKey($kitSettings()), 3, $kitSha),
            $kitRefused($kitAnswer($kitBody, $kitSignature), \Talea\Fleet\Keys::publicKey($kitConsole), 4, $kitSha),
            $kitRefused($kitAnswer($kitForged, \Talea\Fleet\Keys::sign($kitConsole, $kitForged)), \Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
            $kitRefused(['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'No answer.'], \Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
            isset(\Talea\Core\Events::TYPES['fleet.kit_received']), isset(\Talea\Core\Events::TYPES['fleet.kit_refused']), isset(\Talea\Core\Events::TYPES['fleet.kit_published'])], '2.16 Kit::verifyAnswer – the signed kit with the announced hash passes; a tampered body, another console\'s key, a different version, a manifest that does not hash to the announcement, or no answer are refused');
    }

    /** #341: the heartbeat names the database, the web server, the features and the languages; an older heartbeat without them stays valid */
    public function testHeartbeatInventory(): void
    {
        $this->assertSame(['MySQL 8.4', 'MariaDB 11.4', 'PostgreSQL 18.1', 'SQLite 3.40', ''], array_map(\Talea\Fleet\Heartbeat::database(...),
            ['8.4.3', '11.4.2-MariaDB-ubu2404', 'PostgreSQL 18.1 on x86_64-pc-linux-gnu', 'SQLite 3.40.1', 'junk']));
        $this->assertSame(['Apache', 'nginx', 'FrankenPHP', 'PHP built-in server', ''], [
            \Talea\Fleet\Heartbeat::serverFamily('Apache/2.4.62 (Debian)', 'fpm-fcgi'), \Talea\Fleet\Heartbeat::serverFamily('nginx/1.27', 'fpm-fcgi'), \Talea\Fleet\Heartbeat::serverFamily('', 'frankenphp'),
            \Talea\Fleet\Heartbeat::serverFamily('PHP 8.5.0 Development Server', 'cli-server'), \Talea\Fleet\Heartbeat::serverFamily('', 'cli')]);

        $now = 1_800_000_000;
        $old = ['name' => 'Old', 'version' => '2.9.0', 'last_backup' => $now - 3600, 'cron_last_run' => $now - 300, 'status' => 'ok'];
        $cleaned = \Talea\Fleet\Console::clean($old);
        $this->assertSame([null, null, null, null], [$cleaned['database'], $cleaned['web_server'], $cleaned['extensions'], $cleaned['languages']], 'a heartbeat without the new keys reads them as null');
        $row = ['up' => 1, 'last_seen' => date('Y-m-d H:i:s', $now - 600), 'status' => 'ok', 'heartbeat' => (string) json_encode($cleaned)];
        $this->assertSame([], \Talea\Fleet\Console::attention($row, $now)['reasons'], 'an old heartbeat needs no attention for what it does not say');
        $full = \Talea\Fleet\Console::clean($old + ['database' => 'MariaDB 11.4', 'extensions' => array_map(fn (int $i): string => 'ext' . $i, range(1, 50))]);
        $this->assertSame(['MariaDB 11.4', 40], [$full['database'], count($full['extensions'])]);
    }

    /** #341: the end of a site's certificate from what curl reports */
    public function testCertificateEnd(): void
    {
        $this->assertSame([null, gmmktime(23, 59, 59, 1, 14, 2027), gmmktime(23, 59, 59, 1, 14, 2027), null], [
            \Talea\Fleet\Http::certificateEnd([]),
            \Talea\Fleet\Http::certificateEnd([['Expire date' => 'Jan 14 23:59:59 2027 GMT']]),
            \Talea\Fleet\Http::certificateEnd([['Expire date' => '2027-01-14 23:59:59 GMT']]),
            \Talea\Fleet\Http::certificateEnd([['Expire date' => 'junk']])]);
        $chain = [['Expire date' => 'Jan 14 23:59:59 2027 GMT']];
        $this->assertSame([null, null, null, strtotime('2027-01-14 23:59:59 UTC')], [\Talea\Fleet\Http::verifiedCertificateEnd(0, $chain), \Talea\Fleet\Http::verifiedCertificateEnd(0, [['Expire date' => 'junk']]), \Talea\Fleet\Http::verifiedCertificateEnd(200, false), \Talea\Fleet\Http::verifiedCertificateEnd(200, $chain)],
            'the chain of a request that failed (status 0, e.g. a certificate for another name) gives no date');
        $this->assertSame([null], array_column(\Talea\Fleet\Http::probe(['http://10.0.0.5:8080/admin']), 'certificate_ends'), 'a refused address has no certificate');
        if (!function_exists('openssl_csr_new')) {
            $this->markTestSkipped('openssl is missing');
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'console.test'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 10), $pem);
        $this->assertEqualsWithDelta(time() + 10 * 86400, \Talea\Fleet\Http::certificateEnd([['Cert' => $pem, 'Expire date' => 'junk']]), 86400, 'the end of a real certificate is read from its PEM');
    }

    /** #341: certificate and domain dates – days left, severity, attention, the reminders once per threshold, the calendar month */
    public function testExpiry(): void
    {
        $now = strtotime('2026-11-13 12:00:00');
        $this->assertSame([14, -1], [\Talea\Fleet\Expiry::daysLeft('2026-11-27', $now), \Talea\Fleet\Expiry::daysLeft('2026-11-12 08:00:00', $now)]);
        $in = fn (int $days): string => date('Y-m-d', $now + $days * 86400);
        $this->assertSame([null, 'warning', 'error', 'error', null, 'warning'], [
            \Talea\Fleet\Expiry::severity('certificate', $in(15), $now), \Talea\Fleet\Expiry::severity('certificate', $in(14), $now), \Talea\Fleet\Expiry::severity('certificate', $in(7), $now),
            \Talea\Fleet\Expiry::severity('certificate', $in(-2), $now), \Talea\Fleet\Expiry::severity('domain', $in(31), $now), \Talea\Fleet\Expiry::severity('domain', $in(30), $now)]);
        $row = fn (array $more): array => $more + ['up' => 1, 'last_seen' => date('Y-m-d H:i:s', $now - 600), 'status' => 'ok', 'heartbeat' => (string) json_encode(['last_backup' => $now - 3600, 'cron_last_run' => $now - 300])];
        $this->assertSame(['certificate', 'domain'], \Talea\Fleet\Console::attention($row(['cert_expires' => $in(10) . ' 12:00:00', 'domain_expires' => $in(20)]), $now)['reasons']);

        $site = fn (string $date, array $sent = []): array => ['cert_expires' => $date . ' 12:00:00', 'domain_expires' => null, 'reminders' => json_encode($sent)];
        $date13 = $in(13);
        [$reminders, $keys] = \Talea\Fleet\Expiry::due($site($date13), $now);
        $this->assertSame([[['kind' => 'certificate', 'date' => $date13, 'days' => 13]], ["certificate|$date13|14"]], [$reminders, $keys], 'the first threshold');
        $date6 = $in(6);
        [$reminders, $keys] = \Talea\Fleet\Expiry::due($site($date6, ["certificate|$date6|14"]), $now);
        $this->assertSame([1, ["certificate|$date6|14", "certificate|$date6|7"]], [count($reminders), $keys], 'the next threshold of the same date');
        $this->assertSame([[], ["certificate|$date6|14", "certificate|$date6|7"]], \Talea\Fleet\Expiry::due($site($date6, ["certificate|$date6|14", "certificate|$date6|7"]), $now), 'nothing twice');
        $date60 = $in(60);
        $this->assertSame([[], []], \Talea\Fleet\Expiry::due($site($date60, ["certificate|$date6|14", "certificate|$date6|7"]), $now), 'a renewed date: the old keys go and nothing is due');
        $date5 = $in(5);
        [$reminders, $keys] = \Talea\Fleet\Expiry::due($site($date5), $now);
        $this->assertSame([1, ["certificate|$date5|14", "certificate|$date5|7"]], [count($reminders), $keys], 'first seen at 5 days: one mail, both thresholds count as sent');
        $this->assertSame([[], []], \Talea\Fleet\Expiry::due($site($date5), $now, ['certificate']), 'an accepted kind is muted: no reminder, nothing marked as sent');

        $november = \Talea\Fleet\Expiry::month('2026-11', []);
        $this->assertSame([6, [null, null, null, null, null, null, '2026-11-01'], '2026-10', '2026-12'], [count($november['weeks']), array_column($november['weeks'][0], 'day'), $november['previous'], $november['next']]);
        $this->assertSame(date('Y-m'), \Talea\Fleet\Expiry::month('junk', [])['year'] . '-' . sprintf('%02d', \Talea\Fleet\Expiry::month('junk', [])['month']));
    }

    /** #341: Today – items sorted into groups, accepted states hold until their day or until the state changes */
    public function testTodayGroupsAndAcceptedStates(): void
    {
        $now = strtotime('2026-11-13 12:00:00');
        $today = date('Y-m-d', $now);
        $plus = fn (int $days): string => date('Y-m-d', $now + $days * 86400);
        $problems = [['group' => 'Security', 'check' => 'Debug mode', 'status' => 'warning'], ['group' => 'Operation', 'check' => 'Off-site backups', 'status' => 'warning'], ['group' => 'Server', 'check' => 'Free disk space', 'status' => 'warning']];
        $row = fn (array $more = [], array $beat = []): array => $more + ['id' => 1, 'public_id' => 'a', 'name' => 'Aria', 'url' => 'https://a.example', 'up' => 0, 'up_status' => 503, 'status' => 'warning', 'last_seen' => date('Y-m-d H:i:s', $now - 600),
            'heartbeat' => (string) json_encode($beat + ['last_backup' => $now - 3600, 'cron_last_run' => $now - 300, 'enquiries_unanswered' => 2, 'problems' => $problems])];
        $attention = \Talea\Fleet\Console::attention($row(), $now);
        $this->assertSame(['down/uptime/error', 'enquiries/enquiries/warning', 'check:Debug mode/security/warning', 'check:Off-site backups/backups/warning', 'check:Free disk space/errors/warning'],
            array_map(fn (array $i): string => $i['key'] . '/' . $i['group'] . '/' . $i['severity'], $attention['items']));
        $this->assertSame(['down', 'warnings', 'enquiries'], $attention['reasons']);

        $groups = \Talea\Fleet\Today::groups([$row() + $attention]);
        $this->assertSame(['uptime', true, true], [$groups[0]['key'], $groups[0]['incident'], $groups[0]['open']], 'the group with an incident comes first and stays open');
        $many = [];
        foreach (range(1, 6) as $n) {
            $many[] = ['id' => $n, 'name' => "Site $n", 'score' => 10, 'items' => [['key' => 'enquiries', 'group' => 'enquiries', 'severity' => 'warning', 'accepted' => null]]];
        }
        $folded = \Talea\Fleet\Today::groups($many);
        $this->assertSame([false, false], [$folded[0]['incident'], $folded[0]['open']], 'six warnings fold');

        $item = ['key' => 'check:Debug mode', 'state' => 'warning'];
        foreach ([['', $plus(5)], ['why', $today], ['why', $plus(366)], ['why', 'junk']] as [$note, $until]) {
            try {
                \Talea\Fleet\Today::accept([], $item, $note, $until, 'admin', $now);
                $this->fail("$note/$until should be refused");
            } catch (\DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $stored = \Talea\Fleet\Today::accept([], $item, ' Known ', $plus(30), 'admin', $now);
        $this->assertSame(['state' => 'warning', 'note' => 'Known', 'until' => $plus(30), 'by' => 'admin', 'at' => '2026-11-13 12:00:00'], $stored['check:Debug mode']);

        $held = \Talea\Fleet\Console::attention($row(['accepted' => json_encode($stored)]), $now);
        $this->assertContains('warnings', $held['reasons'], 'the other warning items stay open');
        $this->assertSame($attention['score'], $held['score']);
        $all = [];
        foreach (['check:Debug mode', 'check:Off-site backups', 'check:Free disk space'] as $key) {
            $all = \Talea\Fleet\Today::accept($all, ['key' => $key, 'state' => 'warning'], 'ok', $plus(30), 'admin', $now);
        }
        $none = \Talea\Fleet\Console::attention($row(['accepted' => json_encode($all)]), $now);
        $this->assertSame([['down', 'enquiries'], ['warnings'], $attention['score'] - 10], [$none['reasons'], $none['accepted_reasons'], $none['score']], 'all warning items accepted: the reason moves to accepted and the score drops');

        $worse = \Talea\Fleet\Console::attention($row(['accepted' => json_encode($all), 'status' => 'error'], ['problems' => [['group' => 'Security', 'check' => 'Debug mode', 'status' => 'error'], $problems[1], $problems[2]]]), $now);
        $this->assertSame('check:Debug mode', array_values(array_filter($worse['items'], fn (array $i): bool => $i['accepted'] === null && $i['severity'] === 'error' && str_starts_with($i['key'], 'check:')))[0]['key'], 'a worse state is not held');

        $cert = ['key' => 'certificate', 'state' => $plus(12) . '|warning'];
        $this->assertFalse(\Talea\Fleet\Today::holds(['state' => $plus(12) . '|warning', 'until' => $plus(30)], ['key' => 'certificate', 'state' => $plus(12) . '|error'] + $cert, $now), 'a certificate accepted at warning is not held at error');
        $this->assertFalse(\Talea\Fleet\Today::holds(['state' => 'warning', 'until' => $plus(-1)], $item, $now), 'a past last day does not hold');
        $kept = \Talea\Fleet\Today::prune(['check:Debug mode' => ['state' => 'warning', 'until' => $plus(5)], 'check:Free disk space' => ['state' => 'error', 'until' => $plus(5)], 'check:Gone' => ['state' => 'warning', 'until' => $plus(5)],
            'enquiries' => ['state' => 'enquiries', 'until' => $plus(-1)]], $attention['items'], $now);
        $this->assertSame(['check:Debug mode'], array_keys($kept), 'changed, gone and expired acceptances are dropped');

        $site = $row(['cert_expires' => $plus(12) . ' 12:00:00', 'accepted' => json_encode(['certificate' => ['state' => $plus(12) . '|warning', 'note' => 'n', 'until' => $plus(30)]])]);
        $site += \Talea\Fleet\Console::attention($site, $now);
        $this->assertSame([[], []], [\Talea\Fleet\Expiry::due($site, $now, ['certificate'])[0], \Talea\Fleet\Expiry::soon([$site], $now)], 'an accepted certificate has no reminder and is not in the bell');
        $this->assertSame(1, count(\Talea\Fleet\Expiry::due($site, $now)[0]), 'without the acceptance the reminder is due');
    }

    /** #341: the number on the Today tab counts the sites that have an open item, the very ones Today lists – also a site with only enquiries waiting. */
    public function testTheTodayCountAgreesWithTheItemsListed(): void
    {
        $now = strtotime('2026-11-13 12:00:00');
        $until = date('Y-m-d', $now + 30 * 86400);
        $row = fn (int $id, int $enquiries): array => ['id' => $id, 'public_id' => "s$id", 'name' => "Site $id", 'url' => "https://s$id.example", 'up' => 1, 'up_status' => 200, 'status' => 'ok', 'last_seen' => date('Y-m-d H:i:s', $now - 600),
            'heartbeat' => (string) json_encode(['last_backup' => $now - 3600, 'cron_last_run' => $now - 300, 'enquiries_unanswered' => $enquiries])];
        $withEnquiries = $row(1, 2);
        $withEnquiries += \Talea\Fleet\Console::attention($withEnquiries, $now);
        $this->assertLessThan(\Talea\Fleet\Console::REASONS['warnings'], $withEnquiries['score'], 'only enquiries: below the old threshold');
        $fine = $row(2, 0);
        $fine += \Talea\Fleet\Console::attention($fine, $now);
        $this->assertSame(1, \Talea\Fleet\Today::needing([$withEnquiries, $fine]));
        $this->assertSame(['enquiries'], array_column(\Talea\Fleet\Today::groups([$withEnquiries, $fine]), 'key'), 'Today lists that site');

        $acceptedRow = $row(3, 2) + ['accepted' => json_encode(\Talea\Fleet\Today::accept([], ['key' => 'enquiries', 'state' => 'enquiries'], 'known', $until, 'admin', $now))];
        $acceptedRow += \Talea\Fleet\Console::attention($acceptedRow, $now);
        $this->assertSame(0, \Talea\Fleet\Today::needing([$acceptedRow, $fine]), 'an accepted item is not open');
    }

    /** #341: the inventory – rows from the reports, values, filters, CSV */
    public function testInventory(): void
    {
        $old = \Talea\Fleet\Inventory::row(['id' => 1, 'public_id' => 'a', 'name' => 'Old', 'url' => 'https://old.example', 'version' => '2.9.0', 'beat' => ['php' => '8.4.1'], 'last_seen' => null, 'reasons' => [], 'accepted_reasons' => []]);
        $this->assertSame(['', '', [], []], [$old['database'], $old['web_server'], $old['features'], $old['languages']], 'an old report gives empty values');
        $rows = [
            \Talea\Fleet\Inventory::row(['id' => 1, 'public_id' => 'a', 'name' => 'Aria', 'url' => 'https://aria.example', 'version' => '4.2.3', 'last_seen' => null, 'reasons' => ['errors'], 'accepted_reasons' => [],
                'beat' => ['php' => '8.5.0', 'database' => 'MySQL 8.4', 'web_server' => 'nginx', 'extensions' => ['news', 'fleet'], 'languages' => ['en', 'de']]]),
            \Talea\Fleet\Inventory::row(['id' => 2, 'public_id' => 'b', 'name' => '=cmd', 'url' => 'https://b.example', 'version' => '4.2.10', 'last_seen' => null, 'reasons' => [], 'accepted' => [],
                'beat' => ['php' => '8.5.0', 'database' => 'MariaDB 11.4', 'web_server' => 'Apache', 'extensions' => ['news'], 'languages' => ['en']]]),
        ];
        $values = \Talea\Fleet\Inventory::values($rows);
        $this->assertSame([['MariaDB 11.4', 'MySQL 8.4'], ['fleet', 'news'], ['4.2.3', '4.2.10']], [$values['database'], $values['feature'], $values['version']]);
        $this->assertSame([1, 2, 1], [count(\Talea\Fleet\Inventory::filter($rows, ['feature' => 'fleet'], '')), count(\Talea\Fleet\Inventory::filter($rows, ['language' => 'en'], '')), count(\Talea\Fleet\Inventory::filter($rows, [], 'ARIA'))]);
        $this->assertEquals(['feature' => 'fleet', 'database' => ''], array_intersect_key(\Talea\Fleet\Inventory::filtersFrom(fn (string $k): string => $k === 'feature' ? ' fleet ' : ''), ['feature' => 1, 'database' => 1]));
        $csv = \Talea\Fleet\Inventory::csv($rows);
        $this->assertSame(["\xEF\xBB\xBF", true, true], [substr($csv, 0, 3), str_contains($csv, ';'), str_contains($csv, "'=cmd;")]);
    }
}
