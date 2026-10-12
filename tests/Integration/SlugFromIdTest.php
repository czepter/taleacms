<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Slug;
use Talea\Tests\Support\DatabaseTestCase;

/** A row created with the "n-a" slug of a title nothing could be transliterated from takes its type and number; real slugs never change. */
#[CoversClass(Slug::class)]
final class SlugFromIdTest extends DatabaseTestCase
{
    public function testAPageWithNothingToTransliterateGetsItsNumber(): void
    {
        $id = $this->db()->insert('pages', ['slug' => slugify('עברית'), 'title' => 'עברית', 'text' => '']);
        $this->assertSame('page-' . $id, Slug::fromId($this->db(), 'pages', $id));
        $this->assertSame('page-' . $id, $this->db()->value('SELECT slug FROM {pages} WHERE page_id = ?', [$id]));
    }

    public function testAnExistingSlugNeverChanges(): void
    {
        $id = $this->db()->insert('pages', ['slug' => slugify('صفحة الخدمات'), 'title' => 'x', 'text' => '']);
        $this->assertSame('sfha-alkhdmat', Slug::fromId($this->db(), 'pages', $id));
        $this->assertSame('sfha-alkhdmat', $this->db()->value('SELECT slug FROM {pages} WHERE page_id = ?', [$id]));
    }

    public function testASecondN_aIsRenamedToo(): void
    {
        $this->db()->insert('categories', ['name' => '你好', 'slug' => 'n-a', 'description' => '']);
        $id = $this->db()->insert('categories', ['name' => '世界', 'slug' => 'n-a-2', 'description' => '']);
        $this->assertSame('category-' . $id, Slug::fromId($this->db(), 'categories', $id));
    }
}
