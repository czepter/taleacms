<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Slug;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * The same address in every language version: only the per-language keys exist (the migration dropped the global ones),
 * a slug is unique inside one language, and the "taken?" scope and the redirect paths follow.
 */
#[CoversClass(Slug::class)]
final class SharedSlugsTest extends DatabaseTestCase
{
    private function page(string $slug, string $language): int
    {
        return $this->db()->insert('pages', ['slug' => $slug, 'title' => $slug, 'text' => '', 'language' => $language]);
    }

    public function testOnlyThePerLanguageKeysExistAndTheLanguageColumnsAreWide(): void
    {
        foreach (['pages', 'news', 'categories'] as $table) {
            $keys = $this->db()->uniqueKeys($table);
            $this->assertArrayNotHasKey("uq_{$table}_slug", $keys);
            $this->assertSame(['language', 'slug'], $keys["uq_{$table}_language_slug"]);
        }
        $this->assertNull($this->db()->value("SELECT 1 FROM {settings} WHERE name = 'slugs_per_language'"));
        $this->db()->insert('pages', ['slug' => 'tag', 'title' => 'Tag', 'text' => '', 'language' => 'zh-hant-tw']);
        $this->assertSame('zh-hant-tw', $this->db()->value("SELECT language FROM {pages} WHERE slug = 'tag'"));
    }

    public function testAnAddressIsUniqueOnlyWithinItsLanguage(): void
    {
        $this->page('contact', '');
        $en = $this->page('contact', 'en');
        $this->assertTrue(Slug::taken($this->db(), 'pages', 'contact', 'en'));
        $this->assertTrue(Slug::taken($this->db(), 'pages', 'contact', ''));
        $this->assertFalse(Slug::taken($this->db(), 'pages', 'contact', 'en', $en), 'the page itself does not count');
        $this->assertFalse(Slug::taken($this->db(), 'pages', 'contact', 'de'));
        $this->assertSame([' AND language = ?', ['de']], Slug::scope('de'));
        $this->assertSame('contact-2', Slug::makeUnique('contact', fn (string $s): bool => Slug::taken($this->db(), 'pages', $s, 'en')));
        $this->assertSame('contact', Slug::makeUnique('contact', fn (string $s): bool => Slug::taken($this->db(), 'pages', $s, 'de')));

        $this->expectException(\PDOException::class);
        $this->page('contact', 'en');
    }

    public function testRedirectPathCarriesTheLanguagePrefix(): void
    {
        $this->assertSame('en/old', Slug::redirectPath('old', 'en'));
        $this->assertSame('old', Slug::redirectPath('old', ''));
    }
}
