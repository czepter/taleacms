<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An import leaves a live address alone: when the old path of an imported page is still a visible page of this site, no redirect is
 * written, no existing redirect is retargeted and no link is rewritten to the new (hidden) page. A free path still gets its redirect.
 */
#[Group('site')]
final class ImportKeepsLiveAddressesTest extends SiteTestCase
{
    private const string TRANSFER = '/admin.php?module=transfer';

    public function testAnOldPathThatIsStillLiveIsNotRedirected(): void
    {
        $site = $this->site();
        // the WordPress sample brings a page /o-zpravodaji/ and a news item /2026/05/lavka-pres-bystrinu/
        $site->exec('INSERT INTO tl_pages (public_id, slug, title, text, visible, updated_at) VALUES (?, ?, ?, ?, TRUE, NOW())',
            [\Talea\Core\Uuid::v4(), 'o-zpravodaji', 'Live page', '<p><a href="/o-zpravodaji">About</a></p>']);
        $site->exec('INSERT INTO tl_redirects (public_id, from_path, to_path, match_type, type, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [\Talea\Core\Uuid::v4(), 'gone', 'o-zpravodaji', 'exact', 301, 1, date('Y-m-d H:i:s')]);

        $file = 'wordpress-sample.xml';
        $site->admin()->upload(self::TRANSFER . '&action=upload', ['_csrf' => $site->csrf()], ['file' => dirname(__DIR__, 3) . '/tools/fixtures/' . $file]);
        $this->adminPost(self::TRANSFER . '&action=progress&file=' . $file);
        $this->adminPost(self::TRANSFER . '&action=run', ['file' => $file, 'drafts' => 1, 'pages' => 1, 'builder' => 1, 'redirects' => 1, 'category' => 0]);
        $this->adminPost(self::TRANSFER . '&action=progress&file=' . $file);

        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug LIKE 'o-zpravodaji%'"), 'the page was imported under a numbered slug');
        $this->assertNull($site->value("SELECT 1 FROM tl_redirects WHERE from_path = 'o-zpravodaji'"), 'no redirect from the live address');
        $this->assertSame('o-zpravodaji', $site->value("SELECT to_path FROM tl_redirects WHERE from_path = 'gone'"), 'an existing redirect is not retargeted');
        $this->assertStringContainsString('href="/o-zpravodaji"', (string) $site->value("SELECT text FROM tl_pages WHERE slug = 'o-zpravodaji'"), 'links to the live page are not rewritten');
        $this->assertNotNull($site->value("SELECT 1 FROM tl_redirects WHERE from_path LIKE '2026/05/lavka-pres-bystrinu%'"), 'a free old path still gets its redirect');
    }

    /** Kernel::redirectRule also matches `x.html` for /x and `de/x` for /de/x, so those forms of an old path are live too. */
    public function testOldPathsThatMatchLiveAddressesThroughHtmlOrLanguagePrefixAreNotRedirected(): void
    {
        $site = $this->site();
        $site->setting('additional_languages', 'de');
        $site->exec('INSERT INTO tl_pages (public_id, slug, title, text, visible, language, updated_at) VALUES (?, ?, ?, ?, TRUE, ?, NOW())', [\Talea\Core\Uuid::v4(), 'about', 'About', '<p>x</p>', '']);
        $site->exec('INSERT INTO tl_pages (public_id, slug, title, text, visible, language, updated_at) VALUES (?, ?, ?, ?, TRUE, ?, NOW())', [\Talea\Core\Uuid::v4(), 'company-profile', 'Firmenprofil', '<p>x</p>', 'de']);
        $boot = '$app = Talea\\Core\\App::boot(); ';
        foreach (['about.html', 'de/company-profile', 'de/company-profile.html'] as $old) {
            $site->php($boot . 'Talea\\Admin\\Modules\\Redirects::addImported($app->db(), "' . $old . '", "new-' . md5($old) . '");');
            $this->assertNull($site->value('SELECT 1 FROM tl_redirects WHERE from_path = ?', [$old]), $old . ' leads to a live page: no redirect');
        }
        $site->php($boot . 'Talea\\Admin\\Modules\\Redirects::addImported($app->db(), "de/free.html", "somewhere");');
        $this->assertNotNull($site->value('SELECT 1 FROM tl_redirects WHERE from_path = ?', ['de/free.html']), 'a free path still gets its redirect');
    }
}
