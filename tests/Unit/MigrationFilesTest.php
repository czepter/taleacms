<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use Talea\Core\Migrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The migration files themselves: names, classes, and the rule that charset and collation are set once, on the database. */
#[CoversClass(Migrator::class)]
final class MigrationFilesTest extends TestCase
{
    /** @return list<string> */
    private function files(): array
    {
        return glob(Migrator::FOLDER . '/*.php') ?: [];
    }

    private function source(): string
    {
        return implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $this->files()));
    }

    public function testThereAreMigrations(): void
    {
        $this->assertNotSame([], $this->files());
        $this->assertSame(count($this->files()), count(Migrator::files()));
    }

    public function testFileNamesAreATimestampAndAWord(): void
    {
        foreach ($this->files() as $file) {
            $this->assertMatchesRegularExpression('/^\d{14}_[a-z0-9_]+\.php$/', basename($file));
        }
    }

    public function testVersionsAreUniqueAndAscending(): void
    {
        $versions = array_keys(Migrator::files());

        $this->assertSame($versions, array_values(array_unique($versions)));
        $sorted = $versions;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $versions);
    }

    public function testEveryFileDefinesOneUniqueMigrationClass(): void
    {
        $this->assertSame(count($this->files()), preg_match_all('/^final class (\w+) extends AbstractMigration/m', $this->source(), $m));
        $this->assertSame($m[1], array_values(array_unique($m[1])));
    }

    public function testNoMigrationNamesACharsetOrACollation(): void
    {
        $this->assertSame(0, preg_match('/\b(charset|collat\w*)\b/i', $this->source()));
    }

    public function testNoMigrationUsesRawSql(): void
    {
        $this->assertSame(0, preg_match('/->execute\(|->query\(/', $this->source()));
    }

    public function testPhinxConfigurationCarriesThePrefixAndTheOneCollation(): void
    {
        $config = Migrator::phinxConfig(['name' => 'x', 'username' => 'u', 'password' => '', 'prefix' => 'web_']);

        $this->assertSame('utf8mb4_0900_ai_ci', $config['environments']['default']['collation']);
        $this->assertSame('web_', $config['environments']['default']['table_prefix']);
        $this->assertSame('web_migrations', $config['environments']['default_migration_table']);
    }

    public function testASocketReplacesHostAndPort(): void
    {
        $env = Migrator::phinxConfig(['name' => 'x', 'username' => 'u', 'password' => '', 'socket' => '/run/mysqld.sock'])['environments']['default'];

        $this->assertSame('/run/mysqld.sock', $env['unix_socket']);
        $this->assertArrayNotHasKey('host', $env);
    }
}
