<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Db;
use Talea\Core\Dialect\Dialect;
use Talea\Tests\Support\DatabaseTestCase;

/** The dialect helpers executed on the engine the suite runs against (MySQL 8 or PostgreSQL, TALEA_TEST_DB_DRIVER). */
#[CoversClass(Dialect::class)]
#[CoversClass(Db::class)]
final class DialectTest extends DatabaseTestCase
{
    public function testTheDbReportsItsEngine(): void
    {
        $this->assertSame($this->isPostgres() ? 'pgsql' : 'mysql', $this->db()->dialect()->name());
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $db = $this->db();
        $db->upsert('settings', ['name' => 'dialect_test', 'value' => 'one'], ['name']);
        $db->upsert('settings', ['name' => 'dialect_test', 'value' => 'two'], ['name']);

        $this->assertSame('two', $db->value("SELECT value FROM {settings} WHERE name = 'dialect_test'"));
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM {settings} WHERE name = 'dialect_test'"));
    }

    public function testUpsertWithNothingToUpdateKeepsTheRow(): void
    {
        $db = $this->db();
        $db->upsert('settings', ['name' => 'dialect_keep', 'value' => 'first'], ['name']);
        $db->upsert('settings', ['name' => 'dialect_keep', 'value' => 'second'], ['name'], []);

        $this->assertSame('first', $db->value("SELECT value FROM {settings} WHERE name = 'dialect_keep'"));
    }

    public function testInsertIgnoreSkipsADuplicate(): void
    {
        $db = $this->db();

        $this->assertTrue($db->insertIgnore('settings', ['name' => 'dialect_ignore', 'value' => 'a']));
        $this->assertFalse($db->insertIgnore('settings', ['name' => 'dialect_ignore', 'value' => 'b']));
        $this->assertSame('a', $db->value("SELECT value FROM {settings} WHERE name = 'dialect_ignore'"));
    }

    public function testJsonExtractReadsNestedKeysAndListItems(): void
    {
        $db = $this->db();
        $db->upsert('settings', ['name' => 'dialect_json', 'value' => '{"a":{"b":"cafe","n":[10,20]},"x":null}'], ['name']);
        $read = fn (string $path): mixed => $db->value('SELECT ' . $db->dialect()->jsonExtract('value', $path) . " FROM {settings} WHERE name = 'dialect_json'");

        $this->assertSame('cafe', $read('$.a.b'));
        $this->assertSame('20', (string) $read('a.n[1]'));
        $this->assertNull($read('$.missing'));
    }

