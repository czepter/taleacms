<?php

declare(strict_types=1);

namespace Talea\Install;

use Talea\Core\App;
use Talea\Core\Auth;
use Talea\Core\Blueprint;
use Talea\Core\Config;
use Talea\Core\Db;
use Talea\Core\Dialect\Dialect;
use Talea\Core\Dialect\Sqlite;
use Talea\Core\Migrator;
use Talea\Core\Request;
use Talea\Core\Response;
use Talea\Core\Extensions;
use Talea\Core\View;
use Talea\Builder\Looks;


/**
 * Web installer, a wizard of five steps (website, business, look, features, account): every step is its own POST to install.php with a
 * `step` field, the earlier answers travel in hidden fields (the password is asked last, so it is never carried), a step is checked when it
 * is sent, and the site is installed by the last one. The database comes from the environment in the container; without it
 * (a development copy, the test harness) the last step also asks for the database. A POST without a `step` is the last step, with everything
 * in it. The look step works without JavaScript: a look card is a submit button that shows the step again with that look and its preview
 * (Preview). Talea is installed by its container: the image, the database in TALEA_DB_*, then open the site.
 */
final class Installer
{
    private readonly Request $request;
    private readonly View $view;

    public function __construct()
    {
        $this->request = Request::fromGlobals();
        $this->view = new View([TALEA_SYSTEM . '/views']);
    }

    /** The steps in order. */
    public const array STEPS = ['website', 'business', 'look', 'features', 'account'];

    /** Installation languages (= admin languages) and the default time zone we offer for them. */
    private const array TIME_ZONES = ['cs' => 'Europe/Prague', 'en' => 'Europe/London', 'de' => 'Europe/Berlin'];

    /** The fields of the database (asked on the last step without an environment configuration). */
    private const array DATABASE_FIELDS = ['db_driver', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_prefix'];

    /** Where the blueprints are listed: group => position (the Blueprint::GROUPS labels). */
    public const array BUSINESS_GROUPS = ['food', 'services', 'health', 'tech', 'products', 'public'];

    /** Looks topics of "Something else": a general business site. */
    private const array GENERAL_TOPICS = ['consulting'];

    private string $language = 'en';

    /** Form of address of German (formal | informal): the installer's texts, the first account and the site texts for visitors. */
    private string $register = 'informal';

    /** Installation language: a button of the language switcher, an explicit choice (?language=, hidden form field), otherwise the first known language from the browser header. */
    private function chooseLanguage(): string
    {
        $choice = explode(':', $this->request->post('switch'))[0] ?: (string) ($_POST['language'] ?? $_GET['language'] ?? '');
        if (isset(self::TIME_ZONES[$choice])) {
            return $choice;
        }
        foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
            $code = substr(trim($part), 0, 2);
            if (isset(self::TIME_ZONES[$code])) {
                return $code;
            }
        }

        return 'en';
    }

    /** Form of address: informal (du) unless Sie is chosen explicitly (a switcher button, ?register=, hidden form field); only German has one. */
    private function chooseRegister(string $language): string
    {
        $choice = explode(':', $this->request->post('switch'))[1] ?? (string) ($_POST['register'] ?? $_GET['register'] ?? '');

        return $language === 'de' && $choice === 'formal' ? 'formal' : 'informal';
    }

