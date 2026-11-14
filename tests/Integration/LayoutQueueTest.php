<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\LayoutQueue;
use Talea\Tests\Support\DatabaseTestCase;

#[CoversClass(LayoutQueue::class)]
final class LayoutQueueTest extends DatabaseTestCase
{
    public function testTheQueueListsMarkedPagesFiftyAtATime(): void
    {
        $db = $this->db();
        for ($i = 1; $i <= 55; $i++) {
            $db->insert('pages', ['slug' => 'old-' . $i, 'title' => 'Old ' . $i, 'text' => '', 'layout_pending' => 1, 'import_source' => 'https://old.example/old-' . $i . '/', 'sort_order' => $i]);
        }
        $db->insert('pages', ['slug' => 'made-here', 'title' => 'Made here', 'text' => '']);
        $db->insert('pages', ['slug' => 'in-trash', 'title' => 'In the trash', 'text' => '', 'layout_pending' => 1, 'deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(55, LayoutQueue::count($db));
        $first = LayoutQueue::page($db, 0);
        $this->assertCount(50, $first['pages']);
        $this->assertSame(55, $first['total']);
        $this->assertSame('https://old.example/old-1/', $first['pages'][0]['old_address']);
        $this->assertCount(5, LayoutQueue::page($db, 50)['pages']);
    }

    public function testTickingOffChangesNothingButTheMarker(): void
    {
        $db = $this->db();
        $id = $db->insert('pages', ['slug' => 'about', 'title' => 'About', 'text' => '<p>Text</p>', 'build' => '{"v":1,"children":[]}', 'layout_pending' => 1, 'updated_at' => '2026-01-02 03:04:05']);
        $before = $db->one('SELECT build, text, updated_at, build_draft FROM {pages} WHERE page_id = ?', [$id]);

        $this->assertSame(1, LayoutQueue::done($db, [$id]));
        $this->assertSame($before, $db->one('SELECT build, text, updated_at, build_draft FROM {pages} WHERE page_id = ?', [$id]));
        $this->assertSame(0, (int) $db->value('SELECT layout_pending FROM {pages} WHERE page_id = ?', [$id]));
        $this->assertSame(0, LayoutQueue::done($db, [$id]), 'a page that is done is not counted again');
    }
}
