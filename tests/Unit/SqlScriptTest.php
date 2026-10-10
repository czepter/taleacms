<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use Talea\Core\SqlScript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlScript::class)]
final class SqlScriptTest extends TestCase
{
    private const string SCRIPT = "-- comment\nALTER TABLE tl_news ADD COLUMN x INT;   -- note after the statement\nCREATE TABLE tl_new (\n  a VARCHAR(10) DEFAULT ';'\n);\nALTER TABLE tl_a ADD CONSTRAINT fk_a FOREIGN KEY (b) REFERENCES tl_b (id);\n";

    public function testSplitsIntoStatementsAndIgnoresCommentsAndSemicolonsInValues(): void
    {
        $statements = SqlScript::statements(self::SCRIPT, 'web_');

        $this->assertCount(3, $statements);
        $this->assertStringContainsString("DEFAULT ';'", $statements[1]);
    }

    public function testReplacesTheTablePrefix(): void
    {
        $statements = SqlScript::statements(self::SCRIPT, 'web_');

        $this->assertStringContainsString('CREATE TABLE web_new', $statements[1]);
        $this->assertStringContainsString('REFERENCES web_b', $statements[2]);
    }

    public function testConstraintNamesCarryThePrefixToo(): void
    {
        $this->assertStringContainsString('CONSTRAINT web_fk_a', SqlScript::statements(self::SCRIPT, 'web_')[2]);
    }

    public function testAScriptOfOnlyCommentsHasNoStatements(): void
    {
        $this->assertSame([], SqlScript::statements("-- nothing\n-- here\n", 'tl_'));
    }
}
