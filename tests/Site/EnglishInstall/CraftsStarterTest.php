<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

use Talea\Tests\Site\Support\CzechCheck;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** English install of the crafts starter without the Forms extension: no Czech on the public site. */
#[Group('site')]
final class CraftsStarterTest extends SiteTestCase
{
    use CzechCheck;
    use PublicSiteWalk;

    protected static function siteOptions(): array
    {
        return ['freshInstall' => true, 'web' => 'crafts', 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'extensions' => ['news', 'stats', 'redirects'], 'enabledExtensions' => 'news,stats,redirects'];
    }

    public function testInstallerFinishedScreenHasNoCzech(): void
    {
        $this->assertNoCzech($this->site()->installerResponse->body, 'installer: finished (crafts)');
    }

    public function testPublicSite(): void
    {
        $this->walkPublicSite('crafts');
    }

    public function testContactPageWithoutFormsHasContactDetails(): void
    {
        $this->assertPage('/contact', 200, 'Contact details', $this->site()->client('visitor'), 'crafts: the Contact page without Forms has no contact details');
    }
}
