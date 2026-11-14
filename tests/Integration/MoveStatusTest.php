<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\MoveStatus;
use Talea\Core\Settings;
use Talea\Core\StarterPages;
use Talea\Tests\Support\DatabaseTestCase;

#[CoversClass(MoveStatus::class)]
final class MoveStatusTest extends DatabaseTestCase
{
    public function testASiteNothingWasImportedToHasNoMoveStatus(): void
    {
        $this->assertNull(MoveStatus::status($this->db(), new Settings($this->db()), null));
    }

    public function testTheStatusCountsWhatIsLeftToDo(): void
    {
        $db = $this->db();
        $home = $db->insert('pages', ['slug' => 'home', 'title' => 'Home', 'text' => '']);
        $contact = $db->insert('pages', ['slug' => 'contact', 'title' => 'Contact', 'text' => '']);
        $db->insert('settings', ['name' => 'home_page', 'value' => (string) $home]);
        $db->insert('settings', ['name' => StarterPages::SETTING, 'value' => StarterPages::record($db, [$home, $contact])]);
        $db->insert('pages', ['slug' => 'about', 'title' => 'About', 'text' => '', 'import_source' => 'https://old.example/about/', 'layout_pending' => 1]);
        $db->insert('pages', ['slug' => 'team', 'title' => 'Team', 'text' => '', 'import_source' => 'https://old.example/team/', 'layout_pending' => 0]);
        $db->insert('redirects', ['from_path' => 'old', 'to_path' => 'about', 'match_type' => 'exact', 'type' => 301, 'active' => true, 'created_at' => date('Y-m-d H:i:s')]);

        $status = MoveStatus::status($db, new Settings($db), null);
        $this->assertSame([2, 1, 1, 1, 0, null], [$status['pages_imported'], $status['layout_pending'], $status['starter_pages_left'], $status['redirects'], $status['forms'], $status['report']]);

        $report = ['web' => 'https://old.example', 'phase' => 'done', 'completed' => '2026-11-20 10:00:00', 'summary' => ['failed' => 0, 'warnings' => 2, 'unreachable' => false]];
        $latest = MoveStatus::status($db, new Settings($db), $report)['report'];
        $this->assertSame([false, true, 2], [$latest['clean'], $latest['reachable'], $latest['warnings']]);
        $this->assertTrue(MoveStatus::status($db, new Settings($db), ['summary' => ['failed' => 0, 'warnings' => 0], 'phase' => 'done'] + $report)['report']['clean']);
        $this->assertNotNull(MoveStatus::status($this->db(), new Settings($db), $report), 'a report alone makes a status');
    }
}
