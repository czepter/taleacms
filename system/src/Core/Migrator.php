<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\Sqlite;

use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Database structure: Phinx migrations in system/database/migrations (files YYYYMMDDHHMMSS_name.php, applied in that order,
 * recorded in the table {prefix}migrations). A new installation runs all of them; a site that got new files runs only those.
 * Multisite: the owner prefix (owner tables and site 1) first, then every further site with its own prefix and log table
 * (PrefixAdapter keeps the owner tables out of those runs, docs/specs/multisite.md).
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
     * @param array{driver?:string,host?:string,port?:int,socket?:string,name:string,username?:string,password?:string,prefix?:string,site_prefix?:string} $db
     * @return array<string, mixed>
     */
    public static function phinxConfig(array $db): array
    {
        $dialect = Dialect::forDriver($db['driver'] ?? 'mysql');
        $prefix = $db['site_prefix'] ?? $db['prefix'] ?? 'tl_';
        $environment = $dialect->phinx() + [
            'name' => $db['name'],
            'user' => $db['username'] ?? '', // Phinx's own option name
            'pass' => $db['password'] ?? '',
            'table_prefix' => $prefix,
            'owner_prefix' => $db['prefix'] ?? 'tl_',
        ];
        if (class_exists(AdapterFactory::class)) {
            AdapterFactory::instance()->registerWrapper('prefix', PrefixAdapter::class);
        }
        if ($dialect instanceof Sqlite) {
            $environment['name'] = Sqlite::file($db['name']); // a file, no server
        } else {
            $environment += !empty($db['socket']) ? ($dialect->name() === 'mysql' ? ['unix_socket' => $db['socket']] : ['host' => $db['socket']]) : ['host' => $db['host'] ?? 'localhost', 'port' => $db['port'] ?? $dialect->defaultPort()];
        }

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

    /** @return array<string, string> version => file name of the migrations the site of $db has not applied yet */
    public static function pending(Db $db): array
    {
        try {
            $applied = array_map('strval', array_column($db->all('SELECT version FROM {migrations}'), 'version'));
        } catch (\PDOException) {
            $applied = []; // the log table does not exist yet: nothing has been applied
        }

        return array_diff_key(self::files(), array_flip($applied));
    }

    /** Pending migrations of any site of the database (the owner prefix and every further site). */
    public static function anyPending(Db $db): bool
    {
        foreach (Sites::prefixes($db) as $prefix) {
            if (self::pending($db->forSite($prefix)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies every pending migration: the owner prefix first, then each further site. Two processes (two containers) must not
     * migrate at once, so the whole run holds a lock. A failure stops the run (the sites after it are not touched).
     *
     * @param array{driver?:string,host?:string,port?:int,socket?:string,name:string,username?:string,password?:string,prefix?:string,site_prefix?:string} $config
     * @return list<string> file names of the migrations applied (of every site; a file applied to two sites is named twice)
     */
    public static function migrate(array $config): array
    {
        if (!self::available()) {
            throw new \RuntimeException('Phinx is not installed: run "composer install --no-dev" (the vendor/ folder is missing).');
        }
        unset($config['site_prefix']);
        $db = Db::fromConfig($config);
        $lock = substr('talea-migrate-' . hash('sha256', $db->databaseName() . '|' . $db->prefix), 0, 64);
        if (!$db->lock($lock, 60)) {
            throw new \RuntimeException('Another migration is running.');
        }
        try {
            $applied = self::migrateSite($db, $config);
            // the owner run created the site registry; the further sites are read after it
            foreach (array_slice(Sites::prefixes($db), 1) as $prefix) {
                array_push($applied, ...self::migrateSite($db->forSite($prefix), ['site_prefix' => $prefix] + $config));
            }

            return $applied;
        } finally {
            $db->unlock($lock);
        }
    }

    /**
     * Creates and migrates the tables of one further site that has none (Backup::restore brings back a site whose tables were dropped:
     * a PostgreSQL or SQLite backup holds data only). The caller has found the prefix in the backup it restores.
     */
    public static function migratePrefix(Db $db, string $prefix): void
    {
        if ($prefix === $db->ownerPrefix || $db->config() === []) {
            throw new \RuntimeException('The tables of ' . $prefix . ' cannot be created here.');
        }
        self::migrateSite($db->forSite($prefix), ['site_prefix' => $prefix] + $db->config());
    }

    /** @param array{driver?:string,host?:string,port?:int,socket?:string,name:string,username?:string,password?:string,prefix?:string,site_prefix?:string} $config @return list<string> */
    private static function migrateSite(Db $db, array $config): array
    {
        $pending = self::pending($db);
        if ($pending !== []) {
            $manager = new Manager(new Config(self::phinxConfig($config)), new ArrayInput([]), new BufferedOutput());
            $manager->migrate('default');
        }

        return array_values($pending);
    }
}
