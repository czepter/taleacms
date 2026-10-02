<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The public demo (2.6, demo.kaletacms.com): anyone signs in with the account shown on the sign-in screen, tries the
 * admin and the builder, and every hour the site goes back to its snapshot (php system/demo.php reset from cron).
 * Switched on in config.php: 'demo' => ['user' => 'demo', 'password' => '…'].
 *
 * Visitors share one admin, so the demo refuses what could be abused or lock the others out: sending e-mail, requests
 * to other servers (webhooks, downloads from URLs, updates, off-site backups, IndexNow, the Claude connection), code
 * fields, secret keys, users and passwords, backups, updates and imports.
 */
final class Demo
{
    /** @var array{user: string, password: string}|null */
    private static ?array $config = null;

    /** Called when the app starts, with config.php's 'demo' entry. */
    public static function configure(mixed $config): void
    {
        self::$config = is_array($config) && is_string($config['user'] ?? null) && is_string($config['password'] ?? null) && $config['user'] !== ''
            ? ['user' => $config['user'], 'password' => $config['password']] : null;
    }

    public static function active(): bool
    {
        return self::$config !== null;
    }

    /** @return array{user: string, password: string}|null the shared sign-in, shown on the sign-in screen */
    public static function account(): ?array
    {
        return self::$config;
    }

    /** The message for anything the demo refuses. */
    public static function refusal(): string
    {
        return t('This is switched off in the public demo. Install Kaleta to try it.');
    }

    /**
     * Admin requests the demo refuses. Looking around is allowed; changes to accounts, imports, backups, updates, mail
     * and webhooks are not, and backups cannot be downloaded either.
     */
    public static function blocksAdmin(string $module, string $action, string $tab, bool $post): bool
    {
        if ($module === 'settings') {
            return !in_array($action, ['', 'list', 'save', 'hours_sign'], true) || ($post && !in_array($tab, ['', 'general', 'company', 'seo', 'cookies', 'analytics'], true)); // hours_sign only renders a printable page
        }

        return $post && match ($module) {
            'users', 'roles', 'transfer', 'extensions', 'newsletters', 'fleet' => true,
            '' => in_array($action, ['account', 'oauth'], true),
            default => false,
        };
    }

    /** Settings keys the demo never saves: code that runs on the site and secret keys. */
    public static function blocksSetting(string $key, string $type): bool
    {
        return $type === 'kod' || str_starts_with($type, 'tajne') || in_array($key, ['site_email', 'update_url', 'health_token', 'auto_suspend'], true);
    }

    /** Seconds until the next reset, from the time of the last one (storage/demo/reset). */
    public static function secondsToReset(): int
    {
        $last = (int) @file_get_contents(self::dir() . '/reset');

        return max(0, ($last > 0 ? $last : time()) + 3600 - time());
    }

    public static function dir(): string
    {
        return KALETA_ROOT . '/storage/demo';
    }

    /** Saves the current database and media as the state every reset returns to. */
    public static function snapshot(Db $db): void
    {
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0775, true)) {
            throw new \RuntimeException('storage/demo cannot be created.');
        }
        $name = Backup::create($db, 'demo');
        $backup = Backup::path($name);
        array_map('unlink', glob(self::dir() . '/kaleta-demo-snapshot.sql*') ?: []);
        if ($backup === null || !rename($backup, self::dir() . '/kaleta-demo-snapshot.' . (str_ends_with($name, '.gz') ? 'sql.gz' : 'sql'))) {
            throw new \RuntimeException('The database snapshot could not be saved.');
        }
        self::copyTree(KALETA_ROOT . '/media', self::dir() . '/media');
        file_put_contents(self::dir() . '/reset', (string) time());
    }

    /** Puts the database and media back to the snapshot and empties the page cache. */
    public static function reset(Db $db): void
    {
        $snapshot = (glob(self::dir() . '/kaleta-demo-snapshot.sql*') ?: [''])[0];
        if ($snapshot === '') {
            throw new \RuntimeException('There is no snapshot yet: run php system/demo.php snapshot first.');
        }
        // Backup::restore reads only from its own folder: the snapshot goes there for the moment of the restore
        $name = basename($snapshot);
        copy($snapshot, Backup::FOLDER . '/' . $name);
        try {
            Backup::restore($db, $name);
        } finally {
            @unlink(Backup::FOLDER . '/' . $name);
        }
        self::copyTree(self::dir() . '/media', KALETA_ROOT . '/media');
        self::removeTree(KALETA_ROOT . '/storage/cache/stranky', false);
        file_put_contents(self::dir() . '/reset', (string) time());
    }

    /** Makes $to an exact copy of $from (files missing in $from are deleted). */
    private static function copyTree(string $from, string $to): void
    {
        self::removeTree($to, false);
        if (!is_dir($from)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                is_dir($target) || mkdir($target, 0775, true);
            } else {
                is_dir(dirname($target)) || mkdir(dirname($target), 0775, true);
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function removeTree(string $dir, bool $self = true): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if ($self) {
            rmdir($dir);
        }
    }
}
