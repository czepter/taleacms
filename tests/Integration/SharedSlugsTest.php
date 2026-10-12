<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Db;
use Talea\Core\Settings;
use Talea\Core\Slug;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * The same address in every language version: the migration's per-language keys, the switch of the setting (drops and restores the
 * global keys, refuses to go off while an address is shared, rolls back when a later table fails) and the "taken?" scope.
 * The tests run outside the base class's transaction (as the settings screen does: MySQL commits DDL, PostgreSQL would roll the
 * switch back with it) and every one leaves the setting off with the global keys and no rows behind.
 */
#[CoversClass(Slug::class)]
final class SharedSlugsTest extends DatabaseTestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->pdo()->commit();
        Slug::setPerLanguage(null);
        $this->settings = new Settings($this->db());
    }

    protected function tearDown(): void
    {
        Slug::setPerLanguage(null);
        parent::tearDown();
    }

    private function page(string $slug, string $language): int
    {
        return $this->db()->insert('pages', ['slug' => $slug, 'title' => $slug, 'text' => '', 'language' => $language]);
    }

    /** @return list<string> the names of the unique keys of a table */
    private function keys(string $table): array
    {
        return array_keys($this->db()->uniqueKeys($table));
    }

    private function restore(): void
    {
        $this->db()->run('DELETE FROM {pages}');
        $this->db()->run('DELETE FROM {news}');
        $this->db()->run('DELETE FROM {categories}');
        Slug::switchPerLanguage($this->db(), $this->settings, false);
    }

    public function testMigrationAddsPerLanguageKeysAndWidensTheLanguageColumns(): void
    {
        foreach (Slug::TABLES as $table => [, $global, $perLanguage]) {
            $this->assertContains($global, $this->keys($table));
            $this->assertContains($perLanguage, $this->keys($table));
        }
        $this->assertSame(['language', 'slug'], $this->db()->uniqueKeys('pages')['uq_pages_language_slug']);
        $this->db()->insert('pages', ['slug' => 'tag', 'title' => 'Tag', 'text' => '', 'language' => 'zh-hant-tw']);
        $this->assertSame('zh-hant-tw', $this->db()->value("SELECT language FROM {pages} WHERE slug = 'tag'"));
    }

    public function testAddressIsSharedOnlyWhileTheSettingIsOn(): void
    {
        try {
            $this->page('contact', '');
            try {
                $this->page('contact', 'en');
                $this->fail('the global key must refuse a shared address while the setting is off');
            } catch (\PDOException) {
                $this->assertTrue(true);
            }

            $this->assertNull(Slug::switchPerLanguage($this->db(), $this->settings, true));
            $this->assertTrue(Slug::perLanguage($this->db()));
            $this->assertNotContains('uq_pages_slug', $this->keys('pages'));
            $this->assertContains('uq_pages_language_slug', $this->keys('pages'));

            $en = $this->page('contact', 'en');
            $this->assertTrue(Slug::taken($this->db(), 'pages', 'contact', 'en'));
            $this->assertTrue(Slug::taken($this->db(), 'pages', 'contact', ''));
            $this->assertFalse(Slug::taken($this->db(), 'pages', 'contact', 'en', $en), 'the page itself does not count');
            $this->assertFalse(Slug::taken($this->db(), 'pages', 'contact', 'de'));
            $this->assertSame([' AND language = ?', ['de']], Slug::scope($this->db(), 'de'));

            [$message, $slugs] = Slug::switchPerLanguage($this->db(), $this->settings, false) ?? $this->fail('switching off must be refused');
            $this->assertStringContainsString('cannot be switched off', $message);
            $this->assertSame('/contact', $slugs);
            $this->assertTrue(Slug::perLanguage($this->db()), 'a refused switch leaves the setting on');
            $this->assertNotContains('uq_pages_slug', $this->keys('pages'));

            $this->db()->delete('pages', ['page_id' => $en]);
            $this->assertNull(Slug::switchPerLanguage($this->db(), $this->settings, false));
            $this->assertFalse(Slug::perLanguage($this->db()));
            foreach (Slug::TABLES as $table => [, $global]) {
                $this->assertContains($global, $this->keys($table));
            }
            $this->assertTrue(Slug::taken($this->db(), 'pages', 'contact', 'en'), 'with the setting off any language version takes the address');
        } finally {
            $this->restore();
        }
    }

    public function testAFailureInALaterTableRestoresTheSettingAndDropsTheKeysAlreadyAdded(): void
    {
        try {
            $this->assertNull(Slug::switchPerLanguage($this->db(), $this->settings, true));
            // a stray index with the name of the last global key: adding the key to categories fails after pages and news succeeded
            $this->db()->run('CREATE INDEX uq_categories_slug ON {categories} (weight)');

            try {
                Slug::switchPerLanguage($this->db(), $this->settings, false);
                $this->fail('the failing table must surface');
            } catch (\PDOException) {
                $this->assertTrue(true);
            }

            $this->assertTrue(Slug::perLanguage($this->db()), 'the setting is on again');
            $this->assertSame('1', $this->settings->get('slugs_per_language'));
            $this->assertNotContains('uq_pages_slug', $this->keys('pages'), 'no table refuses a shared address while the setting allows it');
            $this->assertNotContains('uq_news_slug', $this->keys('news'));
            $this->page('about', '');
            $this->page('about', 'en');
        } finally {
            $this->db()->run($this->isPostgres() ? 'DROP INDEX IF EXISTS uq_categories_slug' : 'ALTER TABLE {categories} DROP INDEX uq_categories_slug');
            $this->restore();
        }
    }

    public function testRedirectPathCarriesTheLanguageOnlyWhileTheSettingIsOn(): void
    {
        $this->assertSame('old', Slug::redirectPath($this->db(), 'old', 'en'));
        Slug::setPerLanguage(true);
        $this->assertSame('en/old', Slug::redirectPath($this->db(), 'old', 'en'));
        $this->assertSame('old', Slug::redirectPath($this->db(), 'old', ''));
    }
}
