<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

use PHPUnit\Framework\Attributes\Group;

/** Short links over MCP: create, retarget, list and delete by public id; a drafts-only connection can look but not change. */
#[Group('site')]
final class ShortLinksMcpTest extends \Talea\Tests\Site\Support\SiteTestCase
{
    use FakeServices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site()->exec('DELETE FROM tl_short_links');
        // the test installer switches on a fixed list of features
        $this->site()->setting('extensions', implode(',', array_unique([...\Talea\Core\Extensions::fromValue($this->site()->settingValue('extensions')), 'short_links'])));
        $this->site()->clearPageCache();
    }

    private function count(): int
    {
        return (int) $this->site()->value('SELECT COUNT(*) FROM tl_short_links');
    }

    public function testMcpCreateRetargetDelete(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('"id"', $this->mcpText('create_page', ['title' => 'Springpage', 'slug' => 'springpage', 'visible' => true]));
        $page = $site->publicId('pages', (int) $site->value('SELECT page_id FROM tl_pages WHERE slug = ?', ['springpage']));
        $visitor = $site->client('visitor');

        $made = $site->mcpResult('save_short_link', ['name' => 'spring', 'target' => ['type' => 'page', 'id' => $page], 'utm' => ['source' => 'flyer', 'medium' => 'print']]);
        $this->assertSame('spring', $made['path']);
        $this->assertStringStartsWith('<svg', ltrim($made['qr_svg']));
        $this->assertSame(['page', $page], [$made['target']['type'], $made['target']['id']], 'a public id, never a row number');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $made['id']);
        $this->assertSame($site->base . '/springpage?utm_source=flyer&utm_medium=print', $visitor->get('/spring')->redirect);

        $changed = $site->mcpResult('save_short_link', ['id' => $made['id'], 'target' => ['type' => 'url', 'url' => 'https://example.org/new']]);
        $this->assertSame('flyer', $changed['utm']['source'], 'omitted fields stay');
        $this->assertSame('https://example.org/new?utm_source=flyer&utm_medium=print', $visitor->get('/spring')->redirect);

        $listed = $site->mcpResult('list_short_links')['short_links'];
        $this->assertCount(1, $listed);
        $this->assertSame(['url', 'https://example.org/new', true, $made['id']], [$listed[0]['target']['type'], $listed[0]['target']['url'], $listed[0]['target_public'], $listed[0]['id']]);
        $this->assertSame($site->base . '/spring', $listed[0]['url']);

        $random = $site->mcpResult('save_short_link', ['target' => ['type' => 'page', 'id' => $page]]);
        $this->assertMatchesRegularExpression('#^s/[2-9a-hjkmnp-z]{6}$#', $random['path']);

        $this->assertSame(['deleted' => true], $site->mcpResult('delete_short_link', ['id' => $made['id']]));
        $this->assertNotSame(302, $visitor->get('/spring')->status);
        $this->assertSame(1, $this->count());
    }

    public function testRefusals(): void
    {
        $site = $this->site();
        $r = $site->mcp('save_short_link', ['name' => 'Bad Name', 'target' => ['type' => 'url', 'url' => 'https://example.org']]);
        $this->assertTrue($r['result']['isError'] ?? false);
        $r = $site->mcp('save_short_link', ['target' => ['type' => 'page', 'id' => '3f2b8c1e-0000-4000-8000-000000000000']]);
        $this->assertTrue($r['result']['isError'] ?? false, 'an id that names no page');
        $this->assertSame(0, $this->count());
    }

    public function testDraftsOnlyCannotChange(): void
    {
        $site = $this->site();
        $site->mcpResult('save_short_link', ['name' => 'keep', 'target' => ['type' => 'url', 'url' => 'https://example.org']]);
        $token = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, access, created_at) SELECT user_id, 'drafty', ?, 'drafts', NOW() FROM tl_users WHERE username = 'admin'", [hash('sha256', $token)]);

        $r = $site->mcp('save_short_link', ['name' => 'nope', 'target' => ['type' => 'url', 'url' => 'https://example.org']], $token);
        $this->assertTrue($r['result']['isError'] ?? false);
        $this->assertSame(1, $this->count(), 'nothing stored');
        $this->assertCount(1, $site->mcpResult('list_short_links', [], $token)['short_links']);
        $id = $site->mcpResult('list_short_links')['short_links'][0]['id'];
        $this->assertTrue($site->mcp('delete_short_link', ['id' => $id], $token)['result']['isError'] ?? false);
        $this->assertSame(1, $this->count());
    }
}
