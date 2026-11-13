<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Admin\Modules\Settings;
use Talea\Core\SiteExport;
use Talea\Core\Vault;

/** The secretbox that seals stored secrets (#335). */
final class VaultTest extends TestCase
{
    public function testSealAndOpen(): void
    {
        $key = Vault::derive('a-secret-of-at-least-thirty-two-characters');
        $sealed = Vault::seal($key, 'smtp-password');
        $this->assertNotSame('smtp-password', $sealed);
        $this->assertNotSame($sealed, Vault::seal($key, 'smtp-password'), 'every seal has its own nonce');
        $this->assertSame('smtp-password', Vault::open($key, $sealed));
        $this->assertSame('', Vault::open($key, Vault::seal($key, '')), 'an empty value seals too');
    }

    public function testWrongKeyAndGarbageOpenNothing(): void
    {
        $key = Vault::derive('one-secret-one-secret-one-secret-1');
        $sealed = Vault::seal($key, 'x');
        $this->assertNull(Vault::open(Vault::derive('another-secret-another-secret-12'), $sealed), 'another key');
        $this->assertNull(Vault::open($key, null));
        $this->assertNull(Vault::open($key, 'not base64 at all!'));
        $this->assertNull(Vault::open($key, base64_encode('short')), 'too short for a nonce');
        $this->assertNull(Vault::open($key, substr($sealed, 0, -4) . 'AAAA'), 'a damaged box');
    }

    public function testDeriveIsThirtyTwoStableBytes(): void
    {
        $this->assertSame(32, strlen(Vault::derive('s')));
        $this->assertSame(Vault::derive('s'), Vault::derive('s'));
        $this->assertNotSame(Vault::derive('s'), Vault::derive('t'));
    }

    /** A new secret setting must be listed here (and in Vault::SETTINGS) or it stays readable in the database and in backups. */
    public function testSecretSettingsAreKnown(): void
    {
        $secretFields = [];
        foreach ((new \ReflectionClass(Settings::class))->getReflectionConstant('FIELDS')->getValue() as $fields) {
            foreach ($fields as $key => $type) {
                if (str_starts_with((string) $type, 'secret')) {
                    $secretFields[] = $key;
                }
            }
        }
        sort($secretFields);
        $this->assertSame(['ai_key', 'backup_password', 'captcha_secret', 'newsletter_key', 'smtp_password'], $secretFields, 'the settings of type secret');
        $this->assertSame([], array_values(array_diff($secretFields, Vault::SETTINGS)), 'every settings field of type secret is sealed');
        $defaults = array_keys(\Talea\Core\Settings::DEFAULTS);
        $this->assertSame(['newsletter_key'], array_values(array_diff(Vault::SETTINGS, $defaults)), 'only the key of the mailing service has no default');
        foreach (Vault::SETTINGS as $key) {
            $this->assertFalse(SiteExport::exportsSetting($key), $key . ' is not exported');
        }
    }
}
