<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * A simple container of shared services. No magic - what the application can do is visible here.
 */
final class App
{
    public readonly Request $request;
    public readonly Session $session;
    public readonly View $view;

    private ?Db $db = null;
    private ?Auth $auth = null;
    private ?Settings $settings = null;

    /**
     * @param array<string, mixed> $config contents of config.php
     * @param array<string, mixed>|null $site the site row (Sites::resolve) this request is routed to; null with multisite off
     */
    public function __construct(public readonly array $config, ?Request $request = null, public readonly ?array $site = null)
    {
        $this->request = $request ?? Request::fromGlobals();
        $this->session = new Session($this->request->isHttps(), $this->request->basePath() . '/');
        $this->view = new View([TALEA_SYSTEM . '/views']);
        Demo::configure($config['demo'] ?? null);
    }

    /**
     * Loads the configuration (environment or config.php, see Config); when there is none, the site is not installed yet.
     * $sitePrefix: a further site's tables (multisite); $ownerPrefix: the tables of a further owner (its site 1 is its owner prefix).
     */
    public static function boot(?string $sitePrefix = null, ?string $ownerPrefix = null): self
    {
        $config = Config::load();
        if ($config !== null && $sitePrefix !== null) {
            $config['db']['site_prefix'] = $sitePrefix;
            if ($ownerPrefix !== null && $ownerPrefix !== ($config['db']['prefix'] ?? 'tl_')) {
                $config['db']['owner_prefix'] = $ownerPrefix;
            }
            \Talea\Front\Cache::useSite($sitePrefix);
        }
        if ($config === null) {
            $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            header('Location: ' . $base . '/install.php');
            exit;
        }
        // while files are being updated the site briefly answers 503 (a lock older than 10 minutes is a leftover and is ignored);
        // the dictionary is not loaded here yet, so the page is plain English
        $lock = TALEA_ROOT . '/storage/maintenance.lock';
        if (is_file($lock) && time() - (int) filemtime($lock) < 600 && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'admin.php') {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
            exit('<!doctype html><meta charset="utf-8"><title>Update in progress</title><body style="font:16px system-ui,sans-serif;margin:3em"><h1 style="font-size:22px">The site is being updated</h1><p>Please try again in a minute.</p>');
        }
        $site = null;
        if ($sitePrefix === null && PHP_SAPI !== 'cli' && Sites::enabled()) {
            $site = self::routeHost($config);
            if ($site['owner_prefix'] !== ($config['db']['prefix'] ?? 'tl_')) {
                $config['db']['owner_prefix'] = $site['owner_prefix'];
            }
            if ($site['prefix'] !== ($config['db']['prefix'] ?? 'tl_')) {
                $config['db']['site_prefix'] = $site['prefix'];
                \Talea\Front\Cache::useSite($site['prefix']);
            }
        }
        $app = new self($config, null, $site);
        $app->installErrorHandler();

        return $app;
    }

    /**
     * Multisite: the site that answers to the request's host (docs/specs/multisite.md). An unknown or trashed host gets a bare 404 (never
     * site 1's content), a site cron has not set up yet a 503, a secondary domain with "redirect to primary" a 301; /health answers for the install (site 1) on any host.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function routeHost(array $config): array
    {
        $request = Request::fromGlobals();
        $db = Db::fromConfig($config['db']);
        $site = Sites::resolve($db, Sites::requestHost($request));
        if ($site === null || $site['status'] === 'trashed' || !$site['ready']) {
            if ($request->path() !== '/health') {
                // unknown host: never site 1's content; a site cron has not set up yet has no tables to boot (Sites::provision)
                $setUp = $site !== null && $site['status'] !== 'trashed';
                http_response_code($setUp ? 503 : 404);
                header('Content-Type: text/plain; charset=utf-8');
                if ($setUp) {
                    header('Retry-After: 300');
                    header('X-Robots-Tag: noindex');
                }
                exit($setUp ? "This site is being set up\n" : "Not found\n");
            }
            $site = ['site_id' => 1, 'public_id' => '', 'prefix' => $db->ownerPrefix, 'owner_prefix' => $db->ownerPrefix, 'status' => 'live', 'redirect_to_site_id' => null, 'is_primary' => true, 'redirect_to_primary' => false, 'primary_host' => null, 'ready' => true];
        }
        if ($site['redirect_to_primary'] && !$site['is_primary'] && $site['primary_host'] !== null) {
            header('Location: ' . Sites::originOn($request, $site['primary_host']) . (string) ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }

        return $site;
    }

    public function db(): Db
    {
        return $this->db ??= Db::fromConfig($this->config['db']);
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this->db(), $this->session);
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db());
    }

    /**
     * The site time zone from Settings applies to PHP and to the database session (PHP writes the dates, queries compare them with NOW()).
     * Both kernels call it right after start; until then the default zone from the bootstrap applies.
     */
    public function applyTimezone(): void
    {
        $timeZone = $this->settings()->get('time_zone');
        if ($timeZone !== date_default_timezone_get() && in_array($timeZone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timeZone);
            $this->db()->dialect()->setTimeZone($this->db()->pdo(), date('P'));
        }
    }

