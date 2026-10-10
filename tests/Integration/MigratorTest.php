<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use Talea\Core\Migrator;
use Talea\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** The real migrations against a real MySQL 8 (the class's database was built by Migrator::migrate in setUpBeforeClass). */
#[CoversClass(Migrator::class)]
final class MigratorTest extends DatabaseTestCase
{
    public function testEveryMigrationIsApplied(): void
    {
        $this->assertSame([], Migrator::pending($this->db()));
        $this->assertSame(count(Migrator::files()), (int) $this->db()->value('SELECT COUNT(*) FROM {migrations}'));
    }

    public function testMigratingAgainChangesNothing(): void
    {
        $this->assertSame([], Migrator::migrate($this->dbConfig()));
    }

    public function testAFreshDatabaseHasTheTablesOfTheSchema(): void
    {
        $tables = (int) $this->db()->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [self::PREFIX . '%']);

        $this->assertGreaterThanOrEqual(90, $tables);
    }

    public function testEveryTableAndColumnInheritsTheDatabaseCollation(): void
    {
        $this->assertSame([], array_column($this->db()->all(
            'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_collation <> ?', ['utf8mb4_0900_ai_ci'],
        ), 't'));
        $this->assertSame([], array_column($this->db()->all(
            'SELECT CONCAT(table_name, ".", column_name) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND collation_name IS NOT NULL AND collation_name <> ?', ['utf8mb4_0900_ai_ci'],
        ), 'c'));
    }

    public function testForeignKeyNamesCarryThePrefix(): void
    {
        $this->assertSame([], array_column($this->db()->all(
            'SELECT constraint_name AS n FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name NOT LIKE ?', [self::PREFIX . 'fk\_%'],
        ), 'n'));
    }

    public function testDeletingAParentCascadesWhereTheSchemaSaysSo(): void
    {
        $db = $this->db();
        $db->run('INSERT INTO {users} (username, password, email, admin) VALUES (?, ?, ?, 0)', ['phpunit', 'x', 'phpunit@example.test']);
        $userId = (int) $db->value('SELECT user_id FROM {users} WHERE username = ?', ['phpunit']);
        $db->run('INSERT INTO {user_permissions} (user_id, module) VALUES (?, ?)', [$userId, 'pages']);

        $db->run('DELETE FROM {users} WHERE user_id = ?', [$userId]);

        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {user_permissions} WHERE user_id = ?', [$userId]));
    }

    public function testATestsChangesAreRolledBack(): void
    {
        $this->assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {users}'));
    }
}
