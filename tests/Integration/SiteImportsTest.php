<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Builder\Build;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\Settings;
use Talea\Core\SiteExport;
use Talea\Core\SiteImport;
use Talea\Core\SiteImports;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Bringing an existing single-site install in as a NEW site (docs/specs/multisite.md, "Bringing existing sites in"): an archive made by
 * SiteExport::create on site 1 is imported as site 2 of owner 1 (where every public id and media path of the archive is already taken) and
 * as a site of owner 2, by SiteImports::work, the way cron does. The imports run once, in the class's set-up, outside the tests'
 * transactions; the tests read the result.
 */
#[CoversClass(SiteImports::class)]
#[CoversClass(SiteImport::class)]
final class SiteImportsTest extends DatabaseTestCase
{
    private const string SECRET = 'stolen-by-the-archive';

    private static bool $made = false;

    /** @var array<string, mixed> what was true while the first import was half-way, and the facts of the old site */
    private static array $facts = [];

    /** @var list<string> files the test made, removed afterwards (files only) */
    private static array $files = [];

    /** @var list<string> folders the test made (storage/import/sites/<prefix>, media/<owner public id>), removed with what is in them */
    private static array $folders = [];

    protected function setUp(): void
    {
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                putenv('TALEA_MULTISITE=1');
                putenv('TALEA_OWNERS=1');
                try {
                    $this->build($config);
                } finally {
                    putenv('TALEA_OWNERS');
                    putenv('TALEA_MULTISITE');
                }
                self::$made = true;
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
        Sites::forgetCurrent();
    }

