<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Redirects and schedules in the shared list design (docs/specs/admin-lists.md). */
#[Group('site')]
final class ToolListsTest extends SiteTestCase
{
    public function testNotFoundAddressesWaitInTheAttentionBox(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_not_found (path, count, last_seen_at) VALUES ('gone-for-good', 4, NOW())");
        $this->assertPage('/admin.php?module=missing_pages', 200, [
            '/gone-for-good', 'aria-label="Ignore: /gone-for-good"', '/admin/missing_pages/ignore_all',
        ], message: 'missing pages: the 404 addresses wait in the list');
        $this->assertPageLacks('/admin.php?module=redirects', ['id="from_path"', '#edit'], message: 'the list carries no form');
        $this->assertPage('/admin.php?module=redirects&new=1', 200, ['class="navigation-row"', 'id="from_path"'], message: 'New redirect opens its own page');
        $this->assertPage('/admin.php?module=redirects&from=gone-for-good', 200, '>/gone-for-good</textarea>', message: 'an address from the 404 list fills in the old address');
        $this->assertPageLacks('/admin.php?module=redirects&new=1', 'form="redirect-delete"', message: 'nothing to delete yet');
        $this->assertPage('/admin.php?module=redirects&edit=00000000-0000-4000-8000-000000000000', 404, message: 'an unknown redirect');
    }

    public function testSchedulesHaveASwitch(): void
    {
        $this->adminPost('/admin.php?module=schedules&action=save', ['id' => 0, 'name' => 'Monday review', 'task' => 'review', 'text' => '', 'cadence' => 'weekly', 'weekday' => 1, 'monthday' => 1, 'time' => '07:00', 'active' => 1], '/admin.php?module=schedules&action=edit');
        $this->assertPage('/admin.php?module=schedules', 200, ['role="switch" aria-checked="true" aria-label="Monday review on"', 'class="status status-draft"'], message: 'schedules: an on/off switch and the last run as a status');
    }

    public function testA404RowWithASuggestionKeepsTheManualRedirectAndFullPaths(): void
    {
        $site = $this->site();
        $page = (string) $site->value('SELECT slug FROM tl_pages WHERE deleted_at IS NULL AND visible = TRUE AND slug <> \'\' ORDER BY page_id DESC LIMIT 1');
        $site->exec('INSERT INTO tl_not_found (path, count, last_seen_at) VALUES (?, 3, NOW())', [$page . '.html']);
        $list = $this->assertPage('/admin.php?module=missing_pages', 200, ['title="/' . $page . '.html"', 'Redirect</button>'], message: 'the 404 row shows the full path and the suggestion');
        $this->assertStringContainsString('name="to" value="/', $list->body, 'the suggestion fills the target, which can still be changed by hand');
    }
}
