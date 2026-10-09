<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\EnglishInstall;

use Kaleta\Tests\Site\Support\CzechCheck;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** English install of the crafts starter without the Forms extension: no Czech on the public site. */
#[Group('site')]
final class CraftsStarterTest extends SiteTestCase
{
    use CzechCheck;
    use PublicSiteWalk;

    protected static function siteOptions(): array
    {
        return ['web' => 'remeslo', 'language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running', 'extensions' => ['novinky', 'statistika', 'presmerovani'], 'enabledExtensions' => 'novinky,statistika,presmerovani'];
    }

    public function testInstallerFinishedScreenHasNoCzech(): void
    {
        $this->assertNoCzech($this->site()->installerResponse->body, 'installer: finished (remeslo)');
    }

    public function testPublicSite(): void
    {
        $this->walkPublicSite('remeslo');
    }

    public function testContactPageWithoutFormsHasContactDetails(): void
    {
        $this->assertPage('/contact', 200, 'Contact details', $this->site()->client('visitor'), 'remeslo: the Contact page without Forms has no contact details');
    }
}
