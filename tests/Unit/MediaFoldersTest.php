<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\MediaFolders;

/** The pure helpers of the folder tree: children, path, descendants, tree. */
final class MediaFoldersTest extends TestCase
{
    /** Weddings(1) > Castle(2) > Details(3); Weddings(1) > Church(4); Press(5) @return list<array<string, mixed>> */
    private function folders(): array
    {
        $f = fn (int $id, string $name, ?int $parent): array => ['folder_id' => $id, 'name' => $name, 'parent_id' => $parent, 'count' => 0];

        return [$f(2, 'Castle', 1), $f(4, 'Church', 1), $f(3, 'Details', 2), $f(5, 'Press', null), $f(1, 'Weddings', null)];
    }

    public function testChildrenAndTree(): void
    {
        $all = $this->folders();
        $this->assertSame([5, 1], array_column(MediaFolders::children($all, null), 'folder_id'));
        $this->assertSame([2, 4], array_column(MediaFolders::children($all, 1), 'folder_id'));
        $tree = MediaFolders::tree($all);
        $this->assertSame([5, 1, 2, 3, 4], array_column($tree, 'folder_id'));
        $this->assertSame([0, 0, 1, 2, 1], array_column($tree, 'depth'));
    }

    public function testPathAndDescendants(): void
    {
        $all = $this->folders();
        $this->assertSame(['Weddings', 'Castle', 'Details'], array_column(MediaFolders::path($all, 3), 'name'));
        $this->assertSame(['Press'], array_column(MediaFolders::path($all, 5), 'name'));
        $this->assertSame([], MediaFolders::path($all, 99));
        $this->assertEqualsCanonicalizing([2, 4, 3], MediaFolders::descendants($all, 1));
        $this->assertSame([], MediaFolders::descendants($all, 3));
    }

    public function testBrokenCycleDoesNotLoopForever(): void
    {
        $all = [['folder_id' => 1, 'name' => 'A', 'parent_id' => 2], ['folder_id' => 2, 'name' => 'B', 'parent_id' => 1]];
        $this->assertCount(2, MediaFolders::path($all, 1));
        $this->assertEqualsCanonicalizing([2, 1], MediaFolders::descendants($all, 1));
    }
}
