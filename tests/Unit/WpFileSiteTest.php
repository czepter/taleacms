<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\WpFile;

final class WpFileSiteTest extends TestCase
{
    protected function tearDown(): void
    {
        WpFile::useSite('');
    }

    public function testFurtherSiteGetsItsOwnImportFolder(): void
    {
        $this->assertSame(WpFile::FOLDER, WpFile::dir());
        WpFile::useSite('3b9a1c0e-7d52-4f0b-9a1e-2c6f5d8e4a11');
        $this->assertSame(WpFile::FOLDER . '/site-3b9a1c0e-7d52-4f0b-9a1e-2c6f5d8e4a11', WpFile::dir());
        $this->assertSame('/site-3b9a1c0e-7d52-4f0b-9a1e-2c6f5d8e4a11', WpFile::sitePart());
    }

    public function testAnythingButAPublicIdFallsBackToTheInstallFolder(): void
    {
        WpFile::useSite('../../etc');
        $this->assertSame(WpFile::FOLDER, WpFile::dir());
    }
}
