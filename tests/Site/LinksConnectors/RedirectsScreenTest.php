<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** The Redirects screen: several sources per add, match types, tabs, row actions and the MCP tools. */
#[Group('site')]
final class RedirectsScreenTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    use FakeServices;

    private function rows(string $where = '1 = 1'): int
    {
        return (int) $this->site()->value('SELECT COUNT(*) FROM tl_redirects WHERE ' . $where);
    }

    private function publicId(string $from): string
    {
        return (string) $this->site()->value('SELECT public_id FROM tl_redirects WHERE from_path = ?', [$from]);
    }

    public function testSeveralSourcesAndRefusals(): void
    {
        $site = $this->site();
        $r = $this->adminPost('/admin.php?module=redirects&action=save', ['from_path' => "/one-old\n\n/two-old\n", 'to_path' => '/new-home', 'type' => '301', 'match' => 'exact'], '/admin.php?module=redirects');
        $this->assertSame(302, $r->status);
        $this->assertSame(2, $this->rows("from_path IN ('one-old', 'two-old') AND to_path = 'new-home'"), 'one row per line');

        $r = $this->adminPost('/admin.php?module=redirects&action=save', ['from_path' => "/fine-old\n(", 'to_path' => '/x', 'type' => '301', 'match' => 'regex'], '/admin.php?module=redirects');
        $this->assertSame(0, $this->rows("from_path = 'fine-old'"), 'one refused line saves nothing');
        $this->assertStringContainsString('The regular expression is not valid.', $site->admin()->get('/admin.php?module=redirects&new=1')->body, 'the validate message is shown');

        $this->adminPost('/admin.php?module=redirects&action=save', ['from_path' => '/mail-old', 'to_path' => 'mailto:a@b.c', 'type' => '301', 'match' => 'exact'], '/admin.php?module=redirects');
        $this->assertSame(0, $this->rows("from_path = 'mail-old'"), 'a target that could never be used is refused');
    }

    public function testToggleDuplicateTestAndTabs(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_redirects');
        $this->adminPost('/admin.php?module=redirects&action=save', ['from_path' => '/blog/', 'to_path' => '/news/', 'type' => '307', 'match' => 'starts'], '/admin.php?module=redirects');
        $this->assertSame('starts|307|1', $site->value("SELECT CONCAT(match_type, '|', type, '|', active) FROM tl_redirects WHERE from_path = 'blog/'"));
        $id = $this->publicId('blog/');
        $site->exec("INSERT INTO tl_redirects (public_id, from_path, to_path, auto_score, created_at) VALUES ('" . bin2hex(random_bytes(16)) . "', 'made-by-job', 'news', 90, '" . date('Y-m-d H:i:s') . "')");

        $this->adminPost('/admin.php?module=redirects&action=toggle', ['redirect_id' => $id], '/admin.php?module=redirects');
        $this->assertSame('0', (string) $site->value("SELECT active FROM tl_redirects WHERE from_path = 'blog/'"), 'toggle switches the rule off');
        $off = $this->assertPage('/admin.php?module=redirects&status=off', 200, 'blog/');
        $this->assertStringNotContainsString('made-by-job', $off->body);
        $all = $this->assertPage('/admin.php?module=redirects', 200, ['Made by Talea']);
        $this->assertMatchesRegularExpression('/All\s*<span class="list-tab-count">2</', $all->body);
        $this->assertMatchesRegularExpression('/Active\s*<span class="list-tab-count">1</', $all->body);
        $this->assertMatchesRegularExpression('/Off\s*<span class="list-tab-count">1</', $all->body);
        $auto = $this->assertPage('/admin.php?module=redirects&status=auto', 200, 'made-by-job');
        $this->assertStringNotContainsString('/blog/', $auto->body);

        $this->adminPost('/admin.php?module=redirects&action=toggle', ['redirect_id' => $id], '/admin.php?module=redirects');
        $this->assertSame('1', (string) $site->value("SELECT active FROM tl_redirects WHERE from_path = 'blog/'"), 'toggle switches it back on');

        $this->adminPost('/admin.php?module=redirects&action=duplicate', ['redirect_id' => $id], '/admin.php?module=redirects');
        $this->assertSame(2, $this->rows("match_type = 'starts' AND to_path = 'news/'"), 'duplicate makes a second row');

        $this->assertPage('/admin.php?module=redirects&action=test&id=' . $id . '&path=blog/a/b', 200, '/news/a/b', message: 'test shows the target');
        $this->assertPage('/admin.php?module=redirects&action=test&id=' . $id . '&path=shop/x', 200, 'does not match', message: 'test says no match');
    }

    public function testMcp(): void
    {
        $site = $this->site();
        $site->mcp('save_redirect', ['from' => '/retired/', 'match' => 'starts', 'code' => 410]);
        $this->assertSame('starts|410|1', $site->value("SELECT CONCAT(match_type, '|', type, '|', active) FROM tl_redirects WHERE from_path = 'retired/'"));
        $site->mcp('save_redirect', ['from' => '/paused', 'to' => '/elsewhere', 'active' => false]);
        $this->assertSame('0', (string) $site->value("SELECT active FROM tl_redirects WHERE from_path = 'paused'"));

        $json = $this->mcpJson('list_redirects');
        $this->assertStringContainsString('"match":"starts"', $json);
        $this->assertStringContainsString('"active":false', $json);
        $this->assertStringContainsString('"last_hit_at":null', $json);

        $bad = $site->mcp('save_redirect', ['from' => '/(', 'to' => '/x', 'match' => 'regex']);
        $this->assertStringContainsString('The regular expression is not valid.', json_encode($bad));
        $this->assertSame(0, $this->rows("from_path = '('"));
    }

    public function testUpdatesKeepSettingsAndClashesAreRefused(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tl_redirects');
        $site->mcp('save_redirect', ['from' => '/keep', 'to' => '/a', 'code' => 302, 'active' => 'false']);
        $this->assertSame('302|0', $site->value("SELECT CONCAT(type, '|', active) FROM tl_redirects WHERE from_path = 'keep'"), 'active parsed as a real boolean');
        $site->mcp('save_redirect', ['from' => '/keep', 'to' => '/b']);
        $this->assertSame('b|302|0', $site->value("SELECT CONCAT(to_path, '|', type, '|', active) FROM tl_redirects WHERE from_path = 'keep'"), 'an update without active and code keeps them');
        $site->mcp('save_redirect', ['from' => '/keep', 'to' => '/b', 'active' => true]);
        $this->assertSame('1', (string) $site->value("SELECT active FROM tl_redirects WHERE from_path = 'keep'"));

        // delete a non-exact rule by its from
        $site->mcp('save_redirect', ['from' => '^gone/(\d+)$', 'to' => '/x/$1', 'match' => 'regex']);
        $this->assertSame(1, $this->rows("match_type = 'regex'"));
        $site->mcp('save_redirect', ['from' => '^gone/(\d+)$', 'match' => 'regex', 'delete' => true]);
        $this->assertSame(0, $this->rows("match_type = 'regex'"), 'a regex rule is deleted by its from');

        // an unrelated exact add leaves the other kinds alone
        $site->mcp('save_redirect', ['from' => '/section/', 'to' => '/new-section/', 'match' => 'starts']);
        $site->mcp('save_redirect', ['from' => '/something', 'to' => '/section/']);
        $this->assertSame(1, $this->rows("match_type = 'starts' AND to_path = 'new-section/'"));

        // editing onto an address another row uses
        $one = $this->publicId('keep');
        $this->adminPost('/admin.php?module=redirects&action=save', ['redirect_id' => $one, 'from_path' => '/something', 'to_path' => '/b', 'type' => '301', 'match' => 'exact'], '/admin.php?module=redirects');
        $this->assertSame(1, $this->rows("from_path = 'keep'"), 'the edit was refused');
        $this->assertStringContainsString('Another redirect already uses this old address.', $site->admin()->get('/admin.php?module=redirects')->body);

        // a duplicate starts switched off and an invalid copy is refused
        $this->adminPost('/admin.php?module=redirects&action=duplicate', ['redirect_id' => $one], '/admin.php?module=redirects');
        $this->assertSame(1, $this->rows("from_path = 'keep-copy' AND active = 0"));
        $before = $this->rows();
        $site->exec("INSERT INTO tl_redirects (public_id, from_path, to_path, match_type, type, created_at) VALUES ('" . bin2hex(random_bytes(16)) . "', 'ab', 'ab-copy', 'exact', 301, '" . date('Y-m-d H:i:s') . "')");
        $loop = $this->publicId('ab');
        $this->adminPost('/admin.php?module=redirects&action=duplicate', ['redirect_id' => $loop], '/admin.php?module=redirects');
        $this->assertSame($before + 1, $this->rows(), 'a copy that would be invalid is not stored');

        // toggle keeps the tab and says what happened
        $r = $this->adminPost('/admin.php?module=redirects&action=toggle', ['redirect_id' => $one, 'status' => 'active', 'search' => 'ke'], '/admin.php?module=redirects');
        $this->assertStringContainsString('status=active', (string) $r->redirect);
        $this->assertStringContainsString('search=ke', (string) $r->redirect);
        $this->assertStringContainsString('Redirect switched off.', $site->admin()->get((string) $r->redirect)->body);
    }
}
