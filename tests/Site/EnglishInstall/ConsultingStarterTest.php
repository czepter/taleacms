<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

use Talea\Tests\Site\Support\CzechCheck;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** English install of the consulting starter with every extension: no Czech on the public site. */
#[Group('site')]
final class ConsultingStarterTest extends SiteTestCase
{
    use CzechCheck;
    use PublicSiteWalk;

    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'web' => 'consulting', 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'extensions' => self::ALL, 'enabledExtensions' => implode(',', self::ALL)];
    }

    public function testInstallerFinishedScreenHasNoCzechAndTheClaudeAddress(): void
    {
        $done = $this->site()->installerResponse;
        $this->assertNoCzech($done->body, 'installer: finished (consulting)');
        $this->assertStringContainsString('<code>' . $this->site()->base . '/mcp</code>', $done->body, 'installer: Claude address after installing');
    }

    public function testPublicSite(): void
    {
        $this->walkPublicSite('consulting');
    }
}
