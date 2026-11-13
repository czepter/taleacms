<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Assistant;
use Talea\Core\Backup;
use Talea\Core\Extensions;
use Talea\Core\Settings;
use Talea\Core\SiteExport;
use Talea\Core\Vault;
use Talea\Core\Webhook;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Passwords and keys in the settings are sealed with the install key (#335): only while TALEA_SECRET_KEY is set, opened by Settings::get,
 * read as empty (and flagged in System status) when the key is wrong, never an exception. Outside the base class's transaction, like SecretKeyTest.
 */
#[CoversClass(Vault::class)]
final class SealedSecretsTest extends DatabaseTestCase
{
    private const string SECRET = '5f0c6e1a9b2d4f8e7a3c1b0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f';
    private const string OTHER = 'aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->pdo()->commit();
        putenv('TALEA_SECRET_KEY');
        $this->clean();
    }

    protected function tearDown(): void
    {
        putenv('TALEA_SECRET_KEY');
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $names = array_merge(Vault::SETTINGS, ['secret_key', 'extensions']);
        $this->db()->run('DELETE FROM {settings} WHERE name IN (' . implode(', ', array_fill(0, count($names), '?')) . ')', $names);
        $this->db()->run('DELETE FROM {connectors}');
    }

    private function settings(): Settings
    {
        return new Settings($this->db());
    }

    private function raw(string $name): string
    {
        return (string) $this->db()->value('SELECT value FROM {settings} WHERE name = ?', [$name]);
    }

    public function testWithoutTheVariableSecretsStayPlainAndStatusWarns(): void
    {
        $this->settings()->set('smtp_password', 'pw');
        $this->assertSame('pw', $this->raw('smtp_password'), 'with the key in the database nothing is sealed');
        $this->assertSame('pw', $this->settings()->get('smtp_password'));
        $this->assertSame('warning', Vault::status($this->settings())[0]);
        $this->assertSame(0, Vault::sealStored($this->db()), 'and nothing is sealed afterwards either');
    }

    public function testRoundTrip(): void
    {
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $settings = $this->settings();
        $settings->set('ai_key', 'sk-test');
        $this->assertStringStartsWith(Vault::PREFIX, $this->raw('ai_key'));
        $this->assertStringNotContainsString('sk-test', $this->raw('ai_key'));
        $this->assertSame('sk-test', $settings->get('ai_key'), 'the same Settings object');
        $this->assertSame('sk-test', $this->settings()->get('ai_key'), 'a new one');
        $settings->set('ai_key', '');
        $this->assertSame('', $this->raw('ai_key'), 'an empty value stays empty');
        $this->assertSame('', $settings->get('ai_key'));
    }

    public function testAValueFromBeforeIsSealed(): void
    {
        $this->db()->insert('settings', ['name' => 'backup_password', 'value' => 'old']);
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $this->assertSame('warning', Vault::status($this->settings())[0], 'System status says it is not sealed yet');
        $this->assertSame(1, Vault::sealStored($this->db()));
        $this->assertStringStartsWith(Vault::PREFIX, $this->raw('backup_password'));
        $this->assertSame('old', $this->settings()->get('backup_password'));
        $this->assertSame(0, Vault::sealStored($this->db()), 'a second run finds nothing');
        $this->assertSame('ok', Vault::status($this->settings())[0]);
    }

    public function testTheWrongKeyReadsEmptyAndIsFlagged(): void
    {
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $this->settings()->set('captcha_secret', 'cap');
        putenv('TALEA_SECRET_KEY=' . self::OTHER);
        $settings = $this->settings();
        $this->assertSame('', $settings->get('captcha_secret'), 'it reads as empty, no exception');
        $this->assertTrue($settings->unreadable('captcha_secret'));
        [$state, $info] = Vault::status($settings);
        $this->assertSame('error', $state);
        $this->assertStringContainsString('cannot be read', $info);
        putenv('TALEA_SECRET_KEY');
        $this->assertSame('', $this->settings()->get('captcha_secret'), 'without the variable too');
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $this->assertSame('cap', $this->settings()->get('captcha_secret'), 'and the right key reads it again');
    }

    public function testConsumersReadTheOpenedValues(): void
    {
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $settings = $this->settings();
        $secret = Webhook::secret($settings);
        $this->assertStringStartsWith('whsec_', $secret, 'the webhook secret is the plain one');
        $this->assertStringStartsWith(Vault::PREFIX, $this->raw('webhook_secret'));
        $this->assertSame(['1700000000', 'sha256=' . hash_hmac('sha256', '1700000000.b', $secret)], Webhook::signature($secret, 'b', 1700000000), 'calls are signed with the opened secret');

        $settings->set('ai_key', 'sk-test');
        Extensions::save($settings, ['assistant']);
        $this->assertTrue((new Assistant($this->settings()))->isReady(), 'the assistant has its key');

        $settings->set('smtp_password', 'mail-pw');
        $this->assertSame('mail-pw', $this->settings()->get('smtp_password'), 'what the SMTP login sends');
    }

    public function testAnUnreadableWebhookSecretIsNotReplaced(): void
    {
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        Webhook::secret($this->settings());
        $stored = $this->raw('webhook_secret');
        putenv('TALEA_SECRET_KEY=' . self::OTHER);
        $this->assertSame('', Webhook::secret($this->settings()), 'signed with nothing until the key is back');
        $this->assertSame($stored, $this->raw('webhook_secret'), 'the stored secret is not replaced');
    }

    public function testSiteExportCarriesNoSecret(): void
    {
        foreach (Vault::SETTINGS as $key) {
            $this->assertFalse(SiteExport::exportsSetting($key), $key);
        }
    }

    public function testBackupHoldsOnlySealedValues(): void
    {
        $this->db()->insert('settings', ['name' => 'smtp_password', 'value' => 'plain-secret-123']);
        putenv('TALEA_SECRET_KEY=' . self::SECRET);
        $file = Backup::create($this->db(), 'test');
        try {
            $path = (string) Backup::path($file);
            $sql = str_ends_with($path, '.gz') ? (string) gzdecode((string) file_get_contents($path)) : (string) file_get_contents($path);
            $this->assertStringContainsString(Vault::PREFIX, $sql);
            $this->assertStringNotContainsString('plain-secret-123', $sql, 'the file holds no plain password');
        } finally {
            @unlink((string) Backup::path($file));
        }
    }
}
