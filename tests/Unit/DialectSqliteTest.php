<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Talea\Core\Db;
use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\Sqlite;

/**
 * The SQL the SQLite dialect writes, and its PHP functions on a database in memory (no server needed; DialectTest in
 * tests/Integration runs every helper on a migrated site database with TALEA_TEST_DB_DRIVER=sqlite).
 */
#[CoversClass(Sqlite::class)]
#[CoversClass(Dialect::class)]
final class DialectSqliteTest extends TestCase
{
    private Sqlite $d;

    protected function setUp(): void
    {
        $this->d = new Sqlite();
    }

    public function testDbExpandsTablePlaceholdersAndTurnsBackticksIntoQuotes(): void
    {
        $db = new Db('sqlite::memory:', '', '', 'web_');

        $this->assertSame('SELECT "id" FROM "web_news" JOIN "web_tags"', $db->sql('SELECT `id` FROM {news} JOIN {tags}'));
        $this->assertSame('sqlite', $db->dialect()->name());
    }

    public function testTheFileOfTheConfiguration(): void
    {
        $this->assertSame('sqlite:' . TALEA_ROOT . '/' . Sqlite::DEFAULT_FILE, $this->d->dsn(['name' => '']));
        $this->assertSame('sqlite:' . TALEA_ROOT . '/storage/database/site.sqlite', $this->d->dsn(['name' => 'storage/database/site.sqlite']));
        $this->assertSame('sqlite:/var/lib/talea/site.sqlite', $this->d->dsn(['name' => '/var/lib/talea/site.sqlite']));
        $this->assertSame(['adapter' => 'sqlite', 'suffix' => ''], $this->d->phinx());
    }

    public function testUpsertAndInsertIgnore(): void
    {
        $this->assertSame(
            'INSERT INTO {s} ("day", "visits") VALUES (?, ?) ON CONFLICT ("day") DO UPDATE SET "visits" = {s}."visits" + excluded."visits"',
            $this->d->upsert('s', ['day', 'visits'], ['day'], ['visits' => '{old.visits} + {new.visits}']),
        );
        $this->assertSame('INSERT INTO {t} ("a") VALUES (?) ON CONFLICT ("a") DO NOTHING', $this->d->upsert('t', ['a'], ['a'], []));
        $this->assertSame('INSERT INTO {t} ("a", "b") VALUES (?, ?) ON CONFLICT DO NOTHING', $this->d->insertIgnore('t', ['a', 'b']));
        $this->assertSame('INSERT OR IGNORE INTO {t} ("a") SELECT 1', $this->d->insertIgnoreSelect('t', ['a'], 'SELECT 1'));
    }

    public function testRewriteSpellsMysqlTimeArithmeticTheSqliteWay(): void
    {
        $this->assertSame("SELECT 1 WHERE d > datetime(NOW(), '-30 day') AND e < datetime(NOW(), '+14 day')", $this->d->rewrite('SELECT 1 WHERE d > NOW() - INTERVAL 30 DAY AND e < NOW() + INTERVAL 2 WEEK'));
        $this->assertSame("WHERE a < datetime(NOW(), ('-' || (CAST(? AS INTEGER) * 1) || ' hour'))", $this->d->rewrite('WHERE a < NOW() - INTERVAL ? HOUR'));
        $this->assertSame("WHERE day >= date(NOW(), '-1 day') AND day <= date(NOW())", $this->d->rewrite('WHERE day >= CURRENT_DATE - INTERVAL 1 DAY AND day <= CURRENT_DATE'));
        $this->assertSame("WHERE b.starts_at > datetime(b.starts_at, '-1 hour')", $this->d->rewrite('WHERE b.starts_at > b.starts_at - INTERVAL 1 HOUR'));
        $this->assertSame("WHERE path LIKE ? ESCAPE '\\' AND t LIKE 'page:%' ESCAPE '\\'", $this->d->rewrite("WHERE path LIKE ? AND t LIKE 'page:%'"));
        $this->assertSame('SELECT id FROM "tl_x" WHERE id = 1', $this->d->rewrite('SELECT id FROM `tl_x` WHERE id = 1 FOR UPDATE'));
        $this->assertSame('FREQ=WEEKLY;INTERVAL=2', $this->d->rewrite('FREQ=WEEKLY;INTERVAL=2'), 'text that only looks like an interval stays');
    }

