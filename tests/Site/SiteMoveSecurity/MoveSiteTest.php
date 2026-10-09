<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\Site;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: section 38 of tools/test.sh – moving a site: the export of this site is imported into a NEW installation ("Start from an
 * export", a second Site booted inside the class). Needs from earlier sections: content with a collection, media, a redirect.
 */
#[Group('site')]
final class MoveSiteTest extends SiteTestCase
{
    use SiteFixtures;

    private const string N6_PAYLOAD = '<p class="n6" onclick="alert(1)">N6 check</p><script>alert(1)</script>';

    private static ?Site $moved = null;
    /** The name the uploaded export got on the new site. */
    private static string $file = '';

    public static function tearDownAfterClass(): void
    {
        self::$moved?->close();
        self::$moved = null;
        parent::tearDownAfterClass();
    }

    private function moved(): Site
    {
        return self::$moved ?? throw new \LogicException('The new site is not installed yet.');
    }

    private function counts(Site $site): string
    {
        return (string) $site->value("SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_stranky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_novinky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_kategorie),
            (SELECT COUNT(*) FROM ka_kolekce), (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_komponenty), (SELECT COUNT(*) FROM ka_tridy), (SELECT COUNT(*) FROM ka_menu),
            (SELECT COUNT(*) FROM ka_popupy), (SELECT COUNT(*) FROM ka_presmerovani), (SELECT COUNT(*) FROM ka_media), (SELECT COUNT(*) FROM ka_novinky_stitky ns JOIN ka_novinky n ON n.idc = ns.idc WHERE n.smazano IS NULL))");
    }

    private function sameNumbers(Site $site): string
    {
        return (string) $site->value("SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))");
    }

    /** Builds the content of the old site and downloads its export; returns the export file. */
    public function testTheOldSiteExportsItsContentWithMedia(): string
    {
        $site = $this->site();
        $this->createTeam($site);
        $this->uploadPhoto($site);
        $site->admin()->post('/admin.php?module=redirects&action=save', ['_csrf' => $site->csrf(), 'z_adresy' => '/akce-leto', 'na_adresu' => '/kontakty', 'typ' => 302]);
        $site->mcp('write_notebook', ['topic' => 'history', 'title' => 'Historie redesignu', 'text' => 'Web přešel na Kaletu v říjnu 2026.']); // 2.15: the notebook moves with the site
        $site->exec("INSERT INTO ka_booking_services (name) VALUES ('Move test')"); // 3.2: a booking set-up travels with the site
        $site->exec('UPDATE ka_novinky SET text = CONCAT(text, ?) WHERE smazano IS NULL ORDER BY idc LIMIT 1', [self::N6_PAYLOAD]); // 3.3.2: an archive from anywhere brings no script

        // 3.3.3 (N55, N50): company_map, a social link and a text fact "javascript:…" – the import drops them, a valid link and an ordinary fact stay
        $saved = $site->rows("SELECT promenna, hodnota FROM ka_nastaveni WHERE promenna IN ('company_map', 'social_facebook', 'social_linkedin')");
        $site->setting('company_map', 'javascript:alert(1)');
        $site->setting('social_facebook', ' JavaScript:alert(2)');
        $site->setting('social_linkedin', 'https://www.linkedin.com/company/n55');
        $site->exec("INSERT INTO ka_facts (fact_key, language, label, type, value, updated_at) VALUES ('n55promo', '', 'N55', 'text', 'javascript:alert(3)', NOW()), ('n55note', '', 'N55', 'text', 'Note: open daily', NOW())");

        $this->adminPost('/admin.php?module=transfer&action=export', [], '/admin.php?module=transfer');

        $site->exec("DELETE FROM ka_nastaveni WHERE promenna IN ('company_map', 'social_facebook', 'social_linkedin')");
        foreach ($saved as $row) {
            $site->setting($row['promenna'], $row['hodnota']);
        }
        $site->exec("DELETE FROM ka_facts WHERE fact_key IN ('n55promo', 'n55note')");

        $page = $site->admin()->get('/admin.php?module=transfer');
        preg_match('/export-[0-9]*-[0-9]*\.zip/', $page->body, $m);
        $export = $m[0] ?? '';
        $this->assertNotSame('', $export, 'the export is listed');
        $zipFile = $site->workDir('move') . '/presun.zip';
        file_put_contents($zipFile, $site->admin()->get('/admin.php?module=transfer&action=download&file=' . $export)->body);
        $site->exec("DELETE FROM ka_booking_services WHERE name = 'Move test'");

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile) === true, 'the export is a ZIP');
        $media = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $media += preg_match('#^media/.#', (string) $zip->getNameIndex($i)) === 1 ? 1 : 0;
        }
        $this->assertGreaterThan(0, $media, "the export carries the media ($media files)");

        return $zipFile;
    }

    #[Depends('testTheOldSiteExportsItsContentWithMedia')]
    public function testStartFromAnExportInstallsAnEmptySite(): void
    {
        self::$moved = Site::boot(['web' => 'export', 'siteName' => 'Nový web', 'extensions' => ['novinky']]); // the installer's "Hotovo" page is the answer or boot() throws

        $site = $this->moved();
        $site->setting('tasks_token', 'own-' . bin2hex(random_bytes(8))); // the harness gives every site the same token; the old installer made each site's own
        $this->assertStringContainsString('Pokračovat importem', $site->installerResponse->body, 'installer: Start from an export leads to the import (the installer\'s own answer)');
        $this->assertSame('0/0/1', (string) $site->value('SELECT CONCAT((SELECT COUNT(*) FROM ka_stranky), "/", (SELECT COUNT(*) FROM ka_novinky), "/", (SELECT COUNT(*) FROM ka_uzivatele))'), 'installer: Start from an export leaves the site empty');
        $this->assertStringContainsString('id="soubor-kaleta"', $site->admin()->get('/admin.php?module=transfer')->body, 'an empty site offers Import from Kaleta');
    }

    #[Depends('testStartFromAnExportInstallsAnEmptySite')]
    public function testThePreviewOfTheExportNeedsAConfirmation(): void
    {
        $new = $this->moved();
        $zipFile = $this->site()->workDir('move') . '/presun.zip';
        $admin = $new->admin();
        $upload = $admin->upload('/admin.php?module=transfer&action=upload', ['_csrf' => $new->csrf()], ['soubor' => $zipFile]);
        $preview = $admin->get(str_replace($new->base, '', $upload->redirect));

        $this->assertStringContainsString('Export webu „Testovací firma“', $preview->body, 'preview of the export with counts');
        $this->assertStringContainsString('name="potvrzeni"', $preview->body, 'preview of the export has a confirmation');
        $file = self::$file = substr($upload->redirect, (int) strrpos($upload->redirect, 'file=') + 5);

        $admin->post('/admin.php?module=transfer&action=kaleta_run', ['_csrf' => $new->csrf(), 'soubor' => $file]);
        $this->assertSame('0', (string) $new->value('SELECT COUNT(*) FROM ka_stranky'), 'without the confirmation nothing starts');
    }

    #[Depends('testThePreviewOfTheExportNeedsAConfirmation')]
    public function testTheImportRunsInBatchesAndTheNewSiteHasTheSameContent(): void
    {
        $old = $this->site();
        $new = $this->moved();
        $admin = $new->admin();
        $file = self::$file;
        $admin->post('/admin.php?module=transfer&action=kaleta_run', ['_csrf' => $new->csrf(), 'soubor' => $file, 'potvrzeni' => 1]);
        $result = null;
        for ($i = 0; $i < 80; $i++) {
            $result = $admin->post('/admin.php?module=transfer&action=kaleta', ['_csrf' => $new->csrf(), 'soubor' => $file]);
            if ($result->contains('Web je naimportovaný') || $result->contains('Import se zastavil')) {
                break;
            }
        }
        $this->assertStringContainsString('Web je naimportovaný', $result->body, 'the import went through in batches: ' . mb_substr($result->text(), 0, 300));
        $this->assertStringNotContainsString('data-auto-odeslat', $result->body, 'the result no longer submits itself');

        $this->assertSame($this->counts($old), $this->counts($new), 'the new site has the same content (pages/news/categories/collections/items/components/classes/menus/pop-ups/redirects/media/tags)');
        $this->assertSame($this->sameNumbers($old), $this->sameNumbers($new), 'same numbers: home page, site name and the design system came along');
        $this->assertSame('1/0', (string) $new->value("SELECT CONCAT(SUM(text LIKE '%<p class=\"n6\">N6 check</p>%'), '/', SUM(text LIKE '%onclick%' OR text LIKE '%<script%')) FROM ka_novinky WHERE text LIKE '%N6 check%'"),
            '3.3.2: news HTML from the export is sanitized, its structure and classes kept');
        $old->exec('UPDATE ka_novinky SET text = REPLACE(text, ?, \'\')', [self::N6_PAYLOAD]);
        $this->assertSame('0|https://www.linkedin.com/company/n55|n55note', (string) $new->value("SELECT CONCAT_WS('|', (SELECT COUNT(*) FROM ka_nastaveni WHERE hodnota LIKE '%javascript:%'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'social_linkedin'), (SELECT GROUP_CONCAT(fact_key ORDER BY fact_key) FROM ka_facts WHERE fact_key LIKE 'n55%'))"),
            '3.3.3 (N55, N50): the import drops javascript: in company_map, a social link and a text fact; a valid link and ordinary text came along');
        $this->assertSame('1/' . $new->base . '/0/1', (string) $new->value("SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_uzivatele), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'), (SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('webhook_secret', 'smtp_password') AND hodnota <> ''), (SELECT COUNT(DISTINCT autor) FROM ka_novinky))"),
            'accounts and secrets stay on the new site (users, site address, tokens)');
    }

    #[Depends('testTheImportRunsInBatchesAndTheNewSiteHasTheSameContent')]
    public function testTheMovedSiteRunsWithItsMediaNotebookAndBookings(): void
    {
        $old = $this->site();
        $new = $this->moved();

        $bookingsFromExport = $old->php('echo version_compare(KALETA_VERSION, "3.2.0", "<") ? 1 : 0;');
        $this->assertSame("1|$bookingsFromExport", (string) $new->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_booking_services WHERE name = 'Move test'), '|', (SELECT FIND_IN_SET('bookings', hodnota) > 0 FROM ka_nastaveni WHERE promenna = 'extensions'))"),
            "3.2: the booking set-up came along; Bookings follow the export's version (an export from before 3.2 switches them on)");
        $this->assertNotSame($old->settingValue('tasks_token'), $new->settingValue('tasks_token'), 'the new site keeps its own cron address');
        $this->assertStringStartsWith('own-', $new->settingValue('tasks_token'), 'the cron address did not come from the old site');

        $media = (string) $new->value('SELECT obr_poloha FROM ka_media ORDER BY ido LIMIT 1');
        $this->assertTrue($media !== '' && is_file($new->path($media)), "the media files are on the new site ($media)");
        $php = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($new->path('media'), \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_ends_with((string) $f, '.php')) {
                $php[] = (string) $f;
            }
        }
        $this->assertSame([], $php, 'no PHP came into media/');

        $home = $new->client()->get('/');
        $this->assertSame(200, $home->status, 'the moved site runs');
        $this->assertStringContainsString('Testovací firma', $home->body, 'the moved site shows the old name');
        $this->assertSame('1|history|Historie redesignu|test', (string) $new->value("SELECT CONCAT(COUNT(*), '|', MAX(topic), '|', MAX(title), '|', MAX(author)) FROM ka_notebook"), '2.15: the notebook moved with the site (the note, its topic and author)');

        $slug = (string) $new->value("SELECT seo_link FROM ka_stranky WHERE zobrazit = 1 AND smazano IS NULL AND ids <> (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page') ORDER BY ids LIMIT 1");
        $h1 = static function (Site $s) use ($slug): string {
            preg_match('/<h1[^>]*>[^<]*/', $s->client()->get('/' . $slug)->body, $m);

            return $m[0] ?? '';
        };
        $this->assertNotSame('', $h1($old), 'the page has a heading');
        $this->assertSame($h1($old), $h1($new), 'a moved page looks the same');
    }

    #[Depends('testTheImportRunsInBatchesAndTheNewSiteHasTheSameContent')]
    public function testASiteWithContentRefusesAnotherImport(): void
    {
        $new = $this->moved();
        $admin = $new->admin();
        $file = self::$file;

        $this->assertStringNotContainsString('id="soubor-kaleta"', $admin->get('/admin.php?module=transfer')->body, 'a site with content no longer offers the import form');
        $redirect = $admin->post('/admin.php?module=transfer&action=kaleta_select', ['_csrf' => $new->csrf(), 'soubor' => $file])->redirect;
        $this->assertStringContainsString('kaleta', $redirect, 'a site with content refuses another import: it sends away');
        $this->assertStringNotContainsString('name="potvrzeni"', $admin->get('/admin.php?module=transfer&action=kaleta&file=' . $file)->body, 'a site with content offers no confirmation');

        $log = $new->path('storage/log/chyby.log');
        $this->assertTrue(!is_file($log) || filesize($log) === 0, 'no errors on the new site: ' . (is_file($log) ? (string) file_get_contents($log) : ''));
    }
}
