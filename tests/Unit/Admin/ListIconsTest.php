<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class ListIconsTest extends TestCase
{
    /** docs/specs/admin-lists.md: the row actions, toolbar and head draw these icons */
    public function testActionIconsExist(): void
    {
        $icon = require TALEA_ROOT . '/system/views/admin/icons.php';
        $missing = [];
        foreach (['edit', 'more', 'check', 'out', 'home', 'b-search', 'plus', 'close', 'upload', 'download', 'subpage', 'copy', 'trash', 'restore', 'eye', 'attachment', 'shield', 'layers'] as $key) {
            if (!str_contains($icon($key), '<svg') || ($key !== 'article' && $icon($key) === $icon('article'))) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'every list icon has its own drawing, not the fallback');
    }
}