    public function handle(): Response
    {
        if (Config::load() !== null) {
            $language = $this->chooseLanguage();
            \Talea\Core\Language::set($language, 'install-', $this->chooseRegister($language));

            return $this->page('done', ['alreadyInstalled' => true, 'deleted' => $this->deleteSelf(), 'fromExport' => false]);
        }
        if (isset($_GET['preview'])) {
            return Preview::respond((string) $_GET['preview'], (string) ($_GET['language'] ?? ''), (string) ($_GET['register'] ?? ''), $this->request);
        }

        $this->language = $this->chooseLanguage();
        $this->register = $this->chooseRegister($this->language);
        \Talea\Core\Language::setSiteRegister($this->register); // the sample content for visitors is written in the chosen form of address
        \Talea\Core\Language::set($this->language, 'install-', $this->register);
        $envDb = Config::fromEnv() ? Config::fromEnvironment()['db'] : null;
        $failed = $this->requirements();
        $data = [
            'site_name' => t('My website'), 'site_language' => $this->language, 'time_zone' => self::TIME_ZONES[$this->language], 'blueprint' => '', 'look' => '',
            'username' => 'admin', 'name' => '', 'email' => '',
            'db_driver' => 'mysql', 'db_host' => 'localhost', 'db_port' => '', 'db_name' => '', 'db_user' => '', 'db_password' => '', 'db_prefix' => 'tl_',
        ];
        // extensions enabled after installation: the default set, after the form is submitted the user's choice
        $extensions = Extensions::fromValue('');
        $step = 'website';
        $errors = [];
        if ($this->request->isPost() && $failed === []) {
            foreach (array_diff(array_keys($data), self::DATABASE_FIELDS) as $key) {
                $data[$key] = $key === 'look' ? $this->request->post('look', $this->request->post('look_kept')) : $this->request->post($key);
            }
            foreach (self::DATABASE_FIELDS as $key) {
                $data[$key] = $key === 'db_password' ? (string) ($_POST[$key] ?? '') : $this->request->post($key, $data[$key]); // the database password is not trimmed - it can contain spaces
            }
            if ($envDb !== null) { // the database is not a form field here
                $data = ['db_driver' => $envDb['driver'], 'db_host' => $envDb['host'], 'db_port' => (string) $envDb['port'], 'db_name' => $envDb['name'], 'db_user' => $envDb['username'],
                    'db_password' => $envDb['password'], 'db_prefix' => $envDb['prefix']] + $data;
            }
            $data['db_driver'] = in_array($data['db_driver'], ['mysql', 'pgsql', 'sqlite'], true) ? $data['db_driver'] : 'mysql';
            $data['site_language'] = isset(\Talea\Core\Language::AVAILABLE[$data['site_language']]) ? $data['site_language'] : $this->language;
            $data['time_zone'] = in_array($data['time_zone'], \DateTimeZone::listIdentifiers(), true) ? $data['time_zone'] : self::TIME_ZONES[$this->language];
            $extensions = array_values(array_intersect($this->request->postList('extensions'), array_keys(Extensions::CATALOG)));
            // a POST without a step is the last one with everything in it
            $step = in_array($this->request->post('step'), self::STEPS, true) ? $this->request->post('step') : 'account';
            $go = $this->request->post('go');
            $position = (int) array_search($step, self::STEPS, true);
            if ($this->request->post('switch') !== '' || ($step === 'look' && $go === '' && $this->request->hasPost('look'))) {
                // only the language changed, or a look card was pressed: the same step again
            } elseif ($go === 'prev') {
                $step = self::STEPS[max(0, $position - 1)];
            } elseif (in_array($go, self::STEPS, true)) {
                $step = (int) array_search($go, self::STEPS, true) <= $position ? $go : $step; // the sidebar leads back, never ahead
            } else {
                $password = (string) ($_POST['password'] ?? '');
                $password2 = (string) ($_POST['password2'] ?? '');
                // every step up to this one is checked again (a crafted POST can skip a step): the first that fails is shown with its errors
                $failedStep = $this->firstFailed($data, $password, $password2, $envDb !== null, $step);
                if ($failedStep !== null) {
                    $step = $failedStep;
                    $errors = $this->validate($step, $data, $password, $password2, $envDb !== null);
                } elseif ($step !== 'account') {
                    $step = self::STEPS[$position + 1];
                } else {
                    $errors = $this->install($data, $password, $extensions);
                    if ($errors === []) {
                        Preview::forget();

                        return $this->page('done', ['alreadyInstalled' => false, 'deleted' => $this->deleteSelf(), 'fromExport' => $data['blueprint'] === 'export',
                            'mcp' => in_array('claude', $extensions, true) ? $this->request->origin() . $this->request->basePath() . '/mcp' : null]);
                    }
                }
            }
        }
        $blueprints = Blueprint::available();
        $topics = self::topicsOf($data['blueprint']);
        $looks = Looks::ranked($topics);
        $data['look'] = isset($looks[$data['look']]) ? $data['look'] : '';
        $chosen = $data['look'] !== '' ? $data['look'] : (string) array_key_first($looks);

        return $this->page('form', [
            'failed' => $failed, 'step' => $step, 'steps' => self::STEPS, 'data' => $data, 'errors' => $errors, 'extensions' => $extensions, 'envDb' => $envDb !== null,
            'blueprints' => $blueprints, 'groups' => self::BUSINESS_GROUPS, 'looks' => $looks, 'topics' => $topics, 'chosenLook' => $chosen,
            'preview' => $chosen !== '' && $data['blueprint'] !== 'export' && Preview::available() ? Preview::url($this->request->basePath(), $chosen, $data['site_language'], $this->register) : '',
        ]);
    }

