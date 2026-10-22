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
        $this->assertPage('/admin.php?module=redirects', 200, [
            '<section class="attention" aria-labelledby="not-found">', '/gone-for-good', 'aria-label="Ignore: /gone-for-good"', 'action=ignore_all',
        ], message: 'redirects: the 404 addresses wait above the list');
        $this->assertPageLacks('/admin.php?module=redirects', 'id="edit"', message: 'the form shows only when adding or editing');
        $this->assertPage('/admin.php?module=redirects&new=1', 200, '<details class="panel-collapsed" id="edit" open>', message: 'New redirect opens the form');
    }

    public function testSchedulesHaveASwitch(): void
    {
        $this->adminPost('/admin.php?module=schedules&action=save', ['id' => 0, 'name' => 'Monday review', 'task' => 'review', 'text' => '', 'cadence' => 'weekly', 'weekday' => 1, 'monthday' => 1, 'time' => '07:00', 'active' => 1], '/admin.php?module=schedules&action=edit');
        $this->assertPage('/admin.php?module=schedules', 200, ['role="switch" aria-checked="true" aria-label="Monday review on"', 'class="status status-draft"'], message: 'schedules: an on/off switch and the last run as a status');
    }
}
