<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The same address in every language version (slugs are unique per language version): pages, news, categories, redirects. */
#[Group('site')]
final class SharedSlugsTest extends SiteTestCase
{
    public function testTwoVersionsShareAnAddressButOneLanguageDoesNot(): void
    {
        $site = $this->site();
        $site->setting('additional_languages', 'de');
        $this->assertNull($site->value("SELECT 1 FROM tl_settings WHERE name = 'slugs_per_language'"));

        $site->mcp('create_page', ['title' => 'Contact English', 'slug' => 'help', 'content' => '<p>English contact</p>', 'visible' => 1]);
        $site->mcp('create_page', ['title' => 'Help German', 'slug' => 'help', 'language' => 'de', 'content' => '<p>German help</p>', 'visible' => 1]);
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug = 'help'"), 'neither got a number');
        $again = $site->mcp('create_page', ['title' => 'Help again', 'slug' => 'help', 'language' => 'de', 'content' => '<p>Again</p>', 'visible' => 1]);
        $this->assertStringContainsString('already exists', json_encode($again, JSON_UNESCAPED_UNICODE), 'a clash inside one language is refused');

        $site->clearPageCache();
        $this->assertPage('/help', 200, 'Contact English', $site->client('visitor'), 'the default version');
        $this->assertPage('/de/help', 200, 'Help German', $site->client('visitor'), 'the German version at the same address');
        $this->assertPageLacks('/de/help', 'Contact English', $site->client('visitor'));
    }

    #[Depends('testTwoVersionsShareAnAddressButOneLanguageDoesNot')]
    public function testARenamedPageRedirectsInItsOwnVersionOnly(): void
    {
        $site = $this->site();
        $german = $site->internalId('pages', $site->publicId('pages', (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'help' AND language = 'de'")));
        $site->mcp('update_page', ['id' => $site->publicId('pages', $german), 'slug' => 'hilfe']);

        $this->assertSame('de/help', (string) $site->value("SELECT from_path FROM tl_redirects WHERE to_path = 'de/hilfe'"), 'the redirect carries the language prefix');
        $site->clearPageCache();
        $visitor = $site->client('visitor');
        $moved = $visitor->get('/de/help');
        $this->assertSame(301, $moved->status);
        $this->assertStringContainsString('/de/hilfe', $moved->redirect);
        $this->assertPage('/help', 200, 'Contact English', $visitor, 'the default version keeps its address');
        $this->assertPage('/de/hilfe', 200, 'Help German', $visitor);
    }

    #[Depends('testARenamedPageRedirectsInItsOwnVersionOnly')]
    public function testNewsAndCategoriesShareAddressesToo(): void
    {
        $site = $this->site();
        $category = fn (string $language): int => (int) $site->value('SELECT category_id FROM tl_categories WHERE slug = ? AND language = ?', ['stories', $language]);
        foreach (['' => 'Stories', 'de' => 'Geschichten'] as $language => $name) {
            $site->exec("INSERT INTO tl_categories (name, slug, description, language) VALUES (?, 'stories', '', ?)", [$name, $language]);
            $site->exec("INSERT INTO tl_news (slug, title, intro, text, category_id, published_at, visible, language) VALUES ('first-post', ?, '', '', ?, '2026-01-01 10:00:00', 1, ?)", ['Post ' . ($language ?: 'default'), $category($language), $language]);
        }
        $site->clearPageCache();
        $visitor = $site->client('visitor');

        $this->assertPage('/news/first-post', 200, 'Post default', $visitor, 'the default version');
        $this->assertPage('/de/news/first-post', 200, 'Post de', $visitor, 'the German version of the same address');
        $this->assertPage('/news/category/stories', 200, 'Stories', $visitor);
        $this->assertPage('/de/news/category/stories', 200, 'Geschichten', $visitor);
    }
}