    public function debug(): bool
    {
        return (bool) ($this->config['debug'] ?? false);
    }

    /** Prefix of the site's language version ("en"); set by Front\Kernel when a visitor browses /en/… */
    public string $languagePrefix = '';

    /**
     * Absolute path within the installation: url('admin.php') -> "/magazin/admin.php".
     * In a language version the URLs of site pages get the language prefix (url('news/x') -> "/en/news/x");
     * files and services (anything with an extension, mcp) stay shared.
     */
    public function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        if (preg_match('#^admin\.php(?:\?(.*)|(\#.*))?$#s', $path, $m) === 1) {
            // the administration has clean addresses (/admin/news/edit/<id>), never in a language version
            return $this->request->basePath() . '/' . \Talea\Admin\Kernel::path(($m[1] ?? '') . ($m[2] ?? ''));
        }
        if (preg_match('#^news(?=$|[/?.])#', $path) && isset($this->config['db'])) {
            // the custom news slug (setting news_slug) replaces /news – Core\Routes
            $path = Routes::publicPath($path, $this->db());
        }
        if ($this->languagePrefix !== '') {
            $pathOnly = explode('?', $path, 2)[0];
            // a path that already names its language version (a menu link written as /cs/funkce) keeps it – no /cs/cs/…
            $ownPrefix = preg_match('#^(' . Language::TAG . ')(?:/|$)#', $pathOnly, $m) === 1 && isset(Language::AVAILABLE[$m[1]]);
            if (!$ownPrefix && (!str_contains($pathOnly, '.') || $pathOnly === 'rss.xml' || $pathOnly === 'feed.json') && $pathOnly !== 'mcp') {
                $path = $this->languagePrefix . ($path === '' ? '/' : '/' . $path);
            }
        }

        // trailing slash preference (setting url_slash): page-like paths only, the query and fragment stay behind it
        if (isset($this->config['db']) && preg_match('~^([^?#]+)(.*)$~', $path, $m) && !str_ends_with($m[1], '/') && !str_ends_with($m[1], '.html') && Routes::pageLike('/' . $m[1])) {
            $path = $m[1] . Routes::suffix($this->settings()->get('url_slash')) . $m[2];
        }

        return $this->request->basePath() . '/' . $path;
    }

    /**
     * URL of a news item in ITS language version – regardless of which version the current request came from
     * (publication notifications are sent in the background of someone else's visit).
     */
    public function newsItemUrl(string $seo, string $language): string
    {
        $previous = $this->languagePrefix;
        $this->languagePrefix = in_array($language, Language::additional($this->settings()), true) ? $language : '';
        try {
            return $this->url('news/' . $seo);
        } finally {
            $this->languagePrefix = $previous;
        }
    }

    private function installErrorHandler(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ErrorReport::configure($this->config);
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        ErrorReport::tag('area', match (true) {
            PHP_SAPI === 'cli' => 'cli',
            $script === 'admin.php' => 'admin', // index.php hands /admin/… over to admin.php before the start
            $script === 'install.php' => 'install',
            default => 'front',
        });
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler(function (\Throwable $e): void {
            ErrorReport::log($e, ErrorReport::capture($e, 'error', ['handled' => false]));
            $this->errorPage($e);
        });
        // fatal errors (memory, time limit, a parse error in an add-on) never reach the exception handler: the shutdown sees them;
        // the reserve is freed first, so that an exhausted memory limit still leaves room for the report
        $reserve = str_repeat(' ', 65536);
        register_shutdown_function(function () use (&$reserve): void {
            $reserve = null;
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                return;
            }
            $e = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            ErrorReport::log($e, ErrorReport::capture($e, 'fatal', ['handled' => false])); // sent by ErrorReport's own shutdown function, after the page
            if (!headers_sent()) {
                $this->errorPage($e);
            }
        });
    }

    /** The page of an error nothing else answered: plain English (the site language may not be known yet), details only in debug mode. */
    private function errorPage(\Throwable $e): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        [$title, $heading, $help] = ['Error', 'Sorry, something went wrong on this page.', 'The site administrator can find the details in storage/log/errors.log.'];
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . $title . '</title>'
            . '<body style="font:14px Verdana,sans-serif;margin:3em">'
            . '<h1 style="font-size:18px">' . $heading . '</h1>';
        if ($this->debug()) {
            echo '<pre style="white-space:pre-wrap">' . e((string) $e) . '</pre>';
        } else {
            echo '<p>' . $help . '</p>';
        }
    }
}
