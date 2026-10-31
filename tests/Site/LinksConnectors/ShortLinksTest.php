<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** Short links on the public site: /<name> and /s/<code> lead to their target with the campaign fields, count, and keep their address. */
#[Group('site')]
final class ShortLinksTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    use FakeServices;

    /** @param array<string, string> $fields */
    private function link(string $path, string $type, int|string|null $target, array $fields = []): void
    {
        $this->site()->exec(
            'INSERT INTO tl_short_links (public_id, path, target_type, target_id, target_url, utm_source, utm_medium, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [bin2hex(random_bytes(16)), $path, $type, is_int($target) ? $target : null, is_string($target) ? $target : null, $fields['utm_source'] ?? '', $fields['utm_medium'] ?? '', date('Y-m-d H:i:s')],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->exec('DELETE FROM tl_short_links');
        // the test installer switches on a fixed list of features
        $this->site()->setting('extensions', implode(',', array_unique([...\Talea\Core\Extensions::fromValue($this->site()->settingValue('extensions')), 'short_links'])));
        $this->site()->clearPageCache();
    }

    private function hits(string $path): int
    {
        return (int) $this->site()->value('SELECT hits FROM tl_short_links WHERE path = ?', [$path]);
    }

    private function pageId(string $slug): int
    {
        return (int) $this->site()->value('SELECT page_id FROM tl_pages WHERE slug = ?', [$slug]);
    }

    private function create(string $tool, array $arguments): void
    {
        $this->assertStringContainsString('"id"', $this->site()->mcpText($tool, $arguments));
    }

    public function testNamedAndRandomFollowWithUtm(): void
    {
        $site = $this->site();
        $this->create('create_page', ['title' => 'Aboutus', 'slug' => 'aboutus', 'visible' => true]);
        $this->link('flyer', 'page', $this->pageId('aboutus'), ['utm_source' => 'flyer', 'utm_medium' => 'print']);
        $this->link('s/k7f2qm', 'url', 'https://example.org/x');
        $visitor = $site->client('visitor');

        $r = $visitor->get('/flyer');
        $this->assertSame([302, $site->base . '/aboutus?utm_source=flyer&utm_medium=print'], [$r->status, $r->redirect]);
        $this->assertStringContainsString('no-store', $r->headers['cache-control'] ?? '');
        $this->assertStringContainsString('noindex', $r->headers['x-robots-tag'] ?? '');
        $r = $visitor->get('/FLYER');
        $this->assertSame([302, $site->base . '/aboutus?utm_source=flyer&utm_medium=print'], [$r->status, $r->redirect], 'typed from paper');
        $r = $visitor->get('/S/K7F2QM');
        $this->assertSame([302, 'https://example.org/x'], [$r->status, $r->redirect], 'the code typed with a capital s and capitals');
        $r = $visitor->get('/s/k7f2qm');
        $this->assertSame([302, 'https://example.org/x'], [$r->status, $r->redirect]);
        $this->assertSame([2, 2], [$this->hits('flyer'), $this->hits('s/k7f2qm')]);
        $this->assertNotNull($site->value('SELECT last_hit_at FROM tl_short_links WHERE path = ?', ['flyer']));

        $this->assertNotSame(302, $visitor->post('/flyer', [])->status, 'GET and HEAD only');
        $this->assertSame(404, $visitor->get('/nothing-here')->status, 'no row: the next route answers');
    }

    public function testFollowsRenameAndFallsBackHome(): void
    {
        $site = $this->site();
        $this->create('create_page', ['title' => 'Offer', 'slug' => 'offer', 'visible' => true]);
        $this->link('flyer', 'page', $this->pageId('offer'), ['utm_source' => 'flyer']);
        $visitor = $site->client('visitor');

        $this->mcpOk('update_page', ['id' => $site->publicId('pages', $this->pageId('offer')), 'slug' => 'offer-2027']);
        $site->clearPageCache();
        $this->assertSame($site->base . '/offer-2027?utm_source=flyer', $visitor->get('/flyer')->redirect, 'the link follows the new address');

        $this->mcpOk('trash_page', ['id' => $site->publicId('pages', $this->pageId('offer-2027'))]);
        $r = $visitor->get('/flyer');
        $this->assertSame([302, $site->base . '/'], [$r->status, $r->redirect], 'a trashed target leads home');
    }

    public function testHomePageKeepsTheCampaignFields(): void
    {
        $site = $this->site();
        $this->link('qrcard', 'url', '', ['utm_source' => 'card']);
        $r = $site->client('visitor')->get('/qrcard');
        $this->assertSame([302, $site->base . '/?utm_source=card', ''], [$r->status, $r->redirect, $r->body]);
    }

    public function testNewsAndCollectionItemTargets(): void
    {
        $site = $this->site();
        $this->create('create_news', ['title' => 'Spring offer', 'slug' => 'spring-offer', 'category' => 'News', 'content' => '<p>Hello</p>']);
        $newsId = (int) $site->value("SELECT news_id FROM tl_news WHERE slug = 'spring-offer'");
        $site->exec("UPDATE tl_news SET visible = TRUE, published_at = '2026-01-01 10:00:00' WHERE news_id = ?", [$newsId]);
        $this->link('spring', 'news', $newsId, ['utm_source' => 'poster']);

        $this->mcpOk('create_collection', ['name' => 'Crew', 'item_pages' => true, 'redirect_hidden_to' => '/landing']);
        $this->mcpOk('save_collection_item', ['collection' => 'crew', 'name' => 'Jane Doe', 'slug' => 'jane', 'visible' => true]);
        $this->mcpOk('save_collection_item', ['collection' => 'crew', 'name' => 'John Roe', 'slug' => 'john', 'visible' => true]);
        $jane = (int) $site->value("SELECT item_id FROM tl_collection_items WHERE slug = 'jane'");
        $john = (int) $site->value("SELECT item_id FROM tl_collection_items WHERE slug = 'john'");
        $this->link('jane', 'item', $jane, ['utm_medium' => 'qr']);
        $this->link('john', 'item', $john);
        $visitor = $site->client('visitor');

        $this->assertSame($site->base . '/news/spring-offer?utm_source=poster', $visitor->get('/spring')->redirect);
        $this->assertSame($site->base . '/crew/jane?utm_medium=qr', $visitor->get('/jane')->redirect);

        $site->exec('UPDATE tl_collection_items SET visible = FALSE WHERE item_id = ?', [$john]);
        $this->assertSame($site->base . '/crew/john', $visitor->get('/john')->redirect, 'a hidden item goes to its own address, where hidden_redirect applies');

        $site->exec('UPDATE tl_collections SET hidden_redirect = ?', ['']);
        $site->exec('UPDATE tl_news SET visible = FALSE WHERE news_id = ?', [$newsId]);
        $this->assertSame($site->base . '/', $visitor->get('/john')->redirect, 'a hidden item without a redirect rule leads home');
        $this->assertSame($site->base . '/', $visitor->get('/spring')->redirect, 'an unpublished news item leads home');
    }

    public function testAddressesStayReserved(): void
    {
        $site = $this->site();
        $this->create('create_page', ['title' => 'Landing', 'slug' => 'landing', 'visible' => true]);
        $this->link('flyer', 'page', $this->pageId('landing'));
        $this->link('s/k7f2qm', 'url', 'https://example.org/x');
        $visitor = $site->client('visitor');
        $this->assertContains('s', \Talea\Front\Kernel::reservedSlugs());

        $this->assertStringContainsString('already exists', $site->mcpText('create_page', ['title' => 'Flyer', 'slug' => 'flyer', 'visible' => true]), 'MCP refuses the taken address');
        $this->adminPost('/admin.php?module=pages&action=save', ['title' => 'Flyer', 'visible' => '1', 'text' => '<p>Hello</p>'], '/admin.php?module=pages');
        $this->assertSame(['flyer-2'], array_column($site->rows("SELECT slug FROM tl_pages WHERE slug LIKE 'flyer%'"), 'slug'), 'the admin page takes the next free address');
        $this->assertSame($site->base . '/landing', $visitor->get('/flyer')->redirect, 'the short link keeps answering');

        $set = json_encode($site->mcpResult('update_settings', ['settings' => ['news_slug' => 'flyer']]), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('short link', (string) $set);
        $this->assertNotSame('flyer', $site->settingValue('news_slug'));

        $on = \Talea\Core\Extensions::fromValue($site->settingValue('extensions'));
        $this->assertContains('short_links', $on);
        $this->mcpOk('update_settings', ['settings' => ['extensions' => implode(',', array_diff($on, ['short_links']))]]);
        $site->clearPageCache();
        $this->assertSame(404, $visitor->get('/s/k7f2qm')->status, 'extension off: no short links');
        $this->assertStringContainsString('already exists', $site->mcpText('create_page', ['title' => 'Flyer again', 'slug' => 'flyer', 'visible' => true]), 'the address stays reserved while the extension is off');
    }

    /** @param array<string, mixed> $arguments */
    private function mcpOk(string $tool, array $arguments): void
    {
        $answer = $this->site()->mcp($tool, $arguments);
        $this->assertArrayNotHasKey('error', $answer);
        $this->assertNotTrue($answer['result']['isError'] ?? false, (string) ($answer['result']['content'][0]['text'] ?? ''));
    }
}
