<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Antispam;
use Talea\Core\Connectors;
use Talea\Core\Settings;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * The site's secret out of the database (#152): without TALEA_SECRET_KEY the key stays in secret_key and System status warns; with
 * it the first use encrypts the stored connector credentials again under the variable and clears the database key; a database
 * without its variable reads none of them and System status says so. The tests run outside the base class's transaction (the move
 * opens its own) and every one leaves no connector, no key and no variable behind.
 */
#[CoversClass(Connectors::class)]
#[CoversClass(Antispam::class)]
final class SecretKeyTest extends DatabaseTestCase
{
    private const string SECRET = '5f0c6e1a9b2d4f8e7a3c1b0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->pdo()->commit();
        putenv('TALEA_SECRET_KEY');
    }

    protected function tearDown(): void
    {
        putenv('TALEA_SECRET_KEY');
        $this->db()->run('DELETE FROM {connectors}');
        $this->db()->run("DELETE FROM {settings} WHERE name = 'secret_key'");
        parent::tearDown();
    }

    /** The settings the next request reads (nothing cached). */
    private function settings(): Settings
    {
        return new Settings($this->db());
    }

    private function databaseKey(): string
    {
        return (string) $this->db()->value("SELECT value FROM {settings} WHERE name = 'secret_key'");
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return (array) $this->db()->one("SELECT secret, access_token, refresh_token FROM {connectors} WHERE service = 'hubspot'");
    }

    private function storeCredentials(): void
    {
        $settings = $this->settings();
        $this->db()->insert('connectors', ['service' => 'hubspot', 'secret' => Connectors::encrypt($settings, 'api-token'), 'access_token' => Connectors::encrypt($settings, 'access'), 'refresh_token' => null]);
    }

    public function testWithoutTheVariableTheKeyStaysInTheDatabaseAndStatusWarns(): void
    {
        $this->storeCredentials();

        $this->assertNotSame('', $this->databaseKey(), 'the key is created in the database as before');
        $this->assertSame($this->databaseKey(), (new Antispam($this->db(), $this->settings()))->key());
        $this->assertSame('api-token', Connectors::decrypt($this->settings(), $this->stored()['secret']));
        $this->assertSame('warning', Connectors::keyStatus($this->settings())[0], 'System status warns about the key in the database');
    }

    public function testTheVariableReEncryptsTheCredentialsAndClearsTheDatabaseKey(): void
    {
        $this->storeCredentials();
        $before = $this->stored();
        $signingBefore = (new Antispam($this->db(), $this->settings()))->key();

        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $this->assertSame(self::SECRET, Antispam::environmentSecret($this->settings()));

        $this->assertSame('', $this->databaseKey(), 'the database key is cleared');
        $after = $this->stored();
        $this->assertNotSame($before['secret'], $after['secret']);
        $this->assertNotSame($before['access_token'], $after['access_token']);
        $this->assertNull($after['refresh_token'], 'an empty column stays empty');
        $settings = $this->settings();
        $this->assertSame(['api-token', 'access'], [Connectors::decrypt($settings, $after['secret']), Connectors::decrypt($settings, $after['access_token'])]);
        $this->assertSame('ok', Connectors::keyStatus($settings)[0]);

        $signing = (new Antispam($this->db(), $this->settings()))->key();
        $this->assertNotSame($signingBefore, $signing, 'the signing key comes from the variable too');
        $this->assertSame($signing, (new Antispam($this->db(), $this->settings()))->key(), 'and stays the same');
        $this->assertNotSame(self::SECRET, $signing, 'in its own context, not the variable itself');
        $this->assertSame('', $this->databaseKey(), 'nothing of it is written to the database');
    }

    public function testCredentialsReadOnlyWithTheEnvironmentKey(): void
    {
        $this->storeCredentials();
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        Antispam::environmentSecret($this->settings());

        // the database alone (restored on a server without the variable): nothing can be read, System status says so
        putenv('TALEA_SECRET_KEY');
        $this->assertNull(Connectors::decrypt($this->settings(), $this->stored()['secret']));
        [$state, $info] = Connectors::keyStatus($this->settings());
        $this->assertSame('error', $state);
        $this->assertStringContainsString('2 stored connector credential(s) cannot be read', $info);

        // another variable cannot read them either
        putenv('TALEA_SECRET_KEY=' . str_repeat('0', 64));
        $this->assertNull(Connectors::decrypt($this->settings(), $this->stored()['secret']));

        // the variable they were stored with: readable again, the key created in between is cleared, unreadable values untouched
        $this->db()->update('settings', ['value' => bin2hex(random_bytes(32))], ['name' => 'secret_key']);
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $this->assertSame('api-token', Connectors::decrypt($this->settings(), $this->stored()['secret']));
        $this->assertSame('', $this->databaseKey());
        $this->assertSame('ok', Connectors::keyStatus($this->settings())[0]);
    }

    public function testShortVariableIsIgnoredAndReported(): void
    {
        $this->storeCredentials();
        $key = $this->databaseKey();

        putenv('TALEA_SECRET_KEY=too-short');
        $this->assertNull(Antispam::environmentSecret($this->settings()));
        $this->assertSame($key, (new Antispam($this->db(), $this->settings()))->key(), 'the database key stays in use');
        $this->assertSame('api-token', Connectors::decrypt($this->settings(), $this->stored()['secret']));
        $this->assertSame('error', Connectors::keyStatus($this->settings())[0]);
    }

    public function testNewSiteWithTheVariableNeverStoresAKey(): void
    {
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        (new Antispam($this->db(), $this->settings()))->key();
        $this->storeCredentials();

        $this->assertSame('', $this->databaseKey());
        $this->assertSame('access', Connectors::decrypt($this->settings(), $this->stored()['access_token']));
    }
}
