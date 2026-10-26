<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The "Titles and descriptions" screen of the Search engines section. */
#[Group('site')]
final class SeoTitlesTest extends SiteTestCase
{
    use AuthorSession;

    private function save(array $fields, string $kind): \Talea\Tests\Site\Support\Response
    {
        return $this->adminPost('/admin.php?module=seo_titles&action=save', ['kind' => $kind] + $fields, '/admin.php?module=seo_titles&kind=' . $kind);
    }

    private function aboutPage(): void
    {
        $site = $this->site();
        // the site is shared by the tests of the class: start from the defaults
        foreach (['site_name' => 'My website', 'seo_separator' => '–', 'seo_title_page' => '%title% %sep% %sitename%', 'seo_capitalize' => '0', 'seo_robots' => ''] as $key => $value) {
            $site->setting($key, $value);
        }
        if ((int) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug = 'about'") === 0) {
            $site->mcp('create_page', ['title' => 'About', 'slug' => 'about', 'visible' => true, 'in_menu' => false, 'content' => '<p>About us.</p>']);
        }
        $site->exec("UPDATE tl_pages SET title = 'About' WHERE slug = 'about'");
    }

    public function testEveryKindOpensWithASampleOfTheLatestPage(): void
    {
        $this->aboutPage();
        foreach (['defaults', 'home', 'page', 'news', 'item', 'category', 'tag'] as $kind) {
            $this->assertPage('/admin.php?module=seo_titles&kind=' . $kind, 200, ['Titles and descriptions', 'name="kind" value="' . $kind . '"'], message: $kind);
        }
        $this->assertPage('/admin.php?module=seo_titles&kind=page', 200, ['data-insert', 'Reads as', 'About – My website', '%excerpt%'], message: 'the sample is rendered from the latest page');
        $this->assertPage('/admin.php?module=seo_titles', 200, ['name="seo_separator"', 'name="seo_robots[]"', 'name="seo_card"'], message: 'defaults come first');
    }

    public function testTheSeparatorAndTheTemplateChangeTheTitleOfAPublicPage(): void
    {
        $this->aboutPage();
        $site = $this->site();
        $this->assertSame(302, $this->save(['seo_separator' => '|'], 'defaults')->status);
        $this->assertSame(302, $this->save(['seo_title_page' => '%title% %sep% %sitename%'], 'page')->status);
        $site->clearPageCache();
        $this->assertStringContainsString('<title>About | My website</title>', $site->client('visitor')->get('/about')->body);
    }

    public function testRobotsAreStoredNormalisedAndPrintedOnEveryPage(): void
    {
        $this->aboutPage();
        $site = $this->site();
        $this->save(['seo_robots' => ['nosnippet', 'noarchive', 'bogus'], 'robots_max_snippet' => '50', 'robots_image' => 'large'], 'defaults');
        $this->assertSame('noarchive,nosnippet,max-snippet:50,max-image-preview:large', $site->settingValue('seo_robots'));
        $site->clearPageCache();
        $this->assertStringContainsString('noarchive', $site->client('visitor')->get('/about')->body);
        $this->assertMatchesRegularExpression('/<meta name="robots" content="index, noarchive/', $site->client('visitor')->get('/')->body);
    }

    public function testATemplateWithMarkupIsSavedAsTextAndPrintedEscaped(): void
    {
        $this->aboutPage();
        $site = $this->site();
        $this->save(['seo_title_page' => '<script>'], 'page');
        $this->assertSame('<script>', $site->settingValue('seo_title_page'));
        $this->assertStringContainsString('&lt;script&gt;', $this->assertPage('/admin.php?module=seo_titles&kind=page')->body);
        $site->clearPageCache();
        $body = $site->client('visitor')->get('/about')->body;
        $this->assertStringContainsString('<title>&lt;script&gt;</title>', $body);
        $this->assertStringNotContainsString('<title><script>', $body);
    }

    public function testCapitalLettersTouchTheTitleOnly(): void
    {
        $this->aboutPage();
        $site = $this->site();
        $site->setting('site_name', 'my studio');
        $site->exec("UPDATE tl_pages SET title = 'wedding photos' WHERE slug = 'about'");
        $this->save(['seo_capitalize' => '1'], 'defaults');
        $site->clearPageCache();
        $this->assertStringContainsString('<title>Wedding Photos – my studio</title>', $site->client('visitor')->get('/about')->body);
    }

    public function testAnInvalidSeparatorSavesNothing(): void
    {
        $site = $this->site();
        $before = $site->settingValue('seo_separator');
        $response = $this->save(['seo_separator' => 'toolong', 'seo_card' => 'small'], 'defaults');
        $this->assertSame($before, $site->settingValue('seo_separator'));
        $this->assertNotSame('small', $site->settingValue('seo_card'), 'one wrong field saves none of the section');
        $this->assertSame(302, $response->status);
    }

    public function testAKeyOutsideTheSectionIsNeverWritten(): void
    {
        $site = $this->site();
        $site->setting('site_name', 'My website');
        $this->save(['site_name' => 'Hacked', 'seo_title_page' => '%title%'], 'page');
        $this->assertSame('My website', $site->settingValue('site_name'));
        $this->assertSame('%title%', $site->settingValue('seo_title_page'));
    }

    public function testCapitalLettersWorkOnMultibyteWords(): void
    {
        $this->aboutPage();
        $site = $this->site();
        $site->exec("UPDATE tl_pages SET title = 'ölmühle zürich' WHERE slug = 'about'");
        $this->save(['seo_capitalize' => '1'], 'defaults');
        $site->clearPageCache();
        $this->assertStringContainsString('Ölmühle Zürich', $site->client('visitor')->get('/about')->body);
    }

    public function testResetByGetChangesNothing(): void
    {
        $site = $this->site();
        $this->save(['seo_title_page' => '%title%'], 'page');
        $this->assertPage('/admin.php?module=seo_titles&action=reset&kind=page', 302);
        $this->assertSame('%title%', $site->settingValue('seo_title_page'));
    }

    public function testResetRestoresTheDefaultsOfOneKind(): void
    {
        $site = $this->site();
        $this->save(['seo_title_page' => '%title%', 'seo_description_page' => '%excerpt%'], 'page');
        $this->save(['seo_title_news' => 'News: %title%'], 'news');
        $this->adminPost('/admin.php?module=seo_titles&action=reset', ['kind' => 'page'], '/admin.php?module=seo_titles&kind=page');
        $this->assertSame('%title% %sep% %sitename%', $site->settingValue('seo_title_page'));
        $this->assertSame('', $site->settingValue('seo_description_page'));
        $this->assertSame('News: %title%', $site->settingValue('seo_title_news'), 'the other kinds keep what they have');
        $this->assertStringContainsString('data-confirm=', $this->assertPage('/admin.php?module=seo_titles&kind=page')->body);
    }

    public function testOnlyAdministratorsOpenIt(): void
    {
        $author = $this->authorClient();
        $this->assertSame(403, $author->get('/admin.php?module=seo_titles')->status);
        $this->assertSame(403, $author->post('/admin.php?module=seo_titles&action=save', ['_csrf' => $this->site()->csrf($author), 'kind' => 'page', 'seo_title_page' => 'x'])->status);
    }

    public function testTheSharingTabLinksToTheCardSize(): void
    {
        $site = $this->site();
        $id = $site->publicId('pages', (int) $site->value('SELECT MIN(page_id) FROM tl_pages WHERE deleted_at IS NULL'));
        $this->assertPage('/admin.php?module=pages&action=website&tab=sharing&id=' . $id, 200, '/admin/seo_titles', message: 'the card size text links to the screen');
    }
}
