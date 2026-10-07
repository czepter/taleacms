<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Where the site's configuration comes from. Two sources, the environment wins:
 *
 *  1. Environment variables (Docker): KALETA_DB_NAME turns this mode on, see docker/README.md for the full list.
 *     Every variable also has a KALETA_…_FILE twin (Docker / Kubernetes secrets): the value is read from that file.
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
            if (($url = self::env('AI_URL')) !== '' && !defined('KALETA_AI_URL')) {
                define('KALETA_AI_URL', $url);
            }

            return [
                'db' => [
                    'host' => self::env('DB_HOST', 'localhost'),
                    'port' => (int) self::env('DB_PORT', '3306') ?: 3306,
                    'name' => self::env('DB_NAME'),
                    'user' => self::env('DB_USER'),
                    'password' => self::env('DB_PASSWORD'),
                    'prefix' => self::env('DB_PREFIX', 'ka_'),
                ],
                'debug' => self::flag('DEBUG', false),
                'addons' => self::flag('ADDONS', true),
            ];
        }
        $file = KALETA_ROOT . '/config.php';

        return is_file($file) ? require $file : null;
    }

    /** KALETA_<name>, or the content of the file named by KALETA_<name>_FILE; $default when neither is set. */
    public static function env(string $name, string $default = ''): string
    {
        $file = getenv("KALETA_{$name}_FILE");
        if ($file !== false && $file !== '' && is_readable($file)) {
            return rtrim((string) file_get_contents($file), "\r\n");
        }
        $value = getenv("KALETA_{$name}");

        return $value === false || $value === '' ? $default : $value;
    }

    public static function flag(string $name, bool $default): bool
    {
        $value = strtolower(self::env($name));

        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
