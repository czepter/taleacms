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
            \Talea\Fleet\Http::statuses(['http://10.0.0.5:8080/admin', 'gopher://example.com/']), \Talea\Fleet\Http::allowedUrl('http://example.com/', true)], '3.3.2: Fleet\Http pins public addresses only – loopback, private, link-local and NAT64 are refused; the uptime check of a refused address is 0 without a request');
        $fleetKey = \Talea\Fleet\Link::makeKey('https://console.example.com/', str_repeat('ab', 16), $fleetPub, 'Agency console');
        $this->assertSame([['url' => 'https://console.example.com', 'code' => str_repeat('ab', 16), 'key' => $fleetPub, 'name' => 'Agency console'], null, null, null], [
            \Talea\Fleet\Link::parseKey(" \n" . chunk_split($fleetKey, 40, "\n")), \Talea\Fleet\Link::parseKey('talea-console:junk'), \Talea\Fleet\Link::parseKey(str_repeat('ab', 16)),
            \Talea\Fleet\Link::parseKey(\Talea\Fleet\Link::makeKey('http://console.example.com', str_repeat('ab', 16), $fleetPub, 'x'))], '2.9: Fleet\Link – the pairing key carries the console address, the one-time code and the console key');
        $fleetNow = 1_800_000_000;
        $fleetRow = fn (array $more, array $beat = []): array => $more + ['up' => 1, 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600), 'status' => 'ok', 'heartbeat' => (string) json_encode($beat + ['last_backup' => $fleetNow - 3600, 'cron_last_run' => $fleetNow - 300])];
        $this->assertSame([['score' => 0, 'reasons' => []], ['down', 'errors'], ['not_reporting'], ['no_heartbeat'], ['jobs_failing', 'no_backup', 'enquiries']], [
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
}
