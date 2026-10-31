<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** The Short links admin screen: create, list, retarget, refuse, QR download, delete, and the entry links on the edit screens. */
#[Group('site')]
final class ShortLinksAdminTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    use FakeServices;

    private const string MODULE = '/admin.php?module=short_links';

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->exec('DELETE FROM tl_short_links');
        // the test installer switches on a fixed list of features
        $this->site()->setting('extensions', implode(',', array_unique([...\Talea\Core\Extensions::fromValue($this->site()->settingValue('extensions')), 'short_links'])));
        $this->site()->clearPageCache();
    }

    private function page(string $slug): string
    {
        $this->assertStringContainsString('"id"', $this->site()->mcpText('create_page', ['title' => ucfirst($slug), 'slug' => $slug, 'visible' => true]));

        return $this->site()->publicId('pages', (int) $this->site()->value('SELECT page_id FROM tl_pages WHERE slug = ?', [$slug]));
    }

    public function testAdminScreen(): void
    {
        $site = $this->site();
        $offer = $this->page('offer');

        $r = $this->adminPost(self::MODULE . '&action=save', ['kind' => 'name', 'name' => 'Poster', 'target' => 'page:' . $offer, 'utm_source' => 'poster', 'label' => 'Spring poster'], self::MODULE . '&new=1');
        $this->assertSame(302, $r->status);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_short_links WHERE path = 'poster' AND utm_source = 'poster' AND label = 'Spring poster'"), 'stored with a lower-case name');
        $id = (string) $site->value("SELECT public_id FROM tl_short_links WHERE path = 'poster'");
        $site->exec("UPDATE tl_short_links SET hits = 7 WHERE path = 'poster'");

        $list = $this->assertPage(self::MODULE, 200, ['/poster', 'Spring poster', '7']);
        $this->assertStringContainsString('data-copy=', $list->body);
        $this->assertStringContainsString('data-confirm=', $list->body, 'delete asks first');

        $qr = $site->admin()->get(self::MODULE . '&action=qr&id=' . $id);
        $this->assertSame(200, $qr->status);
        $this->assertStringContainsString('image/svg+xml', $qr->headers['content-type'] ?? '');
        $this->assertStringContainsString('qr-poster.svg', $qr->headers['content-disposition'] ?? '');
        $this->assertStringStartsWith('<svg', ltrim($qr->body));

        $r = $this->adminPost(self::MODULE . '&action=save', ['kind' => 'name', 'name' => 'news', 'target' => 'page:' . $offer], self::MODULE . '&new=1');
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_short_links WHERE path = 'news'"));
        $this->assertStringContainsString('This address is used by the system, choose another one.', $site->admin()->get((string) $r->redirect)->body, 'the refusal is shown');

        $this->adminPost(self::MODULE . '&action=save', ['kind' => 'random', 'target' => 'page:' . $offer], self::MODULE . '&new=1');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_short_links WHERE path LIKE 's/%'"), 'random code');

        $this->adminPost(self::MODULE . '&action=save', ['short_link_id' => $id, 'target' => 'url', 'target_url' => 'https://example.org/x', 'utm_source' => 'poster'], self::MODULE . '&edit=' . $id);
        $this->assertSame(['url', 'https://example.org/x', 'poster'], array_values((array) $site->rows('SELECT target_type, target_url, path FROM tl_short_links WHERE public_id = ?', [$id])[0]));

        $this->adminPost(self::MODULE . '&action=delete', ['short_link_id' => $id], self::MODULE);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_short_links WHERE path = 'poster'"));
    }

    public function testEntryLinksOnEditScreens(): void
    {
        $offer = $this->page('deal');
        $this->assertPage('/admin.php?module=pages&action=edit&id=' . $offer, 200, 'short_links?new=1&amp;target=page%3A' . $offer);

        $this->adminPost(self::MODULE . '&action=save', ['kind' => 'name', 'name' => 'flyer', 'target' => 'page:' . $offer], self::MODULE . '&new=1');
        $this->assertPage('/admin.php?module=pages&action=edit&id=' . $offer, 200, 'short_links?target=page%3A' . $offer);
        $this->assertPage(self::MODULE . '&new=1&target=page:' . $offer, 200, 'value="page:' . $offer . '" selected');
    }

    public function testNewsAndItemEditScreensOfferTheLink(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('"id"', $site->mcpText('create_news', ['title' => 'Spring sale', 'slug' => 'spring-sale', 'category' => 'News', 'content' => '<p>Hello</p>']));
        $news = $site->publicId('news', (int) $site->value("SELECT news_id FROM tl_news WHERE slug = 'spring-sale'"));
        $this->assertPage('/admin.php?module=news&action=edit&id=' . $news, 200, 'short_links?new=1&amp;target=news%3A' . $news);

        $site->mcpText('create_collection', ['name' => 'Crew', 'item_pages' => true]);
        $site->mcpText('save_collection_item', ['collection' => 'crew', 'name' => 'Jane Doe', 'slug' => 'jane', 'visible' => true]);
        $item = $site->publicId('collection_items', (int) $site->value("SELECT item_id FROM tl_collection_items WHERE slug = 'jane'"));
        $collection = $site->publicId('collections', (int) $site->value("SELECT collection_id FROM tl_collections WHERE slug = 'crew'"));
        $this->assertPage('/admin.php?module=collections&action=item&id=' . $collection . '&item=' . $item, 200, 'short_links?new=1&amp;target=item%3A' . $item);
        $this->assertPage(self::MODULE . '&new=1', 200, ['<optgroup label="News">', 'Spring sale', '<optgroup label="Crew">', 'Jane Doe']);
    }
}
