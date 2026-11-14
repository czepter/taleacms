<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\RemoteBackup;

#[CoversClass(RemoteBackup::class)]
final class RemoteBackupFailureTextTest extends TestCase
{
    public function testSecretsAreMaskedAlsoWhenTheTextIsSqueezed(): void
    {
        $text = "Login failed for AKIDTEST\nwith pa|ss  word9 and pa%7Css%20%20word9 (again: PA|SS  WORD9)\n";
        $clean = RemoteBackup::failureText($text, 'pa|ss  word9', 'AKIDTEST');
        $this->assertStringNotContainsString('AKIDTEST', $clean);
        $this->assertStringNotContainsStringIgnoringCase('word9', $clean);
        $this->assertStringNotContainsString('pa ss word9', $clean, 'the collapsed form goes too');
        $this->assertStringNotContainsString("\n", $clean);
    }

    public function testCredentialsInAnAddressEmailsAndTheInstallFolderGo(): void
    {
        $clean = RemoteBackup::failureText('Cannot reach ftp://u:p@nas.example/x for a.b@example.com in ' . TALEA_ROOT . '/storage/backups/x');
        $this->assertStringNotContainsString('u:p@', $clean);
        $this->assertStringContainsString('ftp://nas.example/x', $clean);
        $this->assertStringNotContainsString('a.b@example.com', $clean);
        $this->assertStringNotContainsString(TALEA_ROOT, $clean);
        $this->assertStringContainsString('storage/backups/x', $clean);
    }

    public function testOneLineWithoutBarsAndCapped(): void
    {
        $clean = RemoteBackup::failureText(str_repeat("word | line\n", 60));
        $this->assertStringNotContainsString('|', $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertSame(RemoteBackup::FAILURE_LENGTH, mb_strlen($clean));
        $this->assertStringEndsWith('…', $clean);
    }

    public function testIdempotent(): void
    {
        $secrets = ['pa|ss  word9', 'AKIDTEST'];
        foreach ([
            "Login failed for AKIDTEST\nwith pa|ss  word9",
            'Cannot reach ftp://u:p@nas.example/x for a.b@example.com in ' . TALEA_ROOT . '/storage/backups/x',
            str_repeat("word | line\n", 60),
        ] as $text) {
            $once = RemoteBackup::failureText($text, ...$secrets);
            $this->assertSame($once, RemoteBackup::failureText($once, ...$secrets));
        }
    }

    public function testShortSecretsAndOkStayAlone(): void
    {
        $this->assertSame('bad abc here', RemoteBackup::failureText('bad abc here', 'abc'));
        $this->assertSame('ok', RemoteBackup::failureText('ok', 'secret-value'));
    }
}
