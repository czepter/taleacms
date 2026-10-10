<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

use Talea\Tests\Site\FormsLook\SiteHelpers;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** #24: a look picked in the installer replaces the starter site's style and brings its header and footer. */
#[Group('site')]
final class LookInstallTest extends SiteTestCase
{
    use SiteHelpers;

    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'web' => 'business', 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'installerFields' => ['look' => 'meadow']];
    }

    public function testTheChosenLookIsInstalled(): void
    {
        $this->assertSame('1|1|1', $this->sql("SELECT value LIKE '%lib:lora%' AND value LIKE '%#3f6b4f%' FROM tl_settings WHERE name = 'design_system'")
            . '|' . $this->sql("SELECT build LIKE '%\"navigation\"%' FROM tl_site_parts WHERE type = 'header' AND language = ''")
            . '|' . $this->sql("SELECT build IS NOT NULL FROM tl_site_parts WHERE type = 'footer' AND language = ''"));
        $this->assertStringContainsString('<header', $this->site()->client()->get('/')->body);
    }
}