    public function testIntervalArithmeticComparesWithDatetimeColumns(): void
    {
        $db = $this->db();
        $d = $db->dialect();
        $db->insert('ip_checks', ['ip' => '10.0.0.1', 'type' => 'old', 'checked_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $db->insert('ip_checks', ['ip' => '10.0.0.2', 'type' => 'new', 'checked_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->assertSame(['new'], array_column($db->all('SELECT type FROM {ip_checks} WHERE checked_at > ' . $d->now() . ' - ' . $d->interval(1, 'hour')), 'type'));
        $this->assertSame(['old'], array_column($db->all('SELECT type FROM {ip_checks} WHERE checked_at < ' . $d->now() . ' - ' . $d->interval(90, 'minute')), 'type'));
        $this->assertEqualsWithDelta(time(), (int) $db->value('SELECT ' . $d->unixTime($d->now())), 5, 'the session time zone matches PHP');
    }

    public function testGroupConcatJoinsTheValuesOfAGroup(): void
    {
        $db = $this->db();
        $db->insert('tags', ['name' => 'b', 'slug' => 'tb', 'description' => '']);
        $db->insert('tags', ['name' => 'a', 'slug' => 'ta', 'description' => '']);

        $this->assertSame('a, b', $db->value('SELECT ' . $db->dialect()->groupConcat('name', ', ', 'name') . ' FROM {tags}'));
    }

    public function testLimitWithOffset(): void
    {
        $db = $this->db();
        foreach (['a', 'b', 'c'] as $slug) {
            $db->insert('tags', ['name' => $slug, 'slug' => $slug, 'description' => '']);
        }

        $this->assertSame(['b'], array_column($db->all('SELECT slug FROM {tags} ORDER BY slug ' . $db->dialect()->limit(1, 1)), 'slug'));
    }

    public function testInsertReturnsTheNewKey(): void
    {
        $db = $this->db();
        $first = $db->insert('tags', ['name' => 'k1', 'slug' => 'k1', 'description' => '']);
        $second = $db->insert('tags', ['name' => 'k2', 'slug' => 'k2', 'description' => '']);
        $check = $db->insert('ip_checks', ['ip' => '10.0.0.3', 'type' => 'x', 'checked_at' => date('Y-m-d H:i:s')]); // a table that is not in PRIMARY_KEYS

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first + 1, $second);
        $this->assertGreaterThan(0, $check);
        $this->assertSame(0, $db->insert('settings', ['name' => 'dialect_nokey', 'value' => '1']), 'a table without an auto-numbered key');
    }

    public function testLikeInsensitiveIgnoresCaseAndAccents(): void
    {
        $db = $this->db();
        $db->insert('tags', ['name' => 'Café Crème', 'slug' => 'cafe', 'description' => '']);

        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {tags} WHERE ' . $db->dialect()->likeInsensitive('name'), ['%CAFE CREME%']));
    }

    public function testFulltextPredicateRuns(): void
    {
        $db = $this->db();
        $d = $db->dialect();
        $category = $db->insert('categories', ['name' => 'N', 'slug' => 'n', 'description' => '']);
        $db->insert('news', ['slug' => 'ft', 'title' => 'Hello', 'intro' => '', 'text' => '', 'category_id' => $category, 'published_at' => date('Y-m-d H:i:s'), 'search_text' => 'cafe creme world']);
        $found = (int) $db->value('SELECT COUNT(*) FROM {news} WHERE ' . $d->fulltextMatch(['search_text']), [$d->fulltextQuery(['caf', 'wor'])]);

        // InnoDB indexes rows when they are committed, so inside the test's transaction MySQL finds nothing yet; PostgreSQL sees the row
        $this->assertSame($this->isPostgres() ? 1 : 0, $found);
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {news} WHERE ' . $d->fulltextMatch(['search_text']), [$d->fulltextQuery(['zzz'])]));
    }

    public function testALockIsHeldByOneConnectionAtATime(): void
    {
        $first = $this->db();
        $second = Db::fromConfig($this->dbConfig());

        $this->assertTrue($first->lock('talea-dialect-test', 0));
        $this->assertFalse($second->lock('talea-dialect-test', 0), 'another connection cannot take it');
        $first->unlock('talea-dialect-test');
        $this->assertTrue($second->lock('talea-dialect-test', 0), 'free again after unlock');
        $second->unlock('talea-dialect-test');
    }

    public function testTableExistsAndDatabaseName(): void
    {
        $this->assertTrue($this->db()->tableExists('users'));
        $this->assertFalse($this->db()->tableExists('no_such_table'));
        $this->assertStringStartsWith('talea_phpunit_', $this->db()->databaseName());
    }

    public function testUpsertWithExpressionsCountsOnTheExistingRow(): void
    {
        $db = $this->db();
        $day = date('Y-m-d');
        $update = ['views' => '{old.views} + {new.views}'];
        $db->upsert('stats_pages', ['day' => $day, 'path' => '/dialect', 'views' => 1], ['day', 'path'], $update);
        $db->upsert('stats_pages', ['day' => $day, 'path' => '/dialect', 'views' => 4], ['day', 'path'], $update);
        $db->upsert('stats_pages', ['day' => $day, 'path' => '/dialect', 'views' => 1], ['day', 'path'], ['views' => '{old.views} + 1']);

        $this->assertSame(6, (int) $db->value('SELECT views FROM {stats_pages} WHERE day = ? AND path = ?', [$day, '/dialect']));
    }

    public function testInsertIgnoreSelectAddsOnlyWhatIsNew(): void
    {
        $db = $this->db();
        $db->upsert('settings', ['name' => 'dialect_sel_a', 'value' => '1'], ['name']);
        $db->upsert('settings', ['name' => 'dialect_sel_b', 'value' => '2'], ['name']);
        $db->run("DELETE FROM {settings} WHERE name = 'dialect_sel_copy'");
        $select = "SELECT 'dialect_sel_copy', value FROM {settings} WHERE name IN ('dialect_sel_a', 'dialect_sel_b') ORDER BY name";

        $this->assertSame(1, $db->insertIgnoreSelect('settings', ['name', 'value'], $select), 'the first row is added, the second one has the same key');
        $this->assertSame('1', $db->value("SELECT value FROM {settings} WHERE name = 'dialect_sel_copy'"));
        $this->assertSame(0, $db->insertIgnoreSelect('settings', ['name', 'value'], $select));
    }

    public function testTheCatalogueHelpersListColumnsAndKeys(): void
    {
        $db = $this->db();

        $this->assertSame(['day', 'path', 'views'], array_slice($db->columns('stats_pages'), 0, 3));
        $this->assertSame(['day', 'path'], $db->primaryKey('stats_pages'));
        $this->assertContains(['name'], array_values($db->uniqueKeys('settings')), 'the primary key is a unique key');
        $this->assertSame([], $db->primaryKey('no_such_table'));
        $this->assertGreaterThan(0, $db->tablesSize());
    }

    public function testListPositionSortsByTheGivenOrder(): void
    {
        $db = $this->db();
        foreach (['dialect_pos_a', 'dialect_pos_b', 'dialect_pos_c'] as $name) {
            $db->upsert('settings', ['name' => $name, 'value' => $name], ['name']);
        }
        $order = ['dialect_pos_c', 'dialect_pos_a', 'dialect_pos_b'];
        $names = $db->all("SELECT name FROM {settings} WHERE name LIKE 'dialect\\_pos\\_%' ORDER BY " . $db->dialect()->listPosition('name', 3), $order);

        $this->assertSame($order, array_column($names, 'name'));
    }

    public function testBooleansComeBackAsNumbersOnBothEngines(): void
    {
        $db = $this->db();
        $db->insert('pages', ['slug' => 'bool-page', 'title' => 'B', 'text' => '', 'visible' => 1]);
        $this->assertSame(1, $db->one("SELECT visible FROM {pages} WHERE slug = 'bool-page'")['visible'], 'a boolean is 1, not true');
        $this->assertSame(1, $db->value("SELECT visible FROM {pages} WHERE slug = 'bool-page'"));
        $this->assertSame([[1]], $db->run("SELECT visible FROM {pages} WHERE slug = 'bool-page'")->fetchAll(\PDO::FETCH_NUM), 'for every way of fetching');
        $db->run("UPDATE {pages} SET visible = FALSE WHERE slug = 'bool-page'");
        $this->assertSame(0, $db->value("SELECT visible FROM {pages} WHERE slug = 'bool-page'"), 'false is 0, and not "no row"');
        $this->assertNull($db->value("SELECT visible FROM {pages} WHERE slug = 'no-such-page'"));
        $this->assertSame(1, (int) $db->value('SELECT SUM(CASE WHEN visible THEN 1 ELSE 0 END) + 1 FROM {pages} WHERE slug = ?', ['bool-page']));
    }

    public function testEmptyTablesIgnoresForeignKeysAndTheSequenceCatchesUp(): void
    {
        $db = $this->db();
        $category = $db->insert('categories', ['name' => 'E', 'slug' => 'empty-me', 'description' => '']);
        $db->insert('news', ['slug' => 'empty-news', 'title' => 'T', 'intro' => '', 'text' => '', 'category_id' => $category, 'published_at' => date('Y-m-d H:i:s')]);

        $db->emptyTables(['news', 'categories']);
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {news}'));
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {categories}'));

        // rows with their own numbers (an import, a restore), then the counter moves past them
        $db->run("INSERT INTO {categories} (category_id, name, slug, description, public_id) VALUES (500, 'X', 'x500', '', ?)", [\Talea\Core\Uuid::v4()]);
        $db->syncSequences('categories');
        $this->assertGreaterThan(500, $db->insert('categories', ['name' => 'Y', 'slug' => 'y501', 'description' => '']));
    }
}
