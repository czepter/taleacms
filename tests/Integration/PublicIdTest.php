<?php

declare(strict_types=1);

namespace Kaleta\Tests\Integration;

use Kaleta\Core\Db;
use Kaleta\Core\Uuid;
use Kaleta\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** HF-16: rows that are addressed from outside carry a unique UUID v4, filled by Db::insert and by the column default. */
#[CoversClass(Db::class)]
#[CoversClass(Uuid::class)]
final class PublicIdTest extends DatabaseTestCase
{
    public function testEveryListedTableHasAUniquePublicIdColumn(): void
    {
        foreach (Db::PUBLIC_ID_TABLES as $table) {
            $this->assertSame(1, (int) $this->db()->value(
                "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'public_id' AND non_unique = 0 AND index_name <> 'PRIMARY'",
                [$this->db()->prefix . $table],
            ), "$table: unique public_id");
        }
    }

    public function testInsertFillsAUuidV4(): void
    {
        $id = $this->db()->insert('categories', ['name' => 'A', 'slug' => 'a', 'description' => '']);
        $uuid = (string) $this->db()->value('SELECT public_id FROM {categories} WHERE category_id = ?', [$id]);

        $this->assertTrue(Uuid::valid($uuid), $uuid);
    }

    public function testARawInsertGetsAValidUniqueUuidFromTheColumnDefault(): void
    {
        $this->db()->run("INSERT INTO {categories} (name, slug, description) VALUES ('B', 'b', ''), ('C', 'c', '')");
        $uuids = array_column($this->db()->all("SELECT public_id FROM {categories} WHERE slug IN ('b', 'c')"), 'public_id');

        $this->assertCount(2, array_unique($uuids));
        foreach ($uuids as $uuid) {
            $this->assertTrue(Uuid::valid($uuid), (string) $uuid);
        }
    }

    public function testLookupByPublicIdRejectsMalformedInputBeforeSql(): void
    {
        $id = $this->db()->insert('tags', ['name' => 'T', 'slug' => 't', 'description' => '']);
        $uuid = (string) $this->db()->value('SELECT public_id FROM {tags} WHERE tag_id = ?', [$id]);

        $this->assertSame($id, (int) $this->db()->byPublicId('tags', $uuid)['tag_id']);
        $this->assertNull($this->db()->byPublicId('tags', "$id"), 'an integer id is not accepted');
        $this->assertNull($this->db()->byPublicId('tags', "' OR 1=1 --"));
        $this->assertNull($this->db()->byPublicId('settings', $uuid), 'a table without public ids');
    }

    public function testV4HasVersionAndVariantBits(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertTrue(Uuid::valid(Uuid::v4()));
        }
        $this->assertFalse(Uuid::valid('00000000-0000-1000-8000-000000000000'), 'v1 is not v4');
    }
}
