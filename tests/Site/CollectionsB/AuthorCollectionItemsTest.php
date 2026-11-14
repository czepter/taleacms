<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsB;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/** An account whose role does not publish saves collection items as hidden drafts, in the admin and over MCP; tags stay an editor's (#332). */
#[Group('site')]
final class AuthorCollectionItemsTest extends SiteTestCase
{
    private static ?Http $writer = null;
    private static string $liveId = '';

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    private function writer(): Http
    {
        if (self::$writer === null) {
            $site = $this->site();
            $site->signIn($site->admin());
            $this->adminPost('/admin.php?module=roles&action=save', ['role_id' => 0, 'name' => 'Writer', 'level' => 0, 'modules' => ['collections', 'tags']], '/admin.php?module=roles');
            $role = (int) $site->value("SELECT role_id FROM tl_role WHERE name = 'Writer'");
            $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Writer', 'username' => 'writer', 'password' => $site->password, 'admin' => 'r' . $role]);
            $this->adminPost('/admin.php?module=collections&action=save', ['collection_id' => 0, 'name' => 'Team', 'detail' => 1, 'fields' => [['label' => 'Role', 'type' => 'text']]], '/admin.php?module=collections');
            self::$liveId = (string) $site->mcpResult('save_collection_item', ['collection' => 'team', 'name' => 'Live member', 'values' => ['role' => 'Carpenter'], 'visible' => true])['id'];
            self::$writer = $site->client('writer');
            $site->signIn(self::$writer, 'writer');
        }

        return self::$writer;
    }

    private function csrf(): string
    {
        return (string) ($this->writer()->get('/admin.php?action=token')->json()['csrf'] ?? '');
    }

    private function collectionId(): string
    {
        return (string) $this->site()->value("SELECT public_id FROM tl_collections WHERE slug = 'team'");
    }

    public function testAnAuthorSavesHiddenDrafts(): void
    {
        $site = $this->site();
        $writer = $this->writer();
        $writer->post('/admin.php?module=collections&action=save_item', ['_csrf' => $this->csrf(), 'collection_id' => $this->collectionId(), 'item_id' => '', 'name' => 'Draft member', 'visible' => '1', 'data' => ['role' => 'Joiner']]);
        $this->assertSame('0', (string) $site->value("SELECT visible FROM tl_collection_items WHERE name = 'Draft member'"), 'a new item is saved hidden whatever the form says');

        $live = $site->internalId('collection_items', self::$liveId);
        $before = (string) $site->value('SELECT updated_at FROM tl_collection_items WHERE item_id = ?', [$live]);
        $writer->post('/admin.php?module=collections&action=save_item', ['_csrf' => $this->csrf(), 'collection_id' => $this->collectionId(), 'item_id' => self::$liveId, 'name' => 'Overwritten', 'visible' => '1', 'data' => ['role' => 'x']]);
        $this->assertSame('Live member', (string) $site->value('SELECT name FROM tl_collection_items WHERE item_id = ?', [$live]), 'an item on the site is left to an editor');

        $writer->post('/admin.php?module=collections&action=bulk_items', ['_csrf' => $this->csrf(), 'collection_id' => $this->collectionId(), 'bulk' => 'visible', 'selected' => [$site->publicId('collection_items', (int) $site->value("SELECT item_id FROM tl_collection_items WHERE name = 'Draft member'"))]]);
        $this->assertSame('0', (string) $site->value("SELECT visible FROM tl_collection_items WHERE name = 'Draft member'"), 'an author cannot publish in bulk');
        $writer->post('/admin.php?module=collections&action=bulk_items', ['_csrf' => $this->csrf(), 'collection_id' => $this->collectionId(), 'bulk' => 'trash', 'selected' => [self::$liveId]]);
        $this->assertNull($site->value('SELECT deleted_at FROM tl_collection_items WHERE item_id = ?', [$live]), 'the bulk trash skips an item on the site');
        $writer->post('/admin.php?module=collections&action=delete_item', ['_csrf' => $this->csrf(), 'collection_id' => $this->collectionId(), 'item_id' => self::$liveId]);
        $this->assertNull($site->value('SELECT deleted_at FROM tl_collection_items WHERE item_id = ?', [$live]), 'an author does not trash an item on the site');
        $this->assertSame($before, (string) $site->value('SELECT updated_at FROM tl_collection_items WHERE item_id = ?', [$live]));

        $form = $writer->get('/admin.php?module=collections&action=item&id=' . $this->collectionId());
        $this->assertStringContainsString('An editor or an administrator publishes the item.', $form->body);
    }

