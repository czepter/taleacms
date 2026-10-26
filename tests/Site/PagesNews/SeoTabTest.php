<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * The SEO tab of Website mode: the score and checks, the live check of unsaved fields (pages/seo_check), the search
 * result preview, the score dots of the page tree and the marks laid over the preview ("SEO on the page").
 */
#[Group('site')]
final class SeoTabTest extends SiteTestCase
{
    /** The home page with a draft build: an image without alt and an H1; returns its public id. */
    private function home(): string
    {
        $site = $this->site();
        $id = $site->publicId('pages', (int) $site->settingValue('home_page'));
        $site->mcp('save_build', ['id' => $id, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['id' => 'heroimg', 'type' => 'image', 'content' => ['src' => 'https://example.com/hero.jpg', 'alt' => '']],
            ['id' => 'mainh1', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Photos in Jena']],
            ['type' => 'text', 'content' => ['text' => '<p>We take wedding photos.</p>']],
        ]]]]]);
        $site->exec("UPDATE tl_pages SET seo_title = 'Photos for couples', description = 'Relaxed portraits.', focus_keywords = 'wedding' WHERE public_id = ?", [$id]);

        return $id;
    }

    public function testTheTabShowsTheChecksAndTheSearchResult(): void
    {
        $id = $this->home();
        $this->site()->setting('site_name', 'Studio');
        $this->assertPage('/admin.php?module=pages&action=website&id=' . $id . '&tab=seo', 200,
            ['name="focus_keywords"', 'data-seo-checks', 'data-seo-live="', 'data-chips', 'Photos for couples – Studio', 'data-check="kw_title"']);
        $this->assertStringNotContainsString('data-insert', $this->site()->admin()->get('/admin.php?module=pages&action=website&id=' . $id . '&tab=seo')->body, 'an own title is literal: no variables to insert');
    }

    public function testTheSidebarScoreIsTheRingsOnTheSeoTab(): void
    {
        $id = $this->home();
        $this->site()->exec('UPDATE tl_pages SET seo_score = 3 WHERE public_id = ?', [$id]); // as old as the last save
        $body = $this->assertPage('/admin.php?module=pages&action=website&id=' . $id . '&tab=seo', 200)->body;
        $this->assertSame(1, preg_match('#<strong><span class="guide-hidden">[^<]*</span>(\d+)</strong>#', $body, $ring), 'the ring shows a score');
        $this->assertNotSame('3', $ring[1]);
        $this->assertStringContainsString('>' . $ring[1] . ' / 100</span>', $body, 'the pill shows the number of the ring');
        $this->assertStringContainsString('>3 / 100</span>', $this->site()->admin()->get('/admin.php?module=pages&action=website&id=' . $id . '&tab=page')->body, 'another tab shows the stored score');
    }

    public function testTheLiveCheckJudgesTheUnsavedFieldsAndSavesNothing(): void
    {
        $id = $this->home();
        $client = $this->site()->admin();
        $csrf = $client->get('/admin.php?module=pages&action=website&id=' . $id . '&tab=seo')->csrf();
        $response = $client->post('/admin.php?module=pages&action=seo_check', ['_csrf' => $csrf, 'id' => $id, 'tab' => 'seo',
            'seo_title' => 'Wedding photos for couples', 'description' => 'Relaxed portraits.', 'focus_keywords' => "wedding\nportraits", 'key_page' => '0']);

        $this->assertSame(200, $response->status);
        $this->assertFalse($response->hasPhpError());
        $this->assertMatchesRegularExpression('/<li class="pass" data-check="kw_title"/', $response->body, 'the posted title has the keyword');
        $this->assertStringContainsString('data-seo-checks', $response->body);
        $this->assertStringNotContainsString('<html', $response->body, 'a fragment, not a page');
        $this->assertSame('Photos for couples', $this->site()->value('SELECT seo_title FROM tl_pages WHERE public_id = ?', [$id]), 'nothing is saved');
        $this->assertSame('wedding', $this->site()->value('SELECT focus_keywords FROM tl_pages WHERE public_id = ?', [$id]));
    }

    public function testMarksOnThePreview(): void
    {
        $id = $this->home();
        $website = $this->assertPage('/admin.php?module=pages&action=website&id=' . $id . '&marks=1', 200, ['SEO on the page'])->body;
        $this->assertMatchesRegularExpression('/<iframe[^>]+src="[^"]*seo_marks=1/', $website, 'the frame asks for the marks');
        $this->assertMatchesRegularExpression('/aria-current="true"[^>]*>.*?SEO on the page/s', $website);

        $marked = $this->assertPage('/?build=draft&seo_marks=1', 200, ['data-seo-mark="Image without description (alt)"', 'seo-marks.css'])->body;
        $this->assertStringContainsString('data-seo-mark="Main heading without the focus keyword “wedding”"', $marked);

        foreach (['/?seo_marks=1', '/?build=draft&seo_marks=1'] as $path) {
            $anonymous = $this->site()->client('visitor')->get($path)->body;
            $this->assertStringNotContainsString('data-seo-mark', $anonymous, 'a visitor never gets the marks');
            $this->assertStringNotContainsString('seo-marks.css', $anonymous);
        }
        $this->assertStringNotContainsString('data-seo-mark', $this->site()->admin()->get('/?build=draft')->body, 'only when asked for');
    }

    public function testOnlyASignedInPagesEditorGetsTheMarks(): void
    {
        $site = $this->site();
        $id = $this->home();
        // a signed-in user without the Pages section
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, 1, NOW(), NOW())', ['news-only', password_hash($site->password, PASSWORD_DEFAULT), 'news-only']);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'news' FROM tl_users WHERE username = 'news-only'");
        $client = $site->client('news-only');
        $site->signIn($client, 'news-only');
        $body = $client->get('/?build=draft&seo_marks=1')->body;
        $this->assertStringNotContainsString('data-seo-mark', $body, 'no Pages right, no marks');
        $this->assertStringNotContainsString('seo-marks.css', $body);

        // a signed preview link opens the draft, but its holder without a session gets no marks
        preg_match('/preview_key=([^"&\\\s]+)/', $site->mcpText('preview_link', ['id' => $id]), $key);
        $this->assertNotEmpty($key[1] ?? '', 'a preview link');
        $shown = $site->client('holder')->get('/?build=draft&seo_marks=1&preview_key=' . $key[1]);
        $this->assertSame(200, $shown->status);
        $this->assertStringContainsString('Photos in Jena', $shown->body, 'the draft is shown');
        $this->assertStringNotContainsString('data-seo-mark', $shown->body);
        $this->assertStringNotContainsString('seo-marks.css', $shown->body);
    }

    public function testTheToolIsHiddenForATextPage(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Plain', 'slug' => 'plain-text', 'visible' => true, 'in_menu' => false, 'content' => '<p>Text.</p>']);
        $id = (string) $site->value("SELECT public_id FROM tl_pages WHERE slug = 'plain-text'");
        $body = $this->assertPage('/admin.php?module=pages&action=website&id=' . $id . '&marks=1', 200)->body;
        $this->assertStringNotContainsString('SEO on the page', $body);
        $this->assertStringNotContainsString('seo_marks=1', $body);
    }

    public function testTheTreeShowsAScoreDot(): void
    {
        $id = $this->home();
        $this->site()->mcp('create_page', ['title' => 'No keyword', 'slug' => 'no-keyword', 'visible' => true, 'in_menu' => false, 'content' => '<p>Text.</p>']);
        $this->site()->exec('UPDATE tl_pages SET seo_score = 86 WHERE public_id = ?', [$id]);
        $body = $this->assertPage('/admin.php?module=pages&action=website&id=' . $id, 200)->body;
        $this->assertMatchesRegularExpression('/class="website-dot website-dot-good"[^>]*>.*?86/s', $body);
        $this->assertMatchesRegularExpression('/website-dot-none" title="No focus keyword"/', $body, 'a page without a keyword has an empty ring');
        $this->site()->exec("UPDATE tl_pages SET focus_keywords = 'plain', seo_score = NULL WHERE slug = 'no-keyword'");
        $this->assertStringContainsString('website-dot-none" title="Not checked yet"', $this->site()->admin()->get('/admin.php?module=pages&action=website&id=' . $id)->body, 'a keyword without a score yet');
    }
}
