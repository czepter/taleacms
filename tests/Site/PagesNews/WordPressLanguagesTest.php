<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Polylang and WPML exports arrive as linked language versions (tools/fixtures/wordpress-polylang.xml, wordpress-wpml.xml): a language
 * the site lacks is added only when asked, otherwise its posts are left out and reported; translations point to their original,
 * categories follow their language, slugs another language already has get a number (and are reported), old addresses redirect.
 */
#[Group('site')]
final class WordPressLanguagesTest extends SiteTestCase
{
    private const string TRANSFER = '/admin.php?module=transfer';
    private static string $lastBatch = '';

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 3) . '/tools/fixtures/' . $name;
    }

    private function batch(string $file): Response
    {
        $response = $this->adminPost(self::TRANSFER . '&action=progress&file=' . $file);
        self::$lastBatch = $response->body;

        return $response;
    }

    private function runImport(string $file, array $options): void
    {
        $this->batch($file);
        $this->adminPost(self::TRANSFER . '&action=run', ['file' => $file] + $options);
        $this->batch($file);
    }

    /** @return array<string, array<string, mixed>> rows of a table by slug */
    private function bySlug(string $table, string $columns): array
    {
        return array_column($this->site()->rows('SELECT slug, ' . $columns . ' FROM tl_' . $table), null, 'slug');
    }

    public function testALanguageTheSiteLacksIsLeftOutUntilItIsAdded(): void
    {
        $this->site()->setting('site_language', 'cs');
        $this->site()->exec("UPDATE tl_users SET language = 'en'"); // the administration stays English whatever the site language is
        $this->site()->setting('additional_languages', '');
        $this->site()->admin()->upload(self::TRANSFER . '&action=upload', ['_csrf' => $this->site()->csrf()], ['file' => $this->fixture('wordpress-polylang.xml')]);
        $this->batch('wordpress-polylang.xml');
        $preview = $this->assertPage(self::TRANSFER . '&action=preview&file=wordpress-polylang.xml', 200, 'Polylang', message: 'the preview names the plugin');
        $this->assertStringContainsString('name="add_languages"', $preview->body, 'a language the site lacks can be added with a checkbox');
        $this->assertStringContainsString('he (1)', $preview->body, 'a language the site cannot offer is reported');

        $this->runImport('wordpress-polylang.xml', ['drafts' => 1, 'pages' => 1, 'redirects' => 1, 'category' => 0]);
        $this->assertStringContainsString('The content import is finished', self::$lastBatch);
        $this->assertStringContainsString('the site has no version in these languages', self::$lastBatch, 'the posts left out are reported');
        $pages = $this->bySlug('pages', 'language, translation_of');
        $this->assertArrayHasKey('reseni-ictx', $pages, 'the default-language pages came');
        $this->assertArrayHasKey('kontakt-ictx', $pages, 'the default-language pages came');
        $this->assertArrayNotHasKey('solutions-ictx', $pages, 'a page in a language the site lacks was left out');
        $this->assertArrayNotHasKey('solutions-ictx-he', $pages, 'a page in a language this site cannot offer was left out');
        $this->assertSame('', $this->site()->settingValue('additional_languages'), 'no language was added behind the owner\'s back');
    }

    #[Depends('testALanguageTheSiteLacksIsLeftOutUntilItIsAdded')]
    public function testAddingLanguagesLinksTranslationsAndRedirectsOldAddresses(): void
    {
        $this->adminPost(self::TRANSFER . '&action=select', ['file' => 'wordpress-polylang.xml']);
        $this->runImport('wordpress-polylang.xml', ['drafts' => 1, 'pages' => 1, 'redirects' => 1, 'category' => 0, 'add_languages' => 1]);
        $this->assertStringContainsString('Language versions added to the site: English', self::$lastBatch);
        $this->assertSame('en-gb', $this->site()->settingValue('additional_languages'), 'the English version was added (the export is en_GB and the site has no English: the regional version)');

        $pages = $this->bySlug('pages', 'page_id, language, translation_of');
        $this->assertSame('en-gb', $pages['solutions-ictx']['language'], 'the translation is in its language version');
        $this->assertSame((int) $pages['reseni-ictx']['page_id'], (int) $pages['solutions-ictx']['translation_of'], 'a translation that came before its original is linked to it');
        $this->assertStringNotContainsString('instead of', self::$lastBatch, 'no address got a number: slugs are unique per language');
        $contact = array_column($this->site()->rows("SELECT page_id, language, translation_of FROM tl_pages WHERE slug = 'kontakt-ictx'"), null, 'language');
        $this->assertSame((int) $contact['']['page_id'], (int) $contact['en-gb']['translation_of'], 'the translation kept the address of its original and is linked to it');
        $this->assertSame(301, $this->site()->client('visitor')->get('/en/kontakt-ictx')->status, 'the old address of a renamed translation redirects');

        $news = $this->bySlug('news', 'news_id, language, translation_of, category_id');
        $this->assertArrayHasKey('snidane-s-ai-ictx', $news, 'the Czech post came');
        $this->assertSame('en-gb', $news['breakfast-with-ai-ictx']['language'], 'the English post came in its version');
        $this->assertSame((int) $news['snidane-s-ai-ictx']['news_id'], (int) $news['breakfast-with-ai-ictx']['translation_of'], 'posts are linked');
        $categories = array_column($this->site()->rows("SELECT category_id, name, language, translation_of FROM tl_categories WHERE slug = 'blog-ictx'"), null, 'language');
        $this->assertSame('ICTX Blog', $categories['en-gb']['name'], 'the English category kept its own name and the shared slug');
        $this->assertSame((int) $categories['']['category_id'], (int) $categories['en-gb']['translation_of'], 'categories are linked');
        $this->assertSame((int) $categories['en-gb']['category_id'], (int) $news['breakfast-with-ai-ictx']['category_id'], 'the post sits in the category of its language');
        $this->assertSame(301, $this->site()->client('visitor')->get('/?lang=en')->status, 'Polylang\'s language home redirects');
    }

    #[Depends('testAddingLanguagesLinksTranslationsAndRedirectsOldAddresses')]
    public function testWpmlExportLinksByGroupDuplicateAndAddress(): void
    {
        $this->site()->admin()->upload(self::TRANSFER . '&action=upload', ['_csrf' => $this->site()->csrf()], ['file' => $this->fixture('wordpress-wpml.xml')]);
        $this->runImport('wordpress-wpml.xml', ['drafts' => 1, 'pages' => 1, 'redirects' => 1, 'category' => 0]);
        $pages = $this->bySlug('pages', 'page_id, language, translation_of');
        $this->assertSame((int) $pages['sluzby-wpx']['page_id'], (int) $pages['services-wpx']['translation_of'], 'a WPML translation group is linked');
        $this->assertSame((int) $pages['cenik-wpx']['page_id'], (int) $pages['cenik-wpx-en']['translation_of'], 'a WPML duplicate is linked to its original');
        $this->assertSame('en-gb', $pages['about-wpx']['language'], 'a page without WPML fields takes its language from /en/ in its address (the site has English as en-gb)');
        $this->assertNull($pages['about-wpx']['translation_of'], 'and is linked to nothing');
        $news = $this->bySlug('news', 'news_id, language, translation_of');
        $this->assertSame((int) $news['novy-servisni-vuz-wpx']['news_id'], (int) $news['new-service-van-wpx']['translation_of'], 'WPML posts are linked');
        $categories = $this->bySlug('categories', 'category_id, language, translation_of');
        $this->assertSame((int) $categories['aktuality-wpx']['category_id'], (int) $categories['news-wpx']['translation_of'], 'WPML termmeta links categories');
    }
}