    protected function tearDown(): void
    {
        putenv('TALEA_OWNERS');
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $file) {
            is_file($file) && @unlink($file);
        }
        foreach (self::$folders as $folder) {
            if (preg_match('#/(storage/import/sites/[a-z0-9]+_|media/[0-9a-f-]{36})$#', $folder) === 1) {
                self::removeTree($folder);
            }
        }
        self::$files = self::$folders = [];
        self::$made = false;
        Sites::forgetCurrent();
        parent::tearDownAfterClass();
    }

    private static function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function photo(string $path): void
    {
        $full = TALEA_ROOT . '/' . $path;
        @mkdir(dirname($full), 0775, true);
        $image = imagecreatetruecolor(40, 30);
        imagejpeg($image, $full);
        self::$files[] = $full;
    }

    /** The old single-site install (site 1 with its content), its export, and the imports. */
    private function build(array $config): void
    {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        $tag = bin2hex(random_bytes(3));
        $admin = $db->insert('users', ['username' => 'imp-admin-' . $tag, 'password' => 'x', 'name' => 'Importer', 'email' => 'imp-' . $tag . '@example.test', 'admin' => 2, 'language' => '', 'confirmed_at' => $now]);
        // owner 1's other site content that must survive: a media file and a shared component the archive never sees
        $this->photo($survivor = 'media/2026/10/imp-survivor-' . $tag . '.jpg');
        $survivorId = $db->insert('media', ['image_path' => $survivor, 'name' => 'Survivor', 'created_at' => $now]);
        $keeper = $db->insert('components', ['name' => 'Keeper', 'properties' => '{}', 'site_id' => null, 'updated_at' => $now]);

        // the old install: a folder, two photos (one with a variant), a component, a category, a tag, pages, news and a collection-free page tree
        $this->photo($main = 'media/2026/10/imp-' . $tag . '-photo.jpg');
        $this->photo('media/2026/10/imp-' . $tag . '-photo-1200.jpg');
        $folder = $db->insert('media_folders', ['name' => 'Team']);
        $db->insert('media', ['image_path' => $main, 'thumb_path' => 'media/2026/10/imp-' . $tag . '-photo-1200.jpg', 'name' => 'Old photo', 'folder_id' => $folder, 'created_at' => $now]);
        $component = $db->insert('components', ['name' => 'Hero', 'properties' => '{}', 'site_id' => 1, 'updated_at' => $now,
            'build' => Build::toJson(Build::sanitize(['v' => 1, 'children' => [['id' => 'hh', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Hero text']]]])[0])]);
        $db->insert('facts', ['fact_key' => 'phone_imp', 'language' => '', 'label' => 'Phone', 'type' => 'text', 'value' => '123 456', 'updated_at' => $now, 'site_id' => 1]);
        $category = $db->insert('categories', ['name' => 'Stories', 'slug' => 'stories', 'description' => '']);
        $tagId = $db->insert('tags', ['name' => 'Old', 'slug' => 'old', 'description' => '', 'image' => '']);
        $build = Build::toJson(Build::sanitize(['v' => 1, 'children' => [['id' => 'a1', 'type' => 'component', 'content' => ['component' => (string) $component]], ['id' => 'a2', 'type' => 'image', 'content' => ['src' => $main, 'alt' => 'x']]]])[0]);
        foreach (['about' => 'About us', 'contact' => 'Contact'] as $slug => $title) {
            $db->insert('pages', ['title' => $title, 'slug' => $slug, 'description' => '', 'text' => '<p>' . $title . ' <img src="/' . $main . '" alt=""></p>', 'visible' => 1, 'in_menu' => 1, 'sort_order' => 1, 'updated_at' => $now, 'build' => $build]);
        }
        $news = $db->insert('news', ['title' => 'First story', 'slug' => 'first-story', 'text' => '<p>Story <img src="/' . $main . '" alt=""></p>', 'intro' => '', 'category_id' => $category, 'author_id' => $admin, 'published_at' => $now, 'visible' => 1]);
        $db->insert('news_tags', ['news_id' => $news, 'tag_id' => $tagId]);
        $db->upsert('settings', ['name' => 'site_name', 'value' => 'Old site'], ['name']);
        $db->upsert('settings', ['name' => 'smtp_host', 'value' => 'mail.owner.test'], ['name']);

        // the archive, with secrets the export never has smuggled into its settings
        $archive = SiteExport::create($db, new Settings($db))['file'];
        $path = \Talea\Core\Backup::FOLDER . '/' . $archive;
        self::$files[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName('content.json');
        $content = str_replace('"settings":{', '"settings":{"ai_key":"' . self::SECRET . '","secret_key":"' . self::SECRET . '","tasks_token":"' . self::SECRET . '","smtp_password":"' . self::SECRET . '",', $content);
        $zip->addFromString('content.json', $content);
        $zip->close();

        $copy = fn (string $from, string $to): bool => copy($from, $to);
        $beta = Owners::add($db, 'Beta', 'organisation', 'boss@beta.example');
        if (Owners::provisionPending($db, $config) !== ['owner ' . $beta . ': ready']) {
            throw new \RuntimeException('The owner was not set up.');
        }
        $betaDb = $db->forOwner($beta);
        $one = SiteImports::create($db, 'Imported', $path, 'zip', $admin, $copy);
        $two = SiteImports::create($betaDb, 'Beta imported', $path, 'zip', 0, $copy);
        self::$folders[] = SiteImports::folder($one);
        self::$folders[] = SiteImports::folder($two);
        Migrator::migrate($config);
        Sites::forgetCurrent();

        // owner 1: the first run stops after the preparation (a zero budget), and the other site goes on working meanwhile
        $site = (array) $db->one('SELECT * FROM {sites} WHERE prefix = ?', [$one]);
        $line = SiteImports::work($db, $site, 0.0);
        $db->upsert('settings', ['name' => 'during_import', 'value' => 'written'], ['name']); // site 1 still takes writes
        self::$facts = ['half' => $line, 'during' => [
            'ready' => $db->value('SELECT ready_at FROM {sites} WHERE prefix = ?', [$one]), 'site1' => (int) $db->value('SELECT COUNT(*) FROM {pages}'),
            'running' => SiteImport::running(), 'media' => (int) $db->value('SELECT COUNT(*) FROM {media}'),
        ], 'survivor' => $survivorId, 'keeper' => $keeper, 'main' => $main, 'tag' => $tag, 'admin' => $admin, 'component' => $component, 'survivorPath' => $survivor,
            'beta' => $beta, 'one' => $one, 'two' => $two, 'mediaBefore' => (int) $db->value('SELECT COUNT(*) FROM {media}'), 'componentsBefore' => (int) $db->value('SELECT COUNT(*) FROM {components}')];
        for ($i = 0; $i < 50 && ($line = SiteImports::work($db, (array) $db->one('SELECT * FROM {sites} WHERE prefix = ?', [$one]), 30.0)) !== 'ready'; $i++) {
            self::$facts['lines'][] = $line;
        }
        self::$facts['one_line'] = $line;
        $siteTwo = (array) $betaDb->one('SELECT * FROM {sites} WHERE prefix = ?', [$two]);
        for ($i = 0; $i < 50 && ($line = SiteImports::work($betaDb, $siteTwo, 30.0)) !== 'ready'; $i++) {
            $siteTwo = (array) $betaDb->one('SELECT * FROM {sites} WHERE prefix = ?', [$two]);
        }
        self::$facts['two_line'] = $line;
        // media files the imports wrote
        foreach ($db->all("SELECT image_path, thumb_path FROM {media} WHERE image_path LIKE ?", ['%' . $tag . '%']) as $m) {
            self::$files[] = TALEA_ROOT . '/' . $m['image_path'];
            $m['thumb_path'] !== '' && self::$files[] = TALEA_ROOT . '/' . $m['thumb_path'];
            self::$files[] = TALEA_ROOT . '/' . preg_replace('/\.jpg$/', '-1200.jpg', $m['image_path']);
        }
        self::$folders[] = TALEA_ROOT . '/media/' . Owners::publicIdOf($db, $beta);
    }

    public function testTheImportRunsInBatchesAndOtherSitesKeepWorkingMeanwhile(): void
    {
        $this->assertMatchesRegularExpression('/^importing \d+ %$/', self::$facts['half']);
        $this->assertNull(self::$facts['during']['ready'], 'the site is not ready while it imports');
        $this->assertNull(self::$facts['during']['running'], 'no install-wide import is marked as running: no site shows a maintenance notice');
        $this->assertSame(2, self::$facts['during']['site1'], 'site 1 serves its own pages meanwhile');
        $this->assertSame('ready', self::$facts['one_line']);
        $this->assertSame('ready', self::$facts['two_line']);
        $this->assertNotNull($this->db()->value('SELECT ready_at FROM {sites} WHERE prefix = ?', [self::$facts['one']]));
        $this->assertSame('', $this->db()->value('SELECT import_file FROM {sites} WHERE prefix = ?', [self::$facts['one']]));
        $this->assertDirectoryDoesNotExist(SiteImports::folder(self::$facts['one']), 'the archive and the working files are gone when it is done');
    }

    public function testContentComesOverAndRendersTheSame(): void
    {
        $db = $this->db();
        $new = $db->forSite(self::$facts['one']);
        $this->assertSame(2, (int) $new->value('SELECT COUNT(*) FROM {pages}'));
        $this->assertSame(1, (int) $new->value('SELECT COUNT(*) FROM {news}'));
        $this->assertSame(1, (int) $new->value('SELECT COUNT(*) FROM {news_tags}'));
        $this->assertSame('Old site', $new->value("SELECT value FROM {settings} WHERE name = 'site_name'"));
        $old = $db->one("SELECT title, slug, text, build FROM {pages} WHERE slug = 'about'");
        $page = $new->one("SELECT title, slug, text, build FROM {pages} WHERE slug = 'about'");
        $newComponent = (int) $db->value("SELECT component_id FROM {components} WHERE name = 'Hero' AND site_id = ?", [(int) $db->value('SELECT site_id FROM {sites} WHERE prefix = ?', [self::$facts['one']])]);
        $this->assertGreaterThan(0, $newComponent);
        $this->assertNotSame(self::$facts['component'], $newComponent);
        // the page text follows the media file to its new name; the component element points at the new component
        $newPath = (string) $db->value("SELECT image_path FROM {media} WHERE name = 'Old photo' AND image_path <> ?", [self::$facts['main']]);
        $this->assertNotSame('', $newPath, 'the photo came in under a new name: the old one is in use by site 1');
        $this->assertSame(str_replace(self::$facts['main'], $newPath, (string) $old['text']), $page['text']);
        $this->assertSame(str_replace(['"component":"' . self::$facts['component'] . '"', self::$facts['main']], ['"component":"' . $newComponent . '"', $newPath], (string) $old['build']), $page['build']);
        $this->assertFileExists(TALEA_ROOT . '/' . $newPath);
        $this->assertFileExists(TALEA_ROOT . '/' . preg_replace('/\.jpg$/', '-1200.jpg', $newPath), 'the variant stays beside its main file');
    }

    public function testSharedTablesOfTheOwnerAreOnlyAddedTo(): void
    {
        $db = $this->db();
        $this->assertSame(self::$facts['survivor'], (int) $db->value('SELECT media_id FROM {media} WHERE image_path = ?', [self::$facts['survivorPath']]), 'a file of another site survives');
        $this->assertNotNull($db->value('SELECT 1 FROM {components} WHERE component_id = ? AND site_id IS NULL', [self::$facts['keeper']]), 'a shared component survives');
        $this->assertNotNull($db->value("SELECT 1 FROM {components} WHERE component_id = ? AND name = 'Hero'", [self::$facts['component']]), 'site 1 keeps its own component');
        $this->assertSame(self::$facts['mediaBefore'] + 2, (int) $db->value('SELECT COUNT(*) FROM {media}'), 'the archive\'s two media rows were added, none was replaced');
        $this->assertSame(self::$facts['componentsBefore'] + 2, (int) $db->value('SELECT COUNT(*) FROM {components}'), 'so were its two components (the shared one arrives as the site\'s own)');
        $site = (int) $db->value('SELECT site_id FROM {sites} WHERE prefix = ?', [self::$facts['one']]);
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM {components} WHERE site_id = ? AND name = 'Hero'", [$site]), 'the component is the new site\'s own');
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM {facts} WHERE site_id = ? AND fact_key = 'phone_imp'", [$site]), 'so is the fact');
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM {facts} WHERE site_id = 1 AND fact_key = 'phone_imp'"), 'and site 1 keeps its own');
        $this->assertSame(2, (int) $db->value("SELECT COUNT(*) FROM {media_folders} WHERE name = 'Team'"), 'the folder was added beside the old one');
        $this->assertNotSame($db->value('SELECT public_id FROM {components} WHERE component_id = ?', [self::$facts['component']]), $db->value("SELECT public_id FROM {components} WHERE name = 'Hero' AND site_id = ?", [$site]), 'an id the archive brought that was taken got a new one');
    }

    public function testAuthorshipGoesToTheImportingAdministratorAndNoSecretComesAlong(): void
    {
        $db = $this->db();
        $this->assertSame(self::$facts['admin'], (int) $db->forSite(self::$facts['one'])->value('SELECT author_id FROM {news}'));
        $new = $db->forSite(self::$facts['one']);
        foreach (['ai_key', 'secret_key', 'tasks_token', 'smtp_password'] as $name) {
            $this->assertNotSame(self::SECRET, $new->value('SELECT value FROM {settings} WHERE name = ?', [$name]), $name . ' of the archive is not imported');
        }
        $this->assertSame('mail.owner.test', $new->value("SELECT value FROM {settings} WHERE name = 'smtp_host'"), 'the owner\'s own account settings are taken over as for an empty site');
        $people = json_decode((string) $db->value('SELECT import_people FROM {sites} WHERE prefix = ?', [self::$facts['one']]), true);
        $this->assertIsArray($people);
        $this->assertContains('Importer', array_column($people, 'name'), 'the account list of the archive is kept for the administrator');
    }

    public function testASiteOfAnotherOwnerGetsTheFilesInItsOwnFolderAndNoAuthors(): void
    {
        $db = $this->db();
        $beta = $db->forOwner(self::$facts['beta']);
        $new = $beta->forSite(self::$facts['two']);
        $folder = Owners::publicIdOf($db, self::$facts['beta']);
        $this->assertSame(2, (int) $new->value('SELECT COUNT(*) FROM {pages}'));
        $this->assertNull($new->value('SELECT author_id FROM {news}'), 'an operator is no user of the client: the news have no author');
        $path = (string) $beta->value("SELECT image_path FROM {media} WHERE name = 'Old photo'");
        $this->assertSame('media/' . $folder . '/2026/10/imp-' . self::$facts['tag'] . '-photo.jpg', $path);
        $this->assertFileExists(TALEA_ROOT . '/' . $path);
        $this->assertStringContainsString($path, (string) $new->value("SELECT text FROM {pages} WHERE slug = 'about'"), 'the content follows the files into the owner\'s folder');
        $this->assertSame(0, (int) $beta->value("SELECT COUNT(*) FROM {media} WHERE image_path = ?", [self::$facts['main']]));
        $this->assertSame(self::$facts['mediaBefore'] + 2, (int) $db->value('SELECT COUNT(*) FROM {media}'), 'owner 1\'s library did not change for it (only its own import added)');
    }

    public function testAFailedImportLeavesTheSiteNotReadyWithTheErrorAndTheOwnerRowsUntouched(): void
    {
        $db = $this->db();
        $this->db()->pdo()->rollBack(); // the new site's tables are DDL
        $file = sys_get_temp_dir() . '/not-talea-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('content.json', '{"format":"other"}');
        $zip->close();
        self::$files[] = $file;
        try {
            SiteImports::create($db, 'Broken', $file, 'zip', 1, copy(...));
            $this->fail('A file that is not a Talea export is refused before a site is made.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not a Talea export', $e->getMessage());
        }
        $this->assertNull($db->value("SELECT 1 FROM {sites} WHERE name = 'Broken'"), 'no site row for a refused file');

        // a file that passes the first look but is not importable: the site stays not ready and says why
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('content.json', '{"format":"talea-export","format_version":2,"talea":"1.0.0","settings":{}' . "\n}");
        $zip->close();
        $prefix = SiteImports::create($db, 'Too old', $file, 'zip', 1, copy(...));
        self::$folders[] = SiteImports::folder($prefix);
        $mediaBefore = (int) $db->value('SELECT COUNT(*) FROM {media}');
        Migrator::migrate($this->dbConfig());
        Sites::forgetCurrent();
        $site = (array) $db->one('SELECT * FROM {sites} WHERE prefix = ?', [$prefix]);
        $line = SiteImports::work($db, $site, 30.0);

        $this->assertStringStartsWith('error: ', $line);
        $this->assertStringContainsString('before public ids', (string) $db->value('SELECT import_error FROM {sites} WHERE prefix = ?', [$prefix]));
        $this->assertNull($db->value('SELECT ready_at FROM {sites} WHERE prefix = ?', [$prefix]));
        $this->assertSame($mediaBefore, (int) $db->value('SELECT COUNT(*) FROM {media}'));
        $this->assertNotContains($prefix, array_column(Sites::pending($db), 'prefix'), 'a failed import waits for a person: cron does not retry it');
        $this->assertDirectoryDoesNotExist(SiteImports::folder($prefix));
    }

    public function testRowsAnImportAddedAreTakenOutAgain(): void
    {
        $db = $this->db();
        $new = $db->forSite(self::$facts['one']);
        $site = (array) $db->one('SELECT * FROM {sites} WHERE prefix = ?', [self::$facts['one']]);
        // a second try on the finished site's folder: the components of an earlier try are removed, none is left over
        $folder = SiteImports::folder(self::$facts['one']);
        @mkdir($folder, 0775, true);
        $state = ['file' => 'archive.zip', 'folder' => $folder] + SiteImport::newState('archive.zip', $folder);
        $import = new SiteImport($new, new Settings($new), 0, 'media');
        file_put_contents($folder . '/talea-' . substr(sha1('archive.zip'), 0, 16) . '-x', '');
        $work = $folder . '/talea-' . substr(sha1('archive.zip'), 0, 16);
        @mkdir($work, 0775, true);
        file_put_contents($work . '/owner-rows.txt', 'components ' . self::$facts['keeper'] . "\n");
        $import->undoOwnerRows($state);
        $this->assertNull($db->value('SELECT 1 FROM {components} WHERE component_id = ?', [self::$facts['keeper']]), 'what an import recorded is deleted');
        $this->assertFileDoesNotExist($work . '/owner-rows.txt');
        unset($site);
        SiteImports::forget(self::$facts['one']);
    }
}
