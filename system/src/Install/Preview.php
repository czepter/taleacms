<?php

declare(strict_types=1);

namespace Talea\Install;

use Talea\Builder\Looks;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Extensions;
use Talea\Core\Language;
use Talea\Core\Migrator;
use Talea\Core\Request;
use Talea\Core\Response;
use Talea\Front\Kernel;

/**
 * The installer's preview of a look: the real home page, rendered by the same code as the public site, from a scratch SQLite database that
 * has nothing to do with the site's own (before the installation that one has no tables). The scratch database is migrated once
 * (storage/cache/install-preview/template-<last migration>.sqlite); a request copies it, writes the starter content of the look into the
 * copy (Starter, the code the installer runs), lets the front Kernel render "/" and throws the copy away. The HTML is cached per look and
 * language. Only a look key and a language code from the known lists reach this class; the page runs no script and its links are inert.
 */
final class Preview
{
    private const string FOLDER = TALEA_ROOT . '/storage/cache/install-preview';

    /** Whether the preview can be built here: SQLite and Phinx are there and the cache folder is writable. */
    public static function available(): bool
    {
        return extension_loaded('pdo_sqlite') && Migrator::available() && (is_dir(self::FOLDER) || @mkdir(self::FOLDER, 0775, true)) && is_writable(self::FOLDER);
    }

    /** The address of the preview page (relative to the installation) for the installer's iframe. */
    public static function url(string $base, string $look, string $language): string
    {
        return $base . '/install.php?preview=' . rawurlencode($look) . '&language=' . rawurlencode($language);
    }

    /** The answer to install.php?preview=<look>&language=<code>: the home page, or 404 for a look or language that does not exist. */
    public static function respond(string $look, string $language, Request $request): Response
    {
        $definition = Looks::get($look);
        $language = $language === '' ? 'en' : $language;
        if ($definition === null || !isset(Language::AVAILABLE[$language]) || !self::available()) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $cache = self::FOLDER . '/page-' . md5(TALEA_VERSION . '|' . array_key_last(Migrator::files()) . '|' . $request->basePath() . '|' . $look . '|' . $language) . '.html';
        if (!is_file($cache)) {
            @set_time_limit(300); // the first request migrates the scratch database
            file_put_contents($cache . '.tmp', self::render($definition, $language, $request), LOCK_EX);
            rename($cache . '.tmp', $cache);
        }

        // no script runs in the frame, and nothing in it can be sent anywhere
        return new Response((string) file_get_contents($cache), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, max-age=600',
            'Content-Security-Policy' => "script-src 'none'; form-action 'none'; base-uri 'none'"]);
    }

    /** Removes the scratch databases and the cached pages (after a successful installation). */
    public static function forget(): void
    {
        foreach (glob(self::FOLDER . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::FOLDER);
    }

    /** @param array<string, mixed> $look */
    private static function render(array $look, string $language, Request $request): string
    {
        $work = self::FOLDER . '/work-' . bin2hex(random_bytes(6)) . '.sqlite';
        copy(self::template(), $work);
        try {
            $config = ['db' => ['driver' => 'sqlite', 'name' => $work, 'prefix' => 'tl_'], 'debug' => false];
            $db = Db::fromConfig($config['db']);
            $siteName = Language::runWith($language, fn (): string => t('My website'));
            $db->transaction(fn () => Starter::create($db, $siteName, $language, $look, Extensions::fromValue(''), $request->basePath(),
                ['site_url' => $request->origin(), 'site_email' => '', 'german_register' => 'informal', 'time_zone' => date_default_timezone_get(), 'tasks_token' => '', 'page_cache' => '0']));
            $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $request->basePath() . '/', 'SCRIPT_NAME' => $request->serverValues()['SCRIPT_NAME'] ?? '/install.php'] + $request->serverValues();
            $answer = (new Kernel(new App($config, new Request([], [], $server))))->handle();
            if ($answer->status !== 200) {
                throw new \RuntimeException('The preview page answered ' . $answer->status . '.');
            }

            // links, buttons and fields do nothing in the frame
            return str_replace('</head>', '<style>a, button, input, select, textarea, summary { pointer-events: none; }</style></head>', $answer->body);
        } finally {
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                @unlink($work . $suffix);
            }
        }
    }

    /** The migrated, empty scratch database: built once per set of migrations, by one process at a time. */
    private static function template(): string
    {
        $template = self::FOLDER . '/template-' . array_key_last(Migrator::files()) . '.sqlite';
        if (is_file($template)) {
            return $template;
        }
        $lock = fopen(self::FOLDER . '/lock', 'c');
        flock($lock, LOCK_EX);
        try {
            if (!is_file($template)) {
                $building = self::FOLDER . '/building-' . bin2hex(random_bytes(4)) . '.sqlite';
                Migrator::migrate(['driver' => 'sqlite', 'name' => $building, 'prefix' => 'tl_']);
                gc_collect_cycles(); // Phinx keeps its connection in a reference cycle: close it
                (new \PDO('sqlite:' . $building))->exec('PRAGMA journal_mode = DELETE'); // one file to copy, no -wal beside it
                rename($building, $template);
                array_map('unlink', glob(self::FOLDER . '/building-*') ?: []); // the lock file of the connection
                foreach (glob(self::FOLDER . '/template-*') ?: [] as $old) {
                    $old === $template || @unlink($old); // the template of an earlier set of migrations
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $template;
    }
}
