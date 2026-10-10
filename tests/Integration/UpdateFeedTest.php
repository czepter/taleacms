<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use Talea\Core\Config;
use Talea\Core\Settings;
use Talea\Core\Signature;
use Talea\Core\UpdateFeed;
use Talea\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** HF-13: the release feed is signed, checked at most once a day, and only ever produces a notice. */
#[CoversClass(UpdateFeed::class)]
final class UpdateFeedTest extends DatabaseTestCase
{
    private string $keyFile = '';
    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'pub');
        file_put_contents($this->keyFile, base64_encode(sodium_crypto_sign_publickey($pair)) . " test\n");
        putenv('TALEA_UPDATE_FEED=https://feed.example.test/update.json');
        putenv('TALEA_UPDATE_CHECK');
        $this->db()->upsert('settings', ['name' => 'update_check', 'value' => '1'], ['name']);
        $this->db()->run("DELETE FROM {settings} WHERE name = 'update_feed_cache'");
    }

    protected function tearDown(): void
    {
        putenv('TALEA_UPDATE_FEED');
        putenv('TALEA_UPDATE_CHECK');
        @unlink($this->keyFile);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function feed(string $version, bool $security = false, ?string $signWith = null): array
    {
        $digest = hash('sha256', $version);

        return ['version' => $version, 'released' => '2026-10-10', 'security' => $security, 'changes' => ['Fixed <b>things</b>'], 'image' => 'ghcr.io/example/talea:' . $version, 'digest' => 'sha256:' . $digest,
            'signature' => base64_encode(sodium_crypto_sign_detached(Signature::packageMessage($signWith ?? $version, $digest, $security), $this->secret))];
    }

    private function subject(array &$requests, array $feed): UpdateFeed
    {
        return new UpdateFeed(new Settings($this->db()), $this->keyFile, function (string $url) use (&$requests, $feed): string {
            $requests[] = $url;

            return (string) json_encode($feed);
        });
    }

    public function testANewerSignedVersionIsAvailable(): void
    {
        $requests = [];
        $available = $this->subject($requests, $this->feed('99.0.0', true))->available();

        $this->assertSame('99.0.0', $available['version']);
        $this->assertTrue($available['security']);
        $this->assertSame(['Fixed things'], $available['changes']);
        $this->assertSame(['https://feed.example.test/update.json'], $requests, 'one plain request, no query');
    }

    public function testTheAnswerIsCachedForADay(): void
    {
        $requests = [];
        $feed = $this->subject($requests, $this->feed('99.0.0'));
        $feed->available();
        $feed->available();

        $this->assertCount(1, $requests);
    }

    public function testAForgedOrTamperedFeedIsIgnored(): void
    {
        $requests = [];
        $tampered = $this->feed('99.0.0', false, '98.0.0'); // signed for another version
        $feed = $this->subject($requests, $tampered);

        $this->assertNull($feed->available());
        $this->assertSame('The release feed is not signed by the publisher.', $feed->error());
    }

    public function testAnOlderOrEqualVersionGivesNoNotice(): void
    {
        $requests = [];
        $this->assertNull($this->subject($requests, $this->feed(TALEA_VERSION))->available());
    }

    public function testNothingIsRequestedWhenTheCheckIsOff(): void
    {
        $requests = [];
        putenv('TALEA_UPDATE_CHECK=0');
        $this->assertNull($this->subject($requests, $this->feed('99.0.0'))->available());
        putenv('TALEA_UPDATE_CHECK');
        $this->db()->run("UPDATE {settings} SET value = '0' WHERE name = 'update_check'");
        $this->assertNull($this->subject($requests, $this->feed('99.0.0'))->available());
        putenv('TALEA_UPDATE_FEED');
        $this->db()->run("UPDATE {settings} SET value = '1' WHERE name = 'update_check'");
        $this->assertNull($this->subject($requests, $this->feed('99.0.0'))->available(), 'no feed address, no request');
        $this->assertSame([], $requests);
    }
}
