<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Members;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** set_content_groups needs the same rights as editing the content: authors touch only their own drafts, visible content needs the publishing permission. */
#[Group('site')]
final class ContentGroupsAuthorizationTest extends SiteTestCase
{
    private function token(string $user, string $module): string
    {
        $site = $this->site();
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, 0, NOW(), NOW())', [$user, '', $user]);
        $site->exec('INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, ? FROM tl_users WHERE username = ?', [$module, $user]);
        $token = 'talea_' . substr(hash('sha256', $user), 0, 48);
        $site->exec('INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, ?, ?, NOW() FROM tl_users WHERE username = ?', [$user, hash('sha256', $token), $user]);

        return $token;
    }

    private function gated(string $type, string $id): int
    {
        return (int) $this->site()->value('SELECT COUNT(*) FROM tl_content_groups WHERE content_type = ? AND content_id = ?', [$type, $this->site()->rowId($id)]);
    }

    private function refused(array $answer): bool
    {
        return !empty($answer['result']['isError']);
    }

    public function testGatingNeedsTheRightsToEditTheContent(): void
    {
        $site = $this->site();
        $site->setting('extensions', $site->settingValue('extensions') . ',members');
        $site->exec("INSERT INTO tl_member_groups (name, created_at) VALUES ('Clients', NOW())");
        $group = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Clients'");
        $category = (string) $site->value('SELECT name FROM tl_categories ORDER BY category_id LIMIT 1');

        $other = $site->mcpResult('create_news', ['title' => 'Other author news', 'category' => $category, 'content' => '<p>x</p>']);
        $site->exec('UPDATE tl_news SET visible = 1, published_at = NOW() - INTERVAL 1 DAY WHERE news_id = ?', [$site->rowId($other['id'])]);
        $site->mcpResult('set_content_groups', ['type' => 'news', 'id' => $other['id'], 'groups' => [$group]]);
        $page = $site->mcpResult('create_page', ['title' => 'Visible page', 'slug' => 'visible-gated', 'content' => '<p>x</p>', 'visible' => true]);
        $site->mcpResult('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => [$group]]);
        $this->assertSame(1, $this->gated('news', $other['id']), 'the administrator can gate');

        $author = $this->token('gate-author', 'news');
        $this->assertTrue($this->refused($site->mcp('set_content_groups', ['type' => 'news', 'id' => $other['id'], 'groups' => []], $author)), 'an author cannot ungate somebody else\'s published news');
        $this->assertSame(1, $this->gated('news', $other['id']));

        $own = $site->mcp('create_news', ['title' => 'Own draft', 'category' => $category, 'content' => '<p>y</p>'], $author);
        $ownId = json_decode($own['result']['content'][0]['text'], true)['id'];
        $this->assertFalse($this->refused($site->mcp('set_content_groups', ['type' => 'news', 'id' => $ownId, 'groups' => [$group]], $author)), 'an author may gate their own unpublished news');
        $this->assertSame(1, $this->gated('news', $ownId));

        $writer = $this->token('gate-pages', 'pages');
        $this->assertTrue($this->refused($site->mcp('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => []], $writer)), 'a level-0 pages user cannot ungate a visible page');
        $this->assertSame(1, $this->gated('page', $page['id']));

        $site->mcpResult('set_content_groups', ['type' => 'news', 'id' => $other['id'], 'groups' => []]);
        $this->assertSame(0, $this->gated('news', $other['id']), 'the administrator still can');
    }

    public function testScheduledHiddenPageCountsAsVisible(): void
    {
        $site = $this->site();
        $site->setting('extensions', $site->settingValue('extensions') . ',members');
        $site->exec("INSERT INTO tl_member_groups (name, created_at) VALUES ('Scheduled clients', NOW())");
        $group = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Scheduled clients'");

        $page = $site->mcpResult('create_page', ['title' => 'Scheduled page', 'slug' => 'scheduled-gated', 'content' => '<p>x</p>']);
        $site->exec('UPDATE tl_pages SET visible = 0, publish_at = NOW() + INTERVAL 1 DAY WHERE page_id = ?', [$site->rowId($page['id'])]);
        $site->mcpResult('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => [$group]]);
        $this->assertSame(1, $this->gated('page', $page['id']));

        $writer = $this->token('gate-scheduled', 'pages');
        $this->assertTrue($this->refused($site->mcp('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => []], $writer)), 'a level-0 pages user cannot ungate a page that publishes itself later');
        $this->assertSame(1, $this->gated('page', $page['id']), 'the groups stay');
    }
}
