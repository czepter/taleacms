<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\EnglishInstall;

use Kaleta\Tests\Site\Support\CzechCheck;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** English install of the business starter: the public site without Czech, the privacy policy and a site without news. */
#[Group('site')]
final class BusinessStarterTest extends SiteTestCase
{
    use BusinessInstall;
    use CzechCheck;

    public function testInstallerFinishedScreenHasNoCzechAndTheClaudeAddress(): void
    {
        $done = $this->site()->installerResponse;
        $this->assertNoCzech($done->body, 'installer: finished (business)');
        $this->assertStringContainsString('<code>' . $this->site()->base . '/mcp</code>', $done->body, 'installer: Claude address after installing');
    }

    public function testPrivacyPolicyIsInEnglishAndHiddenUntilCompleted(): void
    {
        $row = $this->site()->rows("SELECT title, visible, text LIKE '%This policy explains%' AS english FROM ka_pages WHERE slug = 'privacy-policy'")[0] ?? [];
        $this->assertSame(['Privacy policy', 0, 1], [$row['title'] ?? null, (int) ($row['visible'] ?? -1), (int) ($row['english'] ?? -1)], 'privacy policy page after an English install');
    }

    public function testPublicSite(): void
    {
        $this->walkPublicSite('business');
    }

    #[Depends('testPublicSite')]
    public function testNewsWithoutNewsItems(): void
    {
        $this->site()->exec('UPDATE ka_news SET visible = 0');
        $this->site()->clearPageCache();
        $this->assertCzechFree('/news', 200, label: 'business: news without news items');
    }
}
