<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Core\Dialect\Dialect;

/**
 * Where the site's configuration comes from. Two sources, the environment wins:
 *
 *  1. Environment variables (Docker): TALEA_DB_NAME turns this mode on (TALEA_DB_DRIVER = mysql | pgsql), see docker/README.md for the full list.
 *     Every variable also has a TALEA_…_FILE twin (Docker / Kubernetes secrets): the value is read from that file.
 *  2. config.php (classic hosting), written by the web installer.
 *
 * null = not configured, the site is not installed yet.
 */
final class Config
{
    public static function fromEnv(): bool
    {
        return self::env('DB_NAME') !== '';
    }

    /** @return array<string, mixed>|null */
    public static function load(): ?array
    {
        if (self::fromEnv()) {
            return self::installed() ? self::fromEnvironment() : null;
        }
        $file = TALEA_ROOT . '/config.php';

        return is_file($file) ? require $file : null;
    }

    /**
     * Env mode has no config.php to prove the installation: the web installer leaves storage/.installed behind. A database
     * that already has the tables (a restored volume, a second instance) is recognised once and gets the marker.
     */
    public static function installed(): bool
    {
        $marker = TALEA_ROOT . '/storage/.installed';
        if (is_file($marker)) {
            return true;
        }
        try {
            $db = Db::fromConfig(self::fromEnvironment()['db']);
            if (!$db->tableExists('users')) {
                return false;
            }
        } catch (\PDOException) {
            return false;
        }
        @touch($marker);

        return true;
    }

    public static function markInstalled(): bool
    {
        return @touch(TALEA_ROOT . '/storage/.installed');
    }

    /** @return array<string, mixed> */
    public static function fromEnvironment(): array
    {
        if (($url = self::env('AI_URL')) !== '' && !defined('TALEA_AI_URL')) {
            define('TALEA_AI_URL', $url);
        }

        return [
            'db' => [
                'driver' => $driver = self::env('DB_DRIVER', 'mysql'),
                'host' => self::env('DB_HOST', 'localhost'),
                'port' => (int) self::env('DB_PORT', '0') ?: Dialect::forDriver($driver)->defaultPort(),
                'name' => self::env('DB_NAME'),
                'username' => self::env('DB_USER'),
                'password' => self::env('DB_PASSWORD'),
                'prefix' => self::env('DB_PREFIX', 'tl_'),
            ],
            'debug' => self::flag('DEBUG', false),
            'addons' => self::flag('ADDONS', true),
        ];
    }

    /** TALEA_<name>, or the content of the file named by TALEA_<name>_FILE; $default when neither is set. */
    public static function env(string $name, string $default = ''): string
    {
        $file = getenv("TALEA_{$name}_FILE");
        if ($file !== false && $file !== '' && is_readable($file)) {
            return rtrim((string) file_get_contents($file), "\r\n");
        }
        $value = getenv("TALEA_{$name}");

        return $value === false || $value === '' ? $default : $value;
    }

    public static function flag(string $name, bool $default): bool
    {
        $value = strtolower(self::env($name));

        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
