<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** Search engines → Missing pages: the 404 log with the referrer, a suggested target and one-click redirect or ignore. */
#[Group('site')]
final class MissingPagesTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    use FakeServices;

    public function testTheScreenListsAddressesWithReferrerAndDecidesThem(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Old guide', 'slug' => 'old-x', 'visible' => true, 'in_menu' => false, 'content' => '<p>Guide.</p>']);
        $site->exec("UPDATE tl_pages SET slug = 'help/old-x' WHERE slug = 'old-x'");
        $site->exec('DELETE FROM tl_redirects');
        $site->exec('DELETE FROM tl_not_found');
        $site->clearPageCache();
        $visitor = $site->client('visitor');
        for ($i = 0; $i < 3; $i++) {
            $visitor->get('/old-x', ['Referer: https://other.example/list?q=1#top']);
            $visitor->get('/wp-login.php');
            $visitor->get('/old-y', ['Referer: ' . $site->base . '/portraits?a=1']);
        }
        $this->assertSame('other.example/list', (string) $site->value("SELECT referrer FROM tl_not_found WHERE path = 'old-x'"), 'host and path of the referrer, no query');
        $this->assertSame('/portraits', (string) $site->value("SELECT referrer FROM tl_not_found WHERE path = 'old-y'"), 'an own-site referrer is stored as its path');
        $this->assertNotNull($site->value("SELECT first_seen_at FROM tl_not_found WHERE path = 'old-x'"), 'first_seen_at is set');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_not_found WHERE path = 'wp-login.php'"), 'bot probes are never recorded');

        $screen = $this->assertPage('/admin.php?module=missing_pages', 200, ['other.example/list', 'your site: /portraits', '/help/old-x', 'name="to"', 'name="path"', 'Ignore', 'Redirect'], message: 'the list');
        $this->assertStringNotContainsString('q=1', $screen->body);

        $this->assertStringContainsString('"referrer":"other.example/list"', $this->mcpJson('list_redirects'), 'MCP: list_redirects carries the referrer');
        $this->adminPost('/admin.php?module=missing_pages&action=redirect', ['path' => 'old-x', 'to' => '/help/old-x'], '/admin.php?module=missing_pages');
        $this->assertSame('/help/old-x', '/' . $site->value("SELECT to_path FROM tl_redirects WHERE from_path = 'old-x'"), 'a 301 was created');
        $this->assertSame('301', (string) $site->value("SELECT type FROM tl_redirects WHERE from_path = 'old-x'"));
        $this->assertPageLacks('/admin.php?module=missing_pages', 'other.example/list', message: 'the row left "To decide"');

        $this->adminPost('/admin.php?module=missing_pages&action=redirect', ['path' => 'old-y', 'to' => 'javascript:alert(1)'], '/admin.php?module=missing_pages');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_redirects WHERE from_path = 'old-y'"), 'an unsafe target is refused');

        $this->adminPost('/admin.php?module=missing_pages&action=ignore', ['path' => 'old-y'], '/admin.php?module=missing_pages');
        $this->assertPageLacks('/admin.php?module=missing_pages', 'your site: /portraits', message: 'ignored leaves "To decide"');
        $this->assertPage('/admin.php?module=missing_pages&status=ignored', 200, ['/old-y', 'your site: /portraits'], message: 'and shows under Ignored');
    }

    public function testASwitchedOffRuleLeavesTheAddressMissing(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_not_found');
        $site->mcp('save_redirect', ['from' => '/old-z', 'to' => '/somewhere', 'active' => false]);
        $site->clearPageCache();
        $visitor = $site->client('visitor');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(404, $visitor->get('/old-z')->status);
        }
        $this->assertPage('/admin.php?module=missing_pages', 200, '/old-z', message: 'an address with a switched-off rule is still missing');

        $this->adminPost('/admin.php?module=missing_pages&action=redirect', ['path' => 'old-home', 'to' => '/'], '/admin.php?module=missing_pages');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_redirects WHERE from_path = 'old-home' AND to_path = ''"), 'the home page is a valid target');
    }

    public function testClearKeepsIgnoredAddresses(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_not_found');
        $site->exec("INSERT INTO tl_not_found (path, count, last_seen_at) VALUES ('wait-here', 5, NOW())");
        $site->exec("INSERT INTO tl_not_found (path, count, last_seen_at, ignored_at) VALUES ('kept-ignored', 5, NOW(), NOW())");
        $this->adminPost('/admin.php?module=missing_pages&action=clear', [], '/admin.php?module=missing_pages');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_not_found WHERE path = 'wait-here'"), 'a pending address is gone');
        $this->assertPage('/admin.php?module=missing_pages&status=ignored', 200, '/kept-ignored', message: 'the ignored address stays');
    }
}
