<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\Db;
use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\Postgres;

/** The SQL the PostgreSQL dialect writes (no database needed; DialectTest in tests/Integration executes it). */
#[CoversClass(Postgres::class)]
#[CoversClass(Dialect::class)]
final class DialectPostgresTest extends TestCase
{
    private Postgres $d;

    protected function setUp(): void
    {
        $this->d = new Postgres();
    }

    public function testQuotesIdentifiersAndRejectsOthers(): void
    {
        $this->assertSame('"tl_news"', $this->d->quote('tl_news'));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->quote('a"; DROP TABLE x');
    }

    public function testDbExpandsTablePlaceholdersAndTurnsBackticksIntoQuotes(): void
    {
        $db = new Db('pgsql:host=x', '', '', 'web_');

        $this->assertSame('SELECT "id" FROM "web_news" JOIN "web_tags"', $db->sql('SELECT `id` FROM {news} JOIN {tags}'));
        $this->assertSame('pgsql', $db->dialect()->name());
    }

    public function testUpsert(): void
    {
        $this->assertSame(
            'INSERT INTO {settings} ("name", "value") VALUES (?, ?) ON CONFLICT ("name") DO UPDATE SET "value" = EXCLUDED."value"',
            $this->d->upsert('settings', ['name', 'value'], ['name'], ['value']),
        );
        $this->assertSame(
            'INSERT INTO {t} ("a", "b") VALUES (?, ?) ON CONFLICT ("a") DO NOTHING',
            $this->d->upsert('t', ['a', 'b'], ['a'], []),
        );
    }

    public function testInsertIgnore(): void
    {
        $this->assertSame('INSERT INTO {t} ("a", "b") VALUES (?, ?) ON CONFLICT DO NOTHING', $this->d->insertIgnore('t', ['a', 'b']));
    }

    public function testJsonExtract(): void
    {
        $this->assertSame("((data)::jsonb #>> ARRAY['a', 'b', '2'])", $this->d->jsonExtract('data', '$.a.b[2]'));
        $this->assertSame("((c.data)::jsonb #>> ARRAY['name'])", $this->d->jsonExtract('c.data', 'name'));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->jsonExtract('data', "a') OR 1=1 --");
    }

    public function testIntervalAndTime(): void
    {
        $this->assertSame("NOW() - INTERVAL '5 day'", $this->d->now() . ' - ' . $this->d->interval(5, 'day'));
        $this->assertSame('EXTRACT(EPOCH FROM created_at)::bigint', $this->d->unixTime('created_at'));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->interval(1, 'day; DROP');
    }

    public function testGroupConcat(): void
    {
        $this->assertSame("string_agg(DISTINCT (name)::text, ', ' ORDER BY (name)::text)", $this->d->groupConcat('name', ', ', 'name', true));
        $this->assertSame("string_agg((name)::text, ',')", $this->d->groupConcat('name'));
    }

    public function testLimit(): void
    {
        $this->assertSame('LIMIT 10 OFFSET 20', $this->d->limit(10, 20));
    }

    public function testFulltextUsesOneExpressionForIndexAndQuery(): void
    {
        $match = $this->d->fulltextMatch(['title', 'text']);
        $index = $this->d->fulltextIndex('tl_news', 'ft_x', ['title', 'text']);
        $vector = "to_tsvector('simple', coalesce(\"title\", '') || ' ' || coalesce(\"text\", ''))";

        $this->assertSame("($vector @@ to_tsquery('simple', ?))", $match);
        $this->assertSame("CREATE INDEX \"ft_x\" ON \"tl_news\" USING GIN ($vector)", $index);
        $this->assertSame('cafe:* & bar:*', $this->d->fulltextQuery(['cafe', 'bar']));
        $this->assertSame('title COLLATE "talea_ci" LIKE ?', $this->d->likeInsensitive('title'));
    }

    public function testLockKeyIsAStableSignedBigint(): void
    {
        $this->assertSame(Postgres::key('talea-migrate-x'), Postgres::key('talea-migrate-x'));
        $this->assertNotSame(Postgres::key('a'), Postgres::key('b'));
        $this->assertIsInt(Postgres::key('a'));
    }

    public function testCatalogueQueriesAndReturning(): void
    {
        $this->assertSame('SELECT current_database()', $this->d->currentDatabaseSql());
        $this->assertStringContainsString('current_schema()', $this->d->tableExistsSql());
        $this->assertSame(' RETURNING "user_id"', $this->d->returning('user_id'));
    }

    public function testDsnByTheConfiguration(): void
    {
        $this->assertSame('pgsql:host=db;port=5432;dbname=x', $this->d->dsn(['host' => 'db', 'name' => 'x']));
        $this->assertSame('pgsql:host=/run/postgresql;port=5432;dbname=x', $this->d->dsn(['socket' => '/run/postgresql', 'name' => 'x']));
        $this->assertSame(5432, $this->d->defaultPort());
        $this->assertSame('pgsql', Dialect::forDriver('pgsql')->name());
    }

    public function testUnknownDriversAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dialect::forDriver('sqlite');
    }

    public function testRewriteMakesMysqlIntervalsAndBackticksPostgres(): void
    {
        $this->assertSame("SELECT 1 WHERE d > NOW() - INTERVAL '30 day' AND e < NOW() + INTERVAL '2 year'", $this->d->rewrite('SELECT 1 WHERE d > NOW() - INTERVAL 30 DAY AND e < NOW() + INTERVAL 2 YEAR'));
        $this->assertSame("WHERE a < NOW() - (CAST(? AS integer) * INTERVAL '1 hour')", $this->d->rewrite('WHERE a < NOW() - INTERVAL ? HOUR'));
        $this->assertSame('SELECT "key" FROM "tl_x"', $this->d->rewrite('SELECT `key` FROM `tl_x`'));
        $this->assertSame('FREQ=WEEKLY;INTERVAL=2', $this->d->rewrite('FREQ=WEEKLY;INTERVAL=2'), 'text that only looks like an interval stays');
    }

    public function testUpsertExpressionsNameTheOldAndTheNewRow(): void
    {
        $this->assertSame(
            'INSERT INTO {s} ("day", "visits") VALUES (?, ?) ON CONFLICT ("day") DO UPDATE SET "visits" = {s}."visits" + EXCLUDED."visits", "views" = {s}."views" + 1',
            $this->d->upsert('s', ['day', 'visits'], ['day'], ['visits' => '{old.visits} + {new.visits}', 'views' => '{old.views} + 1']),
        );
    }

    public function testInsertIgnoreSelectAndListPosition(): void
    {
        $this->assertSame('INSERT INTO {t} ("a", "b") SELECT 1, 2 WHERE true ON CONFLICT DO NOTHING', $this->d->insertIgnoreSelect('t', ['a', 'b'], 'SELECT 1, 2 WHERE true'));
        $this->assertSame('CASE n WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', $this->d->listPosition('n', 2));
    }

    public function testUniqueKeyStatements(): void
    {
        $this->assertSame('DROP INDEX "uq_pages_slug"', $this->d->dropUniqueKeySql('pages', 'uq_pages_slug'));
        $this->assertSame('CREATE UNIQUE INDEX "uq_pages_slug" ON {pages} ("slug")', $this->d->addUniqueKeySql('pages', 'uq_pages_slug', ['slug']));
        $this->expectException(\InvalidArgumentException::class);
        $this->d->dropUniqueKeySql('pages; DROP', 'x');
    }
}
