<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** HF-12: the in-app updater is switched off – no update UI, no update item in the health check, no channel check, no install. */
#[Group('site')]
final class UpdaterOffTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['language' => 'en', 'siteName' => 'Acme', 'doneText' => 'Done, your website is running'];
    }

    public function testTheBackupsTabHasNoUpdateSection(): void
    {
        $page = $this->assertPage('/admin.php?module=settings&tab=backups', 200, 'Backups');

        $this->assertStringNotContainsString('System update', $page->body);
        $this->assertStringNotContainsString('Check now', $page->body);
        $this->assertStringNotContainsString('update_url', $page->body);
    }

    public function testTheHealthCheckReportsNoUpdateItem(): void
    {
        $this->assertPageLacks('/admin.php?module=status', ['no update source is set', 'update source is not responding', 'is available (Settings']);
    }

    public function testCheckAndInstallDoNothing(): void
    {
        $this->site()->setting('update_url', 'http://127.0.0.1:1/update.json');
        $this->adminPost('/admin.php?module=settings&action=check', [], '/admin.php?module=settings&tab=backups');
        $this->adminPost('/admin.php?module=settings&action=update', ['version' => '99.0.0'], '/admin.php?module=settings&tab=backups');

        $this->assertSame('', $this->site()->settingValue('update_cache'), 'no channel check was made, so nothing is cached');
        $this->assertStringNotContainsString('99.0.0', $this->site()->settingValue('update_cache'));
        $this->assertNotContains('updated', array_column($this->site()->rows("SELECT type FROM tl_events WHERE type LIKE 'update.%'"), 'type'));
    }
}
