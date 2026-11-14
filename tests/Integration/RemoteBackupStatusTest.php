<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\RemoteBackup;
use Talea\Core\Settings;
use Talea\Tests\Support\DatabaseTestCase;

#[CoversClass(RemoteBackup::class)]
final class RemoteBackupStatusTest extends DatabaseTestCase
{
    private function settings(): Settings
    {
        $s = new Settings($this->db());
        $s->set('backup_password', 'pa55w0rd-secret');
        $s->set('backup_user', 'uploaduser');

        return $s;
    }

    public function testEveryPartOfAnOldStatusIsCleanedAndCleaningTwiceChangesNothing(): void
    {
        $s = $this->settings();
        $old = '2026-01-01 03:00|Login failed for uploaduser | password pa55w0rd-secret | at ftp://u:p@nas.example/x|and a.b@example.com';
        $clean = RemoteBackup::cleanStatus($s, $old);

        $this->assertStringStartsWith('2026-01-01 03:00|', $clean);
        foreach (['uploaduser', 'pa55w0rd-secret', 'u:p@', 'a.b@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $clean);
        }
        $this->assertSame(1, substr_count($clean, '|'), 'one bar remains: after the date');
        $this->assertSame($clean, RemoteBackup::cleanStatus($s, $clean));
    }

    public function testTheMediaStatusKeepsItsNumberAndAnOkStatusIsNotTouched(): void
    {
        $s = $this->settings();
        $this->assertSame('2026-01-01 03:00|ok', RemoteBackup::cleanStatus($s, '2026-01-01 03:00|ok'));
        $this->assertSame('2026-01-01 03:00|ok|4', RemoteBackup::cleanStatus($s, '2026-01-01 03:00|ok|4'));
        $this->assertSame('', RemoteBackup::cleanStatus($s, ''));
        $media = RemoteBackup::cleanStatus($s, '2026-01-01 03:00|bad pa55w0rd-secret | here|12');
        $this->assertStringNotContainsString('pa55w0rd-secret', $media);
        $this->assertStringEndsWith('|12', $media, 'the number Talea wrote stays');
        $this->assertSame(2, substr_count($media, '|'));
    }
}