    public function testJsonGroupConcatAndFulltext(): void
    {
        $this->assertSame("(CASE json_type(data, '$.\"a\".\"b\"[2]') WHEN 'true' THEN 'true' WHEN 'false' THEN 'false' ELSE CAST(json_extract(data, '$.\"a\".\"b\"[2]') AS TEXT) END)", $this->d->jsonExtract('data', '$.a.b[2]'));
        $this->assertSame("talea_group_concat(j.tool, ', ', 1, j.tool, 0)", $this->d->groupConcat('j.tool', ', ', 'j.tool', true));
        $this->assertSame("talea_group_concat(n, ',', 0, a, 0, f(b, c), 1)", $this->d->groupConcat('n', ',', 'a, f(b, c) DESC'));
        $this->assertSame('(talea_match(?, "search_text") = 1)', $this->d->fulltextMatch(['search_text']));
        $this->assertSame('', $this->d->fulltextIndex('tl_news', 'ft_x', ['title']), 'no index: the search column is read as it is');
        $this->expectException(\InvalidArgumentException::class);
        $this->d->jsonExtract('data', "a') OR 1=1 --");
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testTheFunctionsMeanWhatMysqlMeans(): void
    {
        $pdo = new \Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->d->connect($pdo);
        $value = fn (string $sql, array $params = []): mixed => ($s = $pdo->prepare($this->d->rewrite($sql))) && $s->execute($params) ? $s->fetchColumn() : null;

        $this->assertSame(date('Y-m-d'), substr((string) $value('SELECT NOW()'), 0, 10), 'NOW() is PHP time');
        $this->assertSame(date('Y-m-d', time() - 86400), $value('SELECT CURRENT_DATE - INTERVAL 1 DAY'));
        $this->assertSame(1, (int) $value('SELECT ' . $this->d->likeInsensitive("'Café Crème'"), ['%CAFE CREME%']));
        $this->assertSame(1, (int) $value('SELECT ' . $this->d->likeInsensitive("'Ångström Façade'"), ['%angstrom fac%']));
        $this->assertSame(0, (int) $value('SELECT ' . $this->d->likeInsensitive("'a_b'"), ['a\\_c']), 'a backslash escapes the wildcard, as on MySQL');
        $this->assertNull($value("SELECT CONCAT('a', NULL)"), 'CONCAT is NULL when a part is');
        $this->assertSame('a-b', $value("SELECT CONCAT_WS('-', 'a', NULL, 'b')"));
        $this->assertSame('ÅNGSTRÖM', $value("SELECT UPPER('ångström')"));
        $this->assertSame(1, (int) $value('SELECT ' . $this->d->fulltextMatch(['t']) . " FROM (SELECT 'cafe creme world' AS t)", [$this->d->fulltextQuery(['caf', 'wor'])]));
        $this->assertSame(0, (int) $value('SELECT ' . $this->d->fulltextMatch(['t']) . " FROM (SELECT 'cafe creme world' AS t)", [$this->d->fulltextQuery(['afe'])]), 'a word is matched from its start');
        $this->assertSame('a, b', $value('SELECT ' . $this->d->groupConcat('n', ', ', 'n', true) . " FROM (SELECT 'b' AS n UNION ALL SELECT 'a' UNION ALL SELECT 'b')"));
        $this->assertSame('b;a', $value('SELECT ' . $this->d->groupConcat('n', ';', 'n DESC') . " FROM (SELECT 'a' AS n UNION ALL SELECT 'b')"));
        $this->assertSame('12', $value('SELECT ' . $this->d->jsonExtract("'{\"a\":{\"n\":[10,12]}}'", 'a.n[1]')), 'a number as text, as JSON_UNQUOTE gives it');
        $this->assertSame('true', $value('SELECT ' . $this->d->jsonExtract("'{\"on\":true}'", 'on')), 'true as MySQL and PostgreSQL print it');
        $this->assertNull($value('SELECT ' . $this->d->jsonExtract("'{\"on\":true}'", 'off')));
        $this->assertEqualsWithDelta(time(), (int) $value('SELECT ' . $this->d->unixTime('NOW()')), 5);
        $this->assertSame(intdiv((int) date('Z'), 60), (int) $value('SELECT ' . $this->d->sessionOffsetMinutes()));
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testABackupLiteralStaysOnOneLineAndReadsBackTheSame(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $text = "it's\nmulti-line\r\nwith \\ backslash";
        $literal = $this->d->dumpLiteral($pdo, $text);

        $this->assertStringNotContainsString("\n", $literal);
        $this->assertSame($text, $pdo->query('SELECT ' . $literal)->fetchColumn());
    }

    public function testUnknownDriversAreRefusedAndSqliteIsKnown(): void
    {
        $this->assertSame('sqlite', Dialect::forDriver('sqlite')->name());
        $this->expectException(\InvalidArgumentException::class);
        Dialect::forDriver('oracle');
    }
}
