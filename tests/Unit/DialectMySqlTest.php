<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\Db;
use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\MySql;

/** The SQL the MySQL dialect writes (no database needed; DialectTest in tests/Integration executes it). */
#[CoversClass(MySql::class)]
#[CoversClass(Dialect::class)]
final class DialectMySqlTest extends TestCase
{
    private MySql $d;

    protected function setUp(): void
    {
        $this->d = new MySql();
    }

    public function testQuotesIdentifiersAndRejectsOthers(): void
    {
        $this->assertSame('`tl_news`', $this->d->quote('tl_news'));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->quote('a`; DROP TABLE x');
    }

    public function testDbExpandsTablePlaceholdersWithThePrefixAndTheQuote(): void
    {
        $db = new Db('mysql:host=x', '', '', 'web_');

        $this->assertSame('SELECT * FROM `web_news` JOIN `web_tags`', $db->sql('SELECT * FROM {news} JOIN {tags}'));
        $this->assertSame('mysql', $db->dialect()->name());
    }

    public function testUpsert(): void
    {
        $this->assertSame(
            'INSERT INTO {settings} (`name`, `value`) VALUES (?, ?) AS new_row ON DUPLICATE KEY UPDATE `value` = new_row.`value`',
            $this->d->upsert('settings', ['name', 'value'], ['name'], ['value']),
        );
        $this->assertSame(
            'INSERT INTO {t} (`a`, `b`) VALUES (?, ?) AS new_row ON DUPLICATE KEY UPDATE {t}.`a` = {t}.`a`',
            $this->d->upsert('t', ['a', 'b'], ['a'], []),
            'nothing to update: the existing row stays',
        );
    }

    public function testInsertIgnore(): void
    {
        $this->assertSame('INSERT IGNORE INTO {t} (`a`, `b`) VALUES (?, ?)', $this->d->insertIgnore('t', ['a', 'b']));
    }

    public function testJsonExtract(): void
    {
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(data, '$.\"a\".\"b\"[2]'))", $this->d->jsonExtract('data', '$.a.b[2]'));
        $this->assertSame("JSON_UNQUOTE(JSON_EXTRACT(c.data, '$.\"name\"'))", $this->d->jsonExtract('c.data', 'name'));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->jsonExtract('data', "a') OR 1=1 --");
    }

    public function testIntervalAndTime(): void
    {
        $this->assertSame('NOW() - INTERVAL 5 DAY', $this->d->now() . ' - ' . $this->d->interval(5, 'day'));
        $this->assertSame('UNIX_TIMESTAMP(created_at)', $this->d->unixTime('created_at'));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->interval(1, 'day; DROP');
    }

    public function testGroupConcat(): void
    {
        $this->assertSame("GROUP_CONCAT(DISTINCT name ORDER BY name SEPARATOR ', ')", $this->d->groupConcat('name', ', ', 'name', true));
        $this->assertSame("GROUP_CONCAT(name SEPARATOR ',')", $this->d->groupConcat('name'));
    }

    public function testLimit(): void
    {
        $this->assertSame('LIMIT 10', $this->d->limit(10));
        $this->assertSame('LIMIT 10 OFFSET 20', $this->d->limit(10, 20));
    }

    public function testFulltext(): void
    {
        $this->assertSame('MATCH(`title`, `text`) AGAINST (? IN BOOLEAN MODE)', $this->d->fulltextMatch(['title', 'text']));
        $this->assertSame('+cafe* +bar*', $this->d->fulltextQuery(['cafe', 'bar']));
        $this->assertSame('CREATE FULLTEXT INDEX `ft_x` ON `tl_news` (`title`, `text`)', $this->d->fulltextIndex('tl_news', 'ft_x', ['title', 'text']));
        $this->assertSame('title LIKE ?', $this->d->likeInsensitive('title'));
    }

    public function testLockAndCatalogueQueries(): void
    {
        $this->assertSame('SELECT DATABASE()', $this->d->currentDatabaseSql());
        $this->assertStringContainsString('DATABASE()', $this->d->tableExistsSql());
        $this->assertSame('', $this->d->returning('id'));
    }

    public function testDsnByTheConfiguration(): void
    {
        $this->assertSame('mysql:host=db;port=3306;dbname=x;charset=utf8mb4', $this->d->dsn(['host' => 'db', 'name' => 'x']));
        $this->assertSame('mysql:unix_socket=/s;dbname=x;charset=utf8mb4', $this->d->dsn(['socket' => '/s', 'name' => 'x']));
        $this->assertSame(3306, $this->d->defaultPort());
        $this->assertSame('mysql', Dialect::forDriver('')->name());
    }
}
