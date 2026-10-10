<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Core\Uuid;
use Kaleta\Tests\Site\Support\Site;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * HF-16: the export carries public ids and no integer keys; an import into a fresh site keeps the public ids and points every
 * relation at the new keys; exporting the new site again gives the same public ids per table.
 */
#[Group('site')]
final class PublicIdsExportTest extends SiteTestCase
{
    use SiteFixtures;

    /** Tables of the export that have a public id, with the integer key column that must not be in the file. */
    private const array TABLES = ['pages' => 'page_id', 'categories' => 'category_id', 'tags' => 'tag_id', 'news' => 'news_id', 'redirects' => 'redirect_id', 'components' => 'component_id',
        'collections' => 'collection_id', 'collection_items' => 'item_id', 'popups' => 'popup_id', 'media' => 'media_id', 'media_folders' => 'folder_id', 'booking_services' => 'id', 'booking_staff' => 'id'];

    private static ?Site $moved = null;
    /** @var array<string, mixed> */
    private static array $old = [];
    /** @var array<string, string> the public ids the test created: parent, child, component, popup, service, staff */
    private static array $ids = [];

    public static function tearDownAfterClass(): void
    {
        self::$moved?->close();
        self::$moved = null;
        parent::tearDownAfterClass();
    }

    /** The decoded content.json of the newest export of a site. @return array<string, mixed> */
    private function exportOf(Site $site): array
    {
        $site->admin()->post('/admin.php?module=transfer&action=export', ['_csrf' => $site->csrf()]);
        preg_match_all('/export-[0-9]*-[0-9]*\.zip/', $site->admin()->get('/admin.php?module=transfer')->body, $m);
        $file = $m[0] === [] ? '' : max($m[0]); // the newest by its name; a site that imported an export lists that file as well
        $this->assertNotSame('', $file, 'the export is listed');
        $zipFile = $site->workDir('ids') . '/' . $file;
        file_put_contents($zipFile, $site->admin()->get('/admin.php?module=transfer&action=download&file=' . $file)->body);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile) === true, 'the export is a ZIP');
        $raw = (string) $zip->getFromName('content.json');
        $content = json_decode($raw, true);
        $zip->close();
        $this->assertIsArray($content, 'content.json is JSON');
        $content['_zip'] = $zipFile;
        $content['_raw'] = $raw;

        return $content;
    }

    /** @param array<string, mixed> $content @return list<string> */
    private function publicIds(array $content, string $table): array
    {
        $ids = array_column($content[$table] ?? [], 'public_id');
        sort($ids);

        return $ids;
    }

    public function testTheExportCarriesPublicIdsAndNoIntegerKeys(): void
    {
        $site = $this->site();
        $this->createTeam($site);
        $this->uploadPhoto($site);
        $site->admin()->post('/admin.php?module=redirects&action=save', ['_csrf' => $site->csrf(), 'from_path' => '/ids-old', 'to_path' => '/contact', 'type' => 301]);

        $site->exec("INSERT INTO ka_booking_services (name) VALUES ('Ids service')");
        $site->exec("INSERT INTO ka_booking_staff (name) VALUES ('Ids person')");
        $service = (int) $site->value("SELECT id FROM ka_booking_services WHERE name = 'Ids service'");
        $staff = (int) $site->value("SELECT id FROM ka_booking_staff WHERE name = 'Ids person'");
        $site->exec('INSERT INTO ka_booking_staff_services (staff_id, service_id) VALUES (?, ?)', [$staff, $service]);
        $heading = fn (string $id, string $text): array => ['id' => $id, 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => $text]];
        $site->exec("INSERT INTO ka_components (name, properties, build, updated_at) VALUES ('Ids card', '[]', ?, NOW())", [json_encode(['v' => 1, 'children' => [$heading('idc1', 'Card')]])]);
        $component = (int) $site->value("SELECT component_id FROM ka_components WHERE name = 'Ids card'");
        $site->exec("INSERT INTO ka_pages (slug, title, text, visible, updated_at) VALUES ('ids-parent', 'Ids parent', '', 1, NOW())");
        $parent = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'ids-parent'");
        $build = json_encode(['v' => 1, 'children' => [$heading('idh1', 'Child'),
            ['id' => 'idk1', 'type' => 'component', 'content' => ['component' => (string) $component]],
            ['id' => 'idb1', 'type' => 'booking', 'content' => ['service' => $service, 'staff_member' => $staff]]]]);
        $site->exec("INSERT INTO ka_pages (slug, title, text, visible, parent_id, build, updated_at) VALUES ('ids-parent/ids-child', 'Ids child', '', 1, ?, ?, NOW())", [$parent, $build]);
        $child = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'ids-parent/ids-child'");
        $site->exec("INSERT INTO ka_pages (slug, title, text, visible, language, translation_of, updated_at) VALUES ('ids-eltern', 'Ids Eltern', '', 1, 'de', ?, NOW())", [$parent]);
        $site->exec("INSERT INTO ka_popups (name, slug, rules, build, updated_at) VALUES ('Ids popup', 'ids-popup', ?, ?, NOW())", [json_encode(['where' => 'selected', 'pages' => [$child]]), json_encode(['v' => 1, 'children' => [$heading('idp1', 'Popup')]])]);
        $site->exec("INSERT INTO ka_menus (location, language, items, updated_at) VALUES ('main', '', ?, NOW()) ON DUPLICATE KEY UPDATE items = VALUES(items)",
            [json_encode([['type' => 'page', 'page_id' => $child, 'text' => '', 'new_window' => false, 'children' => []]])]);
        $site->exec("INSERT INTO ka_tags (name, slug) VALUES ('Ids tag', 'ids-tag')");
        $site->exec('INSERT INTO ka_news_tags (news_id, tag_id) SELECT MIN(news_id), (SELECT tag_id FROM ka_tags WHERE slug = ?) FROM ka_news', ['ids-tag']);
        $site->exec("INSERT INTO ka_media_folders (name) VALUES ('Ids folder')");
        $site->exec("UPDATE ka_media SET folder_id = (SELECT folder_id FROM ka_media_folders WHERE name = 'Ids folder') WHERE media_id = (SELECT media_id FROM (SELECT MIN(media_id) AS media_id FROM ka_media) m)");
        $site->exec("UPDATE ka_pages SET translation_of = NULL WHERE page_id = ?", [$child]);

        self::$ids = ['parent' => $site->publicId('pages', $parent), 'child' => $site->publicId('pages', $child), 'component' => $site->publicId('components', $component),
            'popup' => (string) $site->value("SELECT public_id FROM ka_popups WHERE slug = 'ids-popup'"), 'service' => $site->publicId('booking_services', $service), 'staff' => $site->publicId('booking_staff', $staff)];

        $content = self::$old = $this->exportOf($site);
        $this->assertSame(3, $content['format_version'], 'the format with public ids');
        $this->assertGreaterThan(0, count($content['media'] ?? []), 'the export has media rows');
        foreach (self::TABLES as $table => $key) {
            foreach ($content[$table] ?? [] as $row) {
                $this->assertTrue(Uuid::valid($row['public_id'] ?? null), "$table: every row has a public id");
                $this->assertArrayNotHasKey($key, $row, "$table: the integer key $key is not in the file");
            }
        }
        $byId = array_column($content['pages'], null, 'public_id');
        $this->assertSame(self::$ids['parent'], $byId[self::$ids['child']]['parent_id'], 'a parent page is its public id');
        $this->assertNotNull(array_values(array_filter($content['pages'], fn (array $p): bool => ($p['translation_of'] ?? null) === self::$ids['parent'])), 'a translation points at the public id');
        $this->assertSame($site->publicId('pages', (int) $site->settingValue('home_page')), $content['settings']['home_page'], 'the home page setting is a public id');
        $this->assertStringContainsString('"component":"' . self::$ids['component'] . '"', (string) json_encode($byId[self::$ids['child']]['build']) . (string) $byId[self::$ids['child']]['build'], 'a component element refers to the public id');
        $this->assertStringContainsString(self::$ids['service'], (string) $byId[self::$ids['child']]['build'], 'a booking element refers to the public id of the service');
        $popup = array_values(array_filter($content['popups'], fn (array $p): bool => $p['public_id'] === self::$ids['popup']))[0];
        $this->assertStringContainsString(self::$ids['child'], (string) $popup['rules'], 'the pages of a pop-up are public ids');
        $this->assertStringContainsString(self::$ids['child'], (string) $content['menus'][0]['items'] . (string) ($content['menus'][1]['items'] ?? ''), 'a menu page is a public id');
        $this->assertContains(self::$ids['staff'], array_column($content['booking_staff_services'], 'staff_id'), 'a link table is public ids');
        $this->assertDoesNotMatchRegularExpression('/"(page_id|category_id|news_id|tag_id|component_id|popup_id|item_id|media_id|folder_id|collection_id)":\d/', $content['_raw'], 'no integer key of a public-id table anywhere in content.json');
    }

    #[Depends('testTheExportCarriesPublicIdsAndNoIntegerKeys')]
    public function testAnImportKeepsThePublicIdsAndPointsRelationsAtTheNewKeys(): void
    {
        self::$moved = Site::boot(['web' => 'export', 'siteName' => 'Ids new', 'extensions' => ['news']]);
        $new = self::$moved;
        $new->setting('tasks_token', 'own-' . bin2hex(random_bytes(8)));
        $admin = $new->admin();
        $upload = $admin->upload('/admin.php?module=transfer&action=upload', ['_csrf' => $new->csrf()], ['file' => self::$old['_zip']]);
        $file = substr($upload->redirect, (int) strrpos($upload->redirect, 'file=') + 5);
        $admin->post('/admin.php?module=transfer&action=kaleta_run', ['_csrf' => $new->csrf(), 'file' => $file, 'confirmation' => 1]);
        $result = null;
        for ($i = 0; $i < 80; $i++) {
            $result = $admin->post('/admin.php?module=transfer&action=kaleta', ['_csrf' => $new->csrf(), 'file' => $file]);
            if ($result->contains('The site has been imported') || $result->contains('The import has stopped')) {
                break;
            }
        }
        $this->assertStringContainsString('The site has been imported', (string) $result?->body, 'the import went through');

        foreach (array_keys(self::TABLES) as $table) {
            $rows = $table === 'pages' || $table === 'news' || $table === 'collection_items' ? ' WHERE deleted_at IS NULL' : '';
            $this->assertSame($this->publicIds(self::$old, $table), array_values(array_map(fn (array $r): string => $r['public_id'], $new->rows("SELECT public_id FROM ka_$table$rows ORDER BY public_id"))), "$table: the imported rows keep their public ids");
        }
        $parent = $new->internalId('pages', self::$ids['parent']);
        $child = $new->internalId('pages', self::$ids['child']);
        $component = $new->internalId('components', self::$ids['component']);
        $this->assertSame($parent, (int) $new->value('SELECT parent_id FROM ka_pages WHERE page_id = ?', [$child]), 'the parent relation points at the new key');
        $this->assertSame((string) $parent, (string) $new->value("SELECT translation_of FROM ka_pages WHERE slug = 'ids-eltern'"), 'the translation relation points at the new key');
        $build = json_decode((string) $new->value('SELECT build FROM ka_pages WHERE page_id = ?', [$child]), true);
        $this->assertSame((string) $component, (string) ($build['children'][1]['content']['component'] ?? ''), 'a component element points at the new component');
        $this->assertSame([$new->internalId('booking_services', self::$ids['service']), $new->internalId('booking_staff', self::$ids['staff'])],
            [(int) $build['children'][2]['content']['service'], (int) $build['children'][2]['content']['staff_member']], 'a booking element points at the new service and person');
        $this->assertSame([$child], json_decode((string) $new->value("SELECT rules FROM ka_popups WHERE slug = 'ids-popup'"), true)['pages'], 'the pages of a pop-up are the new keys');
        $this->assertStringContainsString('"page_id":' . $child, (string) $new->value("SELECT items FROM ka_menus WHERE location = 'main' AND language = ''"), 'a menu page points at the new key');
        $this->assertSame((string) $new->internalId('pages', self::$old['settings']['home_page']), $new->settingValue('home_page'), 'the home page setting points at the new key');
        $this->assertSame('1', (string) $new->value('SELECT COUNT(*) FROM ka_news_tags nt JOIN ka_tags t ON t.tag_id = nt.tag_id WHERE t.slug = ?', ['ids-tag']), 'a tag link of a news item is kept');
        $this->assertSame('1', (string) $new->value("SELECT COUNT(*) FROM ka_media m JOIN ka_media_folders f ON f.folder_id = m.folder_id WHERE f.name = 'Ids folder'"), 'a media folder relation is kept');
        $this->assertSame('1', (string) $new->value('SELECT COUNT(*) FROM ka_booking_staff_services'), 'the person and the service stay linked');
        $this->assertSame(200, $new->client()->get('/' . $new->value('SELECT slug FROM ka_pages WHERE page_id = ?', [$child]))->status, 'the moved child page is served');
    }

    #[Depends('testAnImportKeepsThePublicIdsAndPointsRelationsAtTheNewKeys')]
    public function testExportingTheNewSiteAgainGivesTheSamePublicIds(): void
    {
        $again = $this->exportOf(self::$moved ?? throw new \LogicException('The new site is not installed.'));
        foreach (array_keys(self::TABLES) as $table) {
            $this->assertSame($this->publicIds(self::$old, $table), $this->publicIds($again, $table), "$table: the re-export has the same public ids");
        }
        $this->assertSame(self::$old['settings']['home_page'], $again['settings']['home_page'], 'the home page is the same public id');
        $byId = array_column($again['pages'], null, 'public_id');
        $this->assertSame(self::$ids['parent'], $byId[self::$ids['child']]['parent_id'], 'the relations are the same public ids after the round trip');
        $this->assertStringContainsString('"component":"' . self::$ids['component'] . '"', (string) $byId[self::$ids['child']]['build'], 'a component element is the same public id after the round trip');
    }
}