    /**
     * What the steps up to $last need before the site can be installed: the first step with an error, or null.
     *
     * @param array<string, string> $d
     */
    private function firstFailed(array $d, string $password, string $password2, bool $env, string $last): ?string
    {
        foreach (self::STEPS as $step) {
            if ($this->validate($step, $d, $password, $password2, $env) !== []) {
                return $step;
            }
            if ($step === $last) {
                break;
            }
        }

        return null;
    }

    /**
     * The errors of one step, by field. A look and the extensions cannot be wrong: an unknown look becomes the best fit of the business.
     *
     * @param array<string, string> $d
     * @return array<string, string>
     */
    private function validate(string $step, array $d, string $password, string $password2, bool $env): array
    {
        $errors = [];
        switch ($step) {
            case 'website':
                if ($d['site_name'] === '') {
                    $errors['site_name'] = t('Fill in the site name.');
                }
                break;
            case 'business':
                if ($d['blueprint'] !== '' && $d['blueprint'] !== 'export' && !isset(Blueprint::available()[$d['blueprint']])) {
                    $errors['blueprint'] = t('Choose what kind of business it is.');
                }
                break;
            case 'account':
                if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $d['username'])) {
                    $errors['username'] = t('Username: 2–40 characters, letters without accents, digits, dot, hyphen, underscore.');
                }
                if (mb_strlen($password) < 10) {
                    $errors['password'] = t('The password must be at least 10 characters long.');
                } elseif ($password !== $password2) {
                    $errors['password'] = t('The passwords do not match.');
                }
                if ($d['email'] !== '' && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
                    $errors['email'] = t('The e-mail address is not valid.');
                }
                if (!$env) {
                    $errors += $this->validateDatabase($d);
                }
                break;
        }

        return $errors;
    }

    /**
     * @param array<string, string> $d
     * @return array<string, string>
     */
    private function validateDatabase(array $d): array
    {
        $errors = [];
        if (!preg_match('/^[a-z][a-z0-9_]{0,15}$/', $d['db_prefix'])) {
            $errors['db_prefix'] = t('Prefix: lowercase letters, digits and underscore, at most 16 characters (e.g. tl_).');
        }
        if ($d['db_driver'] === 'sqlite') {
            if ($d['db_name'] !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}(\.(?:sqlite3?|db))?$/', $d['db_name']) !== 1) {
                $errors['db_name'] = t('SQLite: the file name may hold letters, digits, hyphens and underscores and end in .sqlite or .db; the file is kept in storage/database/.');
            }
        } elseif ($d['db_name'] === '' || $d['db_user'] === '') {
            $errors['db_name'] = t('Fill in the database name and user.');
        }

        return $errors;
    }

    /** What the server lacks (nothing in the container): the names of the requirements that are not met. @return list<string> */
    private function requirements(): array
    {
        $failed = [];
        foreach ([
            t('PHP 8.5 or newer') => PHP_VERSION_ID >= 80500,
            t('pdo_mysql, pdo_pgsql or pdo_sqlite extension') => extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql') || extension_loaded('pdo_sqlite'),
            t('mbstring extension') => extension_loaded('mbstring'),
            t('Write access to the storage/ folder') => is_writable(TALEA_ROOT . '/storage/log') && is_writable(TALEA_ROOT . '/storage/cache'),
        ] + (Config::fromEnv() ? [] : [t('Write access to the root folder') => is_writable(TALEA_ROOT)]) as $name => $ok) {
            if (!$ok) {
                $failed[] = $name;
            }
        }

        return $failed;
    }

    /**
     * @param array<string, string> $d
     * @param list<string> $extensions enabled extensions
     * @return array<string, string> errors; empty array = installed
     */
    private function install(array $d, string $password, array $extensions): array
    {
        $sqlite = $d['db_driver'] === 'sqlite';
        if ($sqlite && !Config::fromEnv()) {
            // a file name only, kept in storage/database/ (not reachable from the web) and never ending in .php; an empty name is the default file
            $d['db_name'] = $d['db_name'] === '' ? Sqlite::DEFAULT_FILE : dirname(Sqlite::DEFAULT_FILE) . '/' . $d['db_name'] . (preg_match('/\.(?:sqlite3?|db)$/', $d['db_name']) === 1 ? '' : '.sqlite');
        }
        if (!extension_loaded('pdo_' . $d['db_driver'])) {
            return ['db_driver' => t('The PHP extension for this database type is missing (pdo_mysql, pdo_pgsql or pdo_sqlite).')];
        }
        $config = [
            'db' => $sqlite ? ['driver' => 'sqlite', 'name' => $d['db_name'], 'prefix' => $d['db_prefix']] : [
                'driver' => $d['db_driver'],
                'host' => $d['db_host'] !== '' ? $d['db_host'] : 'localhost',
                'port' => (int) $d['db_port'] ?: Dialect::forDriver($d['db_driver'])->defaultPort(),
                'name' => $d['db_name'],
                'username' => $d['db_user'],
                'password' => $d['db_password'],
                'prefix' => $d['db_prefix'],
            ],
            'debug' => false,
        ];

        try {
            $db = Db::fromConfig($config['db']);
            $db->pdo();
        } catch (\PDOException $e) {
            return self::connectionError($e);
        }
        // MariaDB before 11.4 has no utf8mb4_0900_ai_ci, the collation of the schema: say so before half the tables exist
        if (preg_match('/^(\d+\.\d+\.\d+)-MariaDB/', (string) $db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), $m) === 1 && version_compare($m[1], '11.4.0', '<')) {
            return ['db_host' => sprintf(t('Talea needs MariaDB 11.4 or newer (or MySQL 8); this server runs MariaDB %s.'), $m[1])];
        }
        if ($db->tableExists('users')) {
            return ['db_prefix' => t('Tables with this prefix already exist in the database. Choose another prefix or remove them first.')];
        }

        @set_time_limit(300); // migrations cut off by max_execution_time (60 s in the image) leave half a schema behind
        try {
            Migrator::migrate($config['db']);
            $this->createDefaultData($db, $d, $password, $extensions);
            if (isset(Blueprint::available()[$d['blueprint']])) {
                // its collections (with their hidden list pages) and facts, in the language of the site
                \Talea\Core\Language::runWith($d['site_language'], fn () => Blueprint::apply(new App($config), Blueprint::available()[$d['blueprint']]));
            }
        } catch (\Throwable $e) {
            return ['db_name' => t('Creating the tables failed:') . ' ' . $e->getMessage()];
        }

        if (Config::fromEnv()) {
            return Config::markInstalled() ? [] : ['db_name' => t('The tables were created, but the storage/ folder is not writable.')]; // configuration comes from the environment, no config.php
        }
        $content = "<?php\n/**\n * Talea - configuration created by the installer " . date('j. n. Y') . ".\n */\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents(TALEA_ROOT . '/config.php', $content, LOCK_EX) === false) {
            return ['db_name' => t('The tables were created, but config.php could not be written. Check the write permissions.')];
        }

        return [];
    }

    /**
     * A database connection error in human terms and at the field that needs fixing (MySQL/MariaDB error code, PostgreSQL SQLSTATE); an unknown error with the driver's text.
     *
     * @return array<string, string>
     */
    private static function connectionError(\PDOException $e): array
    {
        // PostgreSQL has no numeric codes of its own: the SQLSTATE (28P01 password, 3D000 no database, 08xxx connection) is in the message or errorInfo
        $state = (string) ($e->errorInfo[0] ?? (preg_match('/SQLSTATE\[(\w+)\]/', $e->getMessage(), $m) ? $m[1] : ''));
        // PostgreSQL reports a refused login as a failed connection (SQLSTATE 08006) and says why in the text: read the text first
        if (in_array($state, ['28P01', '28000'], true) || preg_match('/password authentication failed|role "[^"]*" does not exist/', $e->getMessage()) === 1) {
            return ['db_user' => t('The database user name or password is wrong. Check them in your hosting control panel.')];
        }
        if ($state === '3D000' || (str_contains($e->getMessage(), 'database "') && str_contains($e->getMessage(), 'does not exist'))) {
            return ['db_name' => t('There is no database with this name on the server. Create it in your hosting control panel or correct the name.')];
        }

        if (str_starts_with($e->getMessage(), 'SQLSTATE[08') || str_contains($e->getMessage(), 'could not translate host name') || str_contains($e->getMessage(), 'Connection refused')) {
            return ['db_host' => t('Could not connect to the database server. Check the server and port.')];
        }

        return match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045 => ['db_user' => t('The database user name or password is wrong. Check them in your hosting control panel.')],
            1044 => ['db_name' => t('This user has no access to the database. Grant it in your hosting control panel.')],
            1049 => ['db_name' => t('There is no database with this name on the server. Create it in your hosting control panel or correct the name.')],
            2002, 2005, 2006 => ['db_host' => t('Could not connect to the database server. Check the server and port.')],
            default => ['db_name' => t('Could not connect to the database:') . ' ' . $e->getMessage()],
        };
    }

    /**
     * After installation the installer deletes itself, so the administrator does not have to use FTP. When the hosting does
     * not allow it (file permissions), a prompt to delete it remains and the system health page keeps warning about the file.
     * In a development copy (a .git folder) it is not deleted. In a container it belongs to the image and stays (null: nothing to say),
     * the installer refuses to run once installed.
     */
    private function deleteSelf(): ?bool
    {
        if (Config::fromEnv()) {
            return null;
        }
        if (is_dir(TALEA_ROOT . '/.git')) {
            return false;
        }

        return !is_file(TALEA_ROOT . '/install.php') || @unlink(TALEA_ROOT . '/install.php');
    }

    /**
     * @param array<string, string> $d
     * @param list<string> $extensions
     */
    private function createDefaultData(Db $db, array $d, string $password, array $extensions): void
    {
        // the chosen time zone already applies to the initial content: otherwise the welcome news item could have a date "in the future" and the site would not show it
        date_default_timezone_set($d['time_zone']);
        $db->dialect()->setTimeZone($db->pdo(), date('P'));
        $tasksToken = bin2hex(random_bytes(16));
        $db->transaction(function (Db $db) use ($d, $password, $extensions, $tasksToken): void {
            $admin = $db->insert('users', [
                'username' => $d['username'],
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $d['name'],
                'email' => $d['email'],
                'admin' => Auth::ADMIN,
                'language' => $this->language === 'en' ? '' : $this->language, // the admin of the first account in the installation language
                'register' => $this->register,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);

            // the site content is created in the site language (the site dictionary), the admin of the first account stays in the installation language
            $siteLanguage = $d['site_language'];
            $settings = ['site_url' => $this->siteUrl(), 'site_email' => $d['email'], 'german_register' => $this->register, 'time_zone' => $d['time_zone'], 'tasks_token' => $tasksToken];
            if ($d['blueprint'] === 'export') {
                // "Move a Talea site here": an empty site – the content, look and settings come with the import (Import and export)
                $settings += ['site_name' => $d['site_name'], 'site_language' => $siteLanguage, 'extensions' => $extensions === [] ? '-' : implode(',', $extensions)];
                foreach ($settings as $key => $value) {
                    $db->insert('settings', ['name' => $key, 'value' => $value]);
                }

                return;
            }
            $looks = Looks::ranked(self::topicsOf($d['blueprint']));
            Starter::create($db, $d['site_name'], $siteLanguage, $looks[$d['look']] ?? reset($looks), $extensions, $this->request->basePath(), $settings);

            if (!in_array('news', $extensions, true)) {
                return; // without news and without the welcome news item
            }
            $x = fn (string $text): string => \Talea\Core\Language::runWith($siteLanguage, fn (): string => t($text));
            $category = $db->insert('categories', ['name' => $x('News'), 'slug' => slugify($x('News')), 'description' => '']);
            $db->insert('news', [
                'slug' => slugify($x('Our new website is live')),
                'title' => $x('Our new website is live'),
                'intro' => '<p>' . e($x('Welcome to our new website. This is where we will share news about our work, projects and offers.')) . '</p>',
                'text' => '<p>' . e($x('We have rebuilt the website so that it is easier to see what we do and how to get in touch. Have a look around – and if you have a question, just write to us.')) . '</p>',
                'category_id' => $category,
                'author_id' => $admin,
                'published_at' => date('Y-m-d H:i:s'),
                'visible' => 1,
            ]);
        });
    }

    /** The looks topics a business fits: its blueprint's, or a general business site's. @return list<string> */
    public static function topicsOf(string $blueprint): array
    {
        return $blueprint === '' ? self::GENERAL_TOPICS : (Blueprint::available()[$blueprint]['topics'] ?? []);
    }

    private function siteUrl(): string
    {
        return rtrim(Config::env('SITE_URL'), '/') ?: $this->request->origin();
    }

    /** @param array<string, mixed> $data */
    private function page(string $template, array $data): Response
    {
        return Response::html($this->view->render('install/' . $template, $data + [
            'base' => $this->request->basePath(), 'language' => \Talea\Core\Language::code(), 'register' => $this->register,
            'languages' => array_intersect_key(\Talea\Core\Language::ADMIN_LANGUAGES, self::TIME_ZONES),
        ]));
    }
}
