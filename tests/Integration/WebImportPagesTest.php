<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\ImageDownloader;
use Talea\Core\Settings;
use Talea\Core\StarterPages;
use Talea\Core\WebImport;
use Talea\Tests\Support\DatabaseTestCase;

/** The page part of the website import (Core\WebImport::importHtml): what an imported page remembers, starter pages, nested addresses. */
#[CoversClass(WebImport::class)]
final class WebImportPagesTest extends DatabaseTestCase
{
    private const string PAGE = '<html><head><title>About us | Acme</title></head><body><main><h1>About us</h1><p>We have been building boats for thirty years and know every harbour on the coast.</p></main></body></html>';

    /** @return array{0: WebImport, 1: array<string, mixed>} */
    private function importer(array $options = ['images' => false, 'redirects' => true, 'news' => false]): array
    {
        $db = $this->db();
        $db->insert('settings', ['name' => 'extensions', 'value' => 'redirects,news,enquiries']);

        return [new WebImport($db, new Settings($db), 0, new ImageDownloader('https://old.example', true)), WebImport::newState('https://old.example', $options)];
    }

    public function testAnImportedPageIsHiddenRemembersItsOldAddressAndWaitsForItsLayout(): void
    {
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/about-us/', self::PAGE, $state);

        $row = $this->db()->one("SELECT slug, visible, import_source, layout_pending FROM {pages} WHERE slug = 'about-us'");
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['visible']);
        $this->assertSame('https://old.example/about-us/', $row['import_source']);
        $this->assertSame(1, (int) $row['layout_pending']);
        $this->assertSame(1, $state['result']['pages']);
    }

    /** @return array<string, int> the four recorded starter pages by slug; the first is the home page */
    private function starterPages(): array
    {
        $db = $this->db();
        $ids = [];
        foreach (['home' => 'Home', 'about-us' => 'About us', 'services' => 'Services', 'contact' => 'Contact'] as $slug => $title) {
            $ids[$slug] = $db->insert('pages', ['slug' => $slug, 'title' => $title, 'text' => '<p>' . $title . '</p>']);
        }
        $db->insert('settings', ['name' => 'home_page', 'value' => (string) $ids['home']]);
        $db->insert('settings', ['name' => StarterPages::SETTING, 'value' => StarterPages::record($db, array_values($ids))]);

        return $ids;
    }

    public function testAnImportedPageTakesTheAddressOfAnUntouchedStarterPage(): void
    {
        $ids = $this->starterPages();
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/contact/', self::PAGE, $state);

        $this->assertSame('contact', $this->db()->value('SELECT slug FROM {pages} WHERE import_source = ?', ['https://old.example/contact/']));
        $row = $this->db()->one('SELECT slug, deleted_at FROM {pages} WHERE page_id = ?', [$ids['contact']]);
        $this->assertSame('contact-starter', $row['slug']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame('/contact', $state['starters']['replaced'][0]['address']);
        $this->assertSame([], $state['starters']['renamed']);
        $this->assertSame(0, $state['result']['redirects'], 'the address did not change: no redirect');
    }

    public function testAKeptStarterPageLeavesTheImportANumber(): void
    {
        $ids = $this->starterPages();
        [$import, $state] = $this->importer();
        WebImport::confirm($state, [$ids['contact']]);
        $import->importHtml('https://old.example/contact/', self::PAGE, $state);

        $this->assertSame((int) $ids['contact'], (int) $this->db()->value("SELECT page_id FROM {pages} WHERE slug = 'contact'"));
        $this->assertSame('contact-2', $this->db()->value('SELECT slug FROM {pages} WHERE import_source = ?', ['https://old.example/contact/']));
        $this->assertSame([['old' => '/contact', 'new' => '/contact-2']], $state['starters']['renamed']);
        $this->assertSame('kept', $state['starters']['kept'][0]['why']);
        $this->assertSame(0, $state['result']['redirects'], 'the starter page still answers at the old address: no redirect over a live page');
    }

    public function testTheHomePageIsNeverReplaced(): void
    {
        $ids = $this->starterPages();
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/', self::PAGE, $state);

        $this->assertSame((string) $ids['home'], (new Settings($this->db()))->get('home_page'));
        $this->assertNull($this->db()->value('SELECT deleted_at FROM {pages} WHERE page_id = ?', [$ids['home']]));
        $this->assertSame('home-2', $this->db()->value('SELECT slug FROM {pages} WHERE import_source = ?', ['https://old.example/']));
        $this->assertSame('home', $state['starters']['kept'][0]['why']);
    }

    public function testThePreviewOffersTheChoices(): void
    {
        $this->starterPages();
        [, $state] = $this->importer();
        $state['urls'] = ['https://old.example/' => 1, 'https://old.example/contact/' => 1, 'https://old.example/team/' => 1];
        $choices = WebImport::starterChoices($this->db(), new Settings($this->db()), $state);
        $this->assertSame(['/' => 'home', '/contact' => 'replace'], array_column($choices, 'action', 'old'));
    }

    public function testPlannedSlugKeepsTheWholePath(): void
    {
        $this->assertSame('home', WebImport::plannedSlug('', ''));
        $this->assertSame('contact', WebImport::plannedSlug('contact', ''));
        $this->assertSame('services/heating/boilers', WebImport::plannedSlug('services/heating/boilers.html', ''));
        $this->assertSame('leistungen/heizung', WebImport::plannedSlug('de/Leistungen/Heizung', ''), 'a language prefix of the old site is not a parent');
        $this->assertSame('home', WebImport::plannedSlug('de', ''));
    }

    public function testNestedAddressesBecomeParentPages(): void
    {
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/services/heating/boilers/', self::PAGE, $state);

        $rows = array_column($this->db()->all('SELECT page_id, slug, visible, layout_pending, parent_id, import_source FROM {pages} ORDER BY page_id'), null, 'slug');
        $this->assertSame(['services', 'services/heating', 'services/heating/boilers'], array_keys($rows));
        $this->assertNull($rows['services']['parent_id']);
        $this->assertSame((int) $rows['services']['page_id'], (int) $rows['services/heating']['parent_id']);
        $this->assertSame((int) $rows['services/heating']['page_id'], (int) $rows['services/heating/boilers']['parent_id']);
        foreach ($rows as $row) {
            $this->assertSame([0, 1], [(int) $row['visible'], (int) $row['layout_pending']], 'imported and placeholder pages are hidden and wait for their layout');
        }
        $this->assertSame(0, (int) $this->db()->value("SELECT COUNT(*) FROM {redirects} WHERE from_path = 'services/heating/boilers'"), 'the address is the same: no redirect');
        $this->assertSame([['old' => '/services', 'new' => '/services'], ['old' => '/services/heating', 'new' => '/services/heating']], $state['parents']);
        $this->assertSame(2, $state['result']['parents']);
    }

    public function testAParentImportedFirstIsReusedNotDuplicated(): void
    {
        [$import, $state] = $this->importer();
        $state['urls'] = ['https://old.example/services/heating/' => 1, 'https://old.example/services/' => 1];
        WebImport::confirm($state, null);
        $this->assertSame(['https://old.example/services/', 'https://old.example/services/heating/'], array_keys($state['urls']), 'shallow addresses first');
        foreach (array_keys($state['urls']) as $url) {
            $import->importHtml($url, self::PAGE, $state);
        }
        $services = (int) $this->db()->value("SELECT page_id FROM {pages} WHERE slug = 'services'");
        $this->assertSame($services, (int) $this->db()->value("SELECT parent_id FROM {pages} WHERE slug = 'services/heating'"));
        $this->assertSame(2, (int) $this->db()->value('SELECT COUNT(*) FROM {pages}'), 'no placeholder next to the real parent');
        $this->assertSame([], $state['parents']);
    }

    public function testALanguagePrefixOfTheOldSiteIsNotAParent(): void
    {
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/de/leistungen/', self::PAGE, $state);
        $this->assertSame(['leistungen'], array_column($this->db()->all('SELECT slug FROM {pages}'), 'slug'));
    }

    public function testATakenParentAddressRedirects(): void
    {
        $this->db()->insert('pages', ['slug' => 'services', 'title' => 'Our services', 'text' => '<p>Mine</p>', 'updated_at' => date('Y-m-d H:i:s')]);
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/services/heating/', self::PAGE, $state);

        $this->assertNotNull($this->db()->value("SELECT page_id FROM {pages} WHERE slug = 'services-2/heating'"));
        $this->assertSame([['from' => '/services/heating', 'to' => '/services-2/heating']], $state['moved']);
        $this->assertSame('services-2/heating', $this->db()->value("SELECT to_path FROM {redirects} WHERE from_path = 'services/heating'"));
    }

    /** The inside of the <main> of a fixture of old widgets. */
    private static function fixture(string $name): string
    {
        preg_match('#<main>(.*)</main>#s', (string) file_get_contents(dirname(__DIR__) . '/Fixtures/old-pages/' . $name), $m);

        return $m[1];
    }

    public function testWidgetsBecomeElementsAndUnreadableOnesPlaceholders(): void
    {
        [$import, $state] = $this->importer();
        $page = '<html><head><title>Widgets</title></head><body><main><h1>Widgets</h1><p>' . str_repeat('A page full of things that moved along. ', 4) . '</p>'
            . self::fixture('aria-accordion.html') . self::fixture('aria-tabs.html') . self::fixture('counters.html') . self::fixture('frames.html') . self::fixture('forms.html') . '</main></body></html>';
        $import->importHtml('https://old.example/widgets/', $page, $state);

        $row = $this->db()->one("SELECT build, text FROM {pages} WHERE slug = 'widgets'");
        $build = (string) $row['build'];
        foreach (['"type":"faq"', '"type":"tabs"', '"type":"counter"', '"type":"map"', '"type":"video"', '"type":"embed"', '"type":"form"'] as $type) {
            $this->assertStringContainsString($type, $build, $type);
        }
        $this->assertStringNotContainsString('talea-widget', $build . $row['text'], 'no marker is stored');
        $this->assertStringContainsString('How long does it take?', (string) $row['text'], 'the text has the questions');
        $this->assertStringContainsString('[Embedded content from widgets.example', $build, 'an unknown frame leaves a placeholder in the site language');
        $this->assertStringContainsString('[Form from the old page: its fields could not be read.', $build);
        ksort($state['kept']);
        $this->assertSame(['counter' => 2, 'embed' => 1, 'faq' => 1, 'form' => 1, 'map' => 2, 'tabs' => 1, 'video' => 1], $state['kept']);
        $kinds = array_column($state['attention'], 'kind');
        sort($kinds);
        $this->assertSame(['frame', 'map', 'unreadable_form'], $kinds, 'a frame, the map that says nothing and the script form need the owner');
    }

    public function testAFormWithEnquiriesOffIsAPlaceholder(): void
    {
        [$import, $state] = $this->importer();
        $this->db()->run("UPDATE {settings} SET value = 'news' WHERE name = 'extensions'");
        $import = new WebImport($this->db(), new Settings($this->db()), 0, new ImageDownloader('https://old.example', true));
        $page = '<html><head><title>Contact</title></head><body><main><h1>Contact</h1><p>' . str_repeat('Write to us about anything at all. ', 4) . '</p><form><input name="email"><textarea name="m"></textarea></form></main></body></html>';
        $import->importHtml('https://old.example/write/', $page, $state);
        $this->assertStringContainsString('[A form was here: switch on Enquiries', (string) $this->db()->value("SELECT build FROM {pages} WHERE slug = 'write'"));
        $this->assertSame(['form_off'], array_column($state['attention'], 'kind'));
    }

    public function testTheOldTitleBecomesTheSeoTitle(): void
    {
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/about/', self::PAGE, $state);
        $row = $this->db()->one("SELECT title, seo_title FROM {pages} WHERE slug = 'about'");
        $this->assertSame(['About us', 'About us | Acme'], [$row['title'], $row['seo_title']]);
        $this->assertSame(1, $state['kept']['seo']);

        $same = '<html><head><title>Contact</title></head><body><main><h1>Contact</h1><p>' . str_repeat('Write to us about anything at all. ', 4) . '</p></main></body></html>';
        $import->importHtml('https://old.example/contact/', $same, $state);
        $this->assertSame('', $this->db()->value("SELECT seo_title FROM {pages} WHERE slug = 'contact'"), 'the same title is no SEO title');
    }
}
