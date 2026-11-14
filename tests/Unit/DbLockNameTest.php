<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Db;
use Talea\Core\Dialect\Postgres;

final class DbLockNameTest extends TestCase
{
    public function testTheScopeSeparatesDatabases(): void
    {
        $this->assertNotSame(Db::scopedLockName('a', 'x'), Db::scopedLockName('b', 'x'));
        $this->assertNotSame(Db::scopedLockName('a', 'x'), Db::scopedLockName('a', 'y'));
        $this->assertSame(Db::scopedLockName('a', 'x'), Db::scopedLockName('a', 'x'));
        $this->assertNotSame(Db::scopedLockName('ab', 'x'), Db::scopedLockName('a', 'bx'), 'the parts cannot run together');
    }

    public function testTheNameFitsMysqlsLimit(): void
    {
        $name = Db::scopedLockName(str_repeat('d', 200), str_repeat('n', 200));
        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertStringStartsWith('talea-', $name);
    }

    public function testPostgresScopesByDatabaseAndSchema(): void
    {
        $sql = (new Postgres())->lockScopeSql();
        $this->assertStringContainsString('current_database()', $sql);
        $this->assertStringContainsString('current_schema()', $sql);
    }
}
