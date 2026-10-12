<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A Polylang export into a site whose slugs are per language: a translation keeps the address it had, nothing gets a number. */
#[Group('site')]
final class WordPressLanguagesSharedSlugsTest extends SiteTestCase
{
    public function testTranslationsKeepTheirAddressWhenSlugsArePerLanguage(): void
    {
        $site = $this->site();
        $site->setting('site_language', 'cs');
        $site->exec("UPDATE tl_users SET language = 'en'");
        $result = $site->mcpResult('update_settings', ['settings' => ['slugs_per_language' => true]]);
        $this->assertSame('1', $result['saved']['slugs_per_language'] ?? null, 'slugs are per language');

        $transfer = '/admin.php?module=transfer';
        $file = 'wordpress-polylang.xml';
        $site->admin()->upload($transfer . '&action=upload', ['_csrf' => $site->csrf()], ['file' => dirname(__DIR__, 3) . '/tools/fixtures/' . $file]);
        $this->adminPost($transfer . '&action=progress&file=' . $file);
        $this->adminPost($transfer . '&action=run', ['file' => $file, 'drafts' => 1, 'pages' => 1, 'redirects' => 1, 'category' => 0, 'add_languages' => 1]);
        $report = $this->adminPost($transfer . '&action=progress&file=' . $file)->body;

        $this->assertStringContainsString('The content import is finished', $report);
        $this->assertStringNotContainsString('instead of', $report, 'no address got a number');
        $pages = array_column($site->rows("SELECT page_id, slug, language, translation_of FROM tl_pages WHERE slug LIKE 'kontakt-ictx%'"), null, 'language');
        $this->assertSame('kontakt-ictx', $pages['en-gb']['slug'], 'the British English page kept the address of the Czech one');
        $this->assertSame((int) $pages['']['page_id'], (int) $pages['en-gb']['translation_of'], 'and is linked to it');
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_categories WHERE slug = 'blog-ictx'"), 'both categories keep their shared slug');
    }
}
