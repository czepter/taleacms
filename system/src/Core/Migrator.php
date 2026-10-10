<?php

declare(strict_types=1);

namespace Talea\Core;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Database structure: Phinx migrations in system/database/migrations (files YYYYMMDDHHMMSS_name.php, applied in that order,
 * recorded in the table {prefix}migrations). A new installation runs all of them; a site that got new files runs only those.
 *
 * Migrations run from the command line (`php bin/migrate`, the Docker entrypoint) and from the installer - never on a page
 * request. The administration only reports that some are pending (pending()).
 */
final class Migrator
{
    public const string FOLDER = TALEA_SYSTEM . '/database/migrations';

    /** Phinx is a Composer package: an installation uploaded without vendor/ cannot migrate. */
    public static function available(): bool
    {
        return class_exists(Manager::class);
    }

    /**
     * Phinx configuration for one database; phinx.php (the Phinx command line) returns the same.
     *
     * @param array{host?:string,port?:int,socket?:string,name:string,user:string,password:string,prefix?:string} $db
     * @return array<string, mixed>
     */
    public static function phinxConfig(array $db): array
    {
        $prefix = $db['prefix'] ?? 'tl_';
        $environment = [
            'adapter' => 'mysql',
            'name' => $db['name'],
            'user' => $db['username'], // Phinx's own option name
            'pass' => $db['password'],
            // charset and collation are set here once; the database is created with the same ones and tables inherit them
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'table_prefix' => $prefix,
        ];
        $environment += !empty($db['socket']) ? ['unix_socket' => $db['socket']] : ['host' => $db['host'] ?? 'localhost', 'port' => $db['port'] ?? 3306];

        return [
            'paths' => ['migrations' => self::FOLDER],
            'environments' => ['default_migration_table' => $prefix . 'migrations', 'default_environment' => 'default', 'default' => $environment], // Phinx does not prefix its own log table
            'version_order' => 'creation',
        ];
    }

    /** @return array<string, string> version (the timestamp) => file name, ascending */
    public static function files(): array
    {
        $files = [];
        foreach (glob(self::FOLDER . '/[0-9]*_*.php') ?: [] as $file) {
            $files[(string) strstr(basename($file), '_', true)] = basename($file);
        }
        ksort($files);

        return $files;
    }

    /** @return array<string, string> version => file name of the migrations that have not been applied yet */
    public static function pending(Db $db): array
    {
        try {
            $applied = array_map('strval', array_column($db->all('SELECT version FROM {migrations}'), 'version'));
        } catch (\PDOException) {
            $applied = []; // the log table does not exist yet: nothing has been applied
        }

        return array_diff_key(self::files(), array_flip($applied));
    }

    /**
     * Applies every pending migration. Two processes (two containers) must not migrate at once, so the whole run holds a lock.
     *
     * @param array{host?:string,port?:int,socket?:string,name:string,user:string,password:string,prefix?:string} $config
     * @return list<string> file names of the migrations applied
     */
    public static function migrate(array $config): array
    {
        if (!self::available()) {
            throw new \RuntimeException('Phinx is not installed: run "composer install --no-dev" (the vendor/ folder is missing).');
        }
        $db = Db::fromConfig($config);
        $lock = substr('talea-migrate-' . hash('sha256', (string) $db->value('SELECT DATABASE()') . '|' . $db->prefix), 0, 64);
        if ((int) $db->value('SELECT GET_LOCK(?, 60)', [$lock]) !== 1) {
            throw new \RuntimeException('Another migration is running.');
        }
        try {
            $pending = self::pending($db);
            if ($pending !== []) {
                $manager = new Manager(new Config(self::phinxConfig($config)), new ArrayInput([]), new BufferedOutput());
                $manager->migrate('default');
            }

            return array_values($pending);
        } finally {
            $db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
