<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\LayoutQueue;

#[CoversClass(LayoutQueue::class)]
final class PageCopyTest extends TestCase
{
    public function testTheCopyOfAnImportedPageIsNotImportedItself(): void
    {
        $copy = LayoutQueue::withoutImportTraces(['title' => 'About', 'build' => '{"v":1,"children":[]}',
            'import_source' => 'https://old.example/about/', 'layout_pending' => 1]);
        $this->assertSame(['title' => 'About', 'build' => '{"v":1,"children":[]}'], $copy, 'no identity, no import source, not in the layout queue (both columns fall back to their defaults); Admin\Modules\Pages::actionDuplicate copies through it');
    }
}
