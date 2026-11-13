<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Settings;
use Talea\Core\StarterPages;
use Talea\Tests\Support\DatabaseTestCase;

#[CoversClass(StarterPages::class)]
final class StarterPagesTest extends DatabaseTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    /** Four recorded starter pages, the first one the home page. */
    private function install(): Settings
    {
        $db = $this->db();
        foreach (['home' => 'Home', 'about-us' => 'About us', 'services' => 'Services', 'contact' => 'Contact'] as $slug => $title) {
            $this->ids[$slug] = $db->insert('pages', ['slug' => $slug, 'title' => $title, 'text' => '<p>' . $title . '</p>', 'in_menu' => 1]);
        }
        $db->insert('settings', ['name' => 'home_page', 'value' => (string) $this->ids['home']]);
        $db->insert('settings', ['name' => StarterPages::SETTING, 'value' => StarterPages::record($db, array_values($this->ids))]);

        return new Settings($db);
    }

    public function testARecordedPageIsUntouchedUntilItChanges(): void
    {
        $db = $this->db();
        $settings = $this->install();
        $this->assertCount(4, StarterPages::untouched($db, $settings));
        $this->assertTrue(StarterPages::untouched($db, $settings)[$this->ids['home']]['home']);

        $db->run("UPDATE {pages} SET title = 'Get in touch' WHERE page_id = ?", [$this->ids['contact']]);
        $db->run("UPDATE {pages} SET build_draft = '{}' WHERE page_id = ?", [$this->ids['services']]);
        $db->run("UPDATE {pages} SET updated_at = NOW() WHERE page_id = ?", [$this->ids['about-us']]);
        $this->assertSame([$this->ids['home']], array_keys(StarterPages::untouched($db, $settings)));
        $this->assertEqualsCanonicalizing([$this->ids['contact'], $this->ids['services'], $this->ids['about-us']], array_keys(StarterPages::edited($db, $settings)));
    }

    public function testATranslationOrTheTrashTakesAPageOutOfTheList(): void
    {
        $db = $this->db();
        $settings = $this->install();
        $db->insert('pages', ['slug' => 'contact-fr', 'title' => 'Contact FR', 'text' => '', 'language' => 'fr', 'translation_of' => $this->ids['contact']]);
        $db->run('UPDATE {pages} SET deleted_at = NOW() WHERE page_id = ?', [$this->ids['services']]);
        $this->assertSame([$this->ids['home'], $this->ids['about-us']], array_keys(StarterPages::untouched($db, $settings)));
        $this->assertArrayHasKey($this->ids['contact'], StarterPages::edited($db, $settings));
        $this->assertArrayNotHasKey($this->ids['services'], StarterPages::edited($db, $settings));
    }

    public function testRetireMovesAnUntouchedPageToTheTrashUnderAFreeAddress(): void
    {
        $db = $this->db();
        $settings = $this->install();
        $this->assertSame('contact', StarterPages::retire($db, $settings, $this->ids['contact']));
        $row = $db->one('SELECT slug, deleted_at, visible FROM {pages} WHERE page_id = ?', [$this->ids['contact']]);
        $this->assertSame('contact-starter', $row['slug']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame(0, (int) $row['visible']);
        $db->insert('pages', ['slug' => 'contact', 'title' => 'Contact', 'text' => '']); // the address is free again
    }

    public function testTheHomePageAndAnEditedPageAreNeverRetired(): void
    {
        $db = $this->db();
        $settings = $this->install();
        $db->run('UPDATE {pages} SET updated_at = NOW() WHERE page_id = ?', [$this->ids['about-us']]);
        $this->assertNull(StarterPages::retire($db, $settings, $this->ids['home']));
        $this->assertNull(StarterPages::retire($db, $settings, $this->ids['about-us']));
        $this->assertSame(2, StarterPages::trashAll($db, $settings)); // services and contact
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE deleted_at IS NOT NULL AND page_id IN (?, ?)', [$this->ids['home'], $this->ids['about-us']]));
    }

    public function testWithoutARecordNothingIsAStarterPage(): void
    {
        $db = $this->db();
        $db->insert('pages', ['slug' => 'contact', 'title' => 'Contact', 'text' => '']);
        $settings = new Settings($db);
        $this->assertNull(StarterPages::recorded($settings));
        $this->assertSame([], StarterPages::untouched($db, $settings));
        $this->assertSame(0, StarterPages::trashAll($db, $settings));
    }
}
