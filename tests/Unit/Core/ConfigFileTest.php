<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Talea\Core\Config;

final class ConfigFileTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/talea-config-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->file, "<?php\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testAFileEveryoneCanReadIsFoundAndRestricted(): void
    {
        chmod($this->file, 0644);
        $this->assertTrue(Config::readableByEveryone($this->file));
        $this->assertTrue(Config::protectFile($this->file), 'the fix reports success');
        $this->assertSame(0600, fileperms($this->file) & 0777, 'only the owner reads and writes config.php');
        $this->assertFalse(Config::readableByEveryone($this->file));
    }

    public function testAProtectedFileStaysAsItIsAndAMissingFileIsNotProtected(): void
    {
        chmod($this->file, 0640);
        $this->assertTrue(Config::protectFile($this->file));
        $this->assertSame(0640, fileperms($this->file) & 0777, 'a file the others cannot read is not touched');
        $this->assertFalse(Config::protectFile($this->file . '.missing'));
        $this->assertFalse(Config::readableByEveryone($this->file . '.missing'));
    }

    public function testTheInstallerRestrictsTheFileItWrites(): void
    {
        $source = (string) file_get_contents(TALEA_SYSTEM . '/src/Install/Installer.php');
        $this->assertMatchesRegularExpression('#chmod\(TALEA_ROOT \. \'/config\.php\', Config::FILE_MODE\)#', $source);
    }
}