    public function testAnAuthorDoesNotRenameMergeOrDeleteTags(): void
    {
        $site = $this->site();
        $writer = $this->writer();
        $site->exec("INSERT INTO tl_tags (public_id, name, slug) VALUES ('11111111-1111-4111-8111-111111111111', 'one', 'one'), ('22222222-2222-4222-8222-222222222222', 'two', 'two')");
        $writer->post('/admin.php?module=tags&action=save', ['_csrf' => $this->csrf(), 'tag_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'renamed', 'merge_into' => '22222222-2222-4222-8222-222222222222']);
        $writer->post('/admin.php?module=tags&action=delete', ['_csrf' => $this->csrf(), 'tag_id' => '22222222-2222-4222-8222-222222222222']);
        $this->assertSame('one,two', implode(',', array_column($site->rows('SELECT name FROM tl_tags ORDER BY name'), 'name')), 'neither renamed, merged nor deleted');
    }

    public function testAnAuthorConnectionSavesHiddenOverMcp(): void
    {
        $site = $this->site();
        $writer = $this->writer();
        $page = $writer->post('/admin.php?action=account', ['_csrf' => $this->csrf(), 'op' => 'token_new', 'name' => 'Writer token', 'access' => 'full', 'lifetime' => '0', 'current_password' => $site->password]);
        $this->assertSame(1, preg_match('/talea_[a-f0-9]{48}/', $page->body, $m), 'the author has a full-access token');
        $created = $site->mcpResult('save_collection_item', ['collection' => 'team', 'name' => 'Proposed by writer', 'values' => ['role' => 'Joiner'], 'visible' => true], $m[0]);
        $this->assertIsArray($created);
        $this->assertFalse($created['visible'], 'saved hidden whatever visible says');
        $this->assertSame('0', (string) $site->value("SELECT visible FROM tl_collection_items WHERE name = 'Proposed by writer'"));
        $refused = $site->mcpText('save_collection_item', ['collection' => 'team', 'id' => self::$liveId, 'name' => 'Overwritten'], $m[0]) . $this->raw($site->mcp('save_collection_item', ['collection' => 'team', 'id' => self::$liveId, 'name' => 'Overwritten'], $m[0]));
        $this->assertStringContainsString('editor or an administrator', $refused);
        $this->assertSame('Live member', (string) $site->value('SELECT name FROM tl_collection_items WHERE item_id = ?', [$site->internalId('collection_items', self::$liveId)]));
        $site->mcp('save_collection_item', ['collection' => 'team', 'id' => self::$liveId, 'values' => ['role' => 'Master carpenter']]); // the administrator makes a version
        $versions = (array) $site->mcpResult('list_item_versions', ['collection' => 'team', 'id' => self::$liveId]);
        $restore = $this->raw($site->mcp('restore_item_version', ['collection' => 'team', 'id' => self::$liveId, 'version' => $versions['versions'][0]['id'] ?? 0], $m[0]));
        $this->assertStringContainsString('editor or an administrator', $restore, 'an author does not bring an earlier version of a live item back');
        $deleted = $this->raw($site->mcp('delete_collection_item', ['collection' => 'team', 'id' => self::$liveId], $m[0]));
        $this->assertStringContainsString('editor or an administrator', $deleted);
    }

    /** @param array<string, mixed> $answer */
    private function raw(array $answer): string
    {
        return (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
