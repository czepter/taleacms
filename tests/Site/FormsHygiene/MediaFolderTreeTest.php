<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Folders inside folders (docs/specs/media-library.md): create, move, refuse a cycle, delete lifts the content one level. */
#[Group('site')]
final class MediaFolderTreeTest extends SiteTestCase
{
    use McpHelpers;

    private function parentOf(string $publicId): ?string
    {
        $parent = $this->site()->value('SELECT parent_id FROM tl_media_folders WHERE public_id = ?', [$publicId]);

        return $parent === null || $parent === false ? null : $this->site()->publicId('media_folders', (int) $parent);
    }

    public function testTreeOverMcpAndAdmin(): void
    {
        $site = $this->site();
        $weddings = (string) $site->mcpResult('save_media_folder', ['name' => 'Weddings'])['id'];
        $castle = (string) $site->mcpResult('save_media_folder', ['name' => 'Castle', 'parent' => $weddings])['id'];
        $details = (string) $site->mcpResult('save_media_folder', ['name' => 'Details', 'parent' => $castle])['id'];
        $this->assertSame($weddings, $this->parentOf($castle), 'MCP: a folder is created inside its parent');

        $list = array_column((array) $site->mcpResult('list_media_folders'), null, 'id');
        $this->assertSame([$castle, ['Weddings', 'Castle', 'Details']], [$list[$details]['parent'], $list[$details]['path']], 'MCP: list_media_folders gives parent and path');
        $this->assertNull($list[$weddings]['parent']);

        // admin: create inside a parent, rename keeps the parent
        $csrf = $site->admin()->get('/admin.php?module=media')->csrf();
        $site->admin()->post('/admin.php?module=media&action=folder', ['_csrf' => $csrf, 'name' => 'Church', 'parent' => $weddings]);
        $church = (string) $site->value('SELECT public_id FROM tl_media_folders WHERE name = ?', ['Church']);
        $this->assertSame($weddings, $this->parentOf($church), 'admin: a folder is created inside its parent');
        $site->admin()->post('/admin.php?module=media&action=folder', ['_csrf' => $csrf, 'folder_id' => $church, 'name' => 'Chapel']);
        $this->assertSame($weddings, $this->parentOf($church), 'admin: renaming keeps the parent');

        // move over MCP and admin
        $site->mcpResult('move_media_folder', ['id' => $church, 'parent' => $castle]);
        $this->assertSame($castle, $this->parentOf($church), 'MCP: move_media_folder');
        $site->admin()->post('/admin.php?module=media&action=folder_move', ['_csrf' => $csrf, 'folder_id' => $church, 'parent' => '']);
        $this->assertNull($this->parentOf($church), 'admin: an empty parent moves the folder to the top level');
        $site->admin()->post('/admin.php?module=media&action=folder_move', ['_csrf' => $csrf, 'folder_id' => $church, 'parent' => $details]);
        $this->assertSame($details, $this->parentOf($church), 'admin: folder_move');

        // cycles are refused
        $text = $site->mcpText('move_media_folder', ['id' => $weddings, 'parent' => $details]);
        $this->assertNull($this->parentOf($weddings), 'MCP: a cycle is refused (' . $text . ')');
        $site->admin()->post('/admin.php?module=media&action=folder_move', ['_csrf' => $csrf, 'folder_id' => $weddings, 'parent' => $church]);
        $this->assertNull($this->parentOf($weddings), 'admin: a cycle is refused');
        $site->mcpText('move_media_folder', ['id' => $castle, 'parent' => $castle]);
        $this->assertSame($weddings, $this->parentOf($castle), 'MCP: a folder cannot be its own parent');

        // deleting a folder lifts its files and subfolders one level
        $this->mcpText('upload_file', ['filename' => 'tree-delete-f17.png', 'data' => $this->pngBase64(), 'folder' => $castle]);
        $media = (int) $site->value("SELECT media_id FROM tl_media WHERE image_path LIKE '%tree-delete-f17%' ORDER BY media_id DESC LIMIT 1");
        $this->assertSame($site->internalId('media_folders', $castle), (int) $site->value('SELECT folder_id FROM tl_media WHERE media_id = ?', [$media]));
        $site->admin()->post('/admin.php?module=media&action=folder_delete', ['_csrf' => $csrf, 'folder_id' => $castle]);
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_media_folders WHERE public_id = ?', [$castle]), 'the folder is gone');
        $this->assertSame($site->internalId('media_folders', $weddings), (int) $site->value('SELECT folder_id FROM tl_media WHERE media_id = ?', [$media]), 'its file moved up to the parent');
        $this->assertSame($weddings, $this->parentOf($details), 'its subfolder moved up to the parent');

        // MCP delete does the same; a top-level folder's content goes to no folder
        $site->mcpResult('delete_media_folder', ['id' => $weddings]);
        $this->assertNull($this->parentOf($details), 'MCP delete: the subfolder reaches the top level');
        $this->assertNull($site->value('SELECT folder_id FROM tl_media WHERE media_id = ?', [$media]), 'MCP delete: the file is in no folder');
    }

    public function testSavingAFolderMovesBeforeItRenames(): void
    {
        $site = $this->site();
        $a = (string) $site->mcpResult('save_media_folder', ['name' => 'Move A'])['id'];
        $b = (string) $site->mcpResult('save_media_folder', ['name' => 'Move B', 'parent' => $a])['id'];
        $nameOf = fn (string $id): string => (string) $site->value('SELECT name FROM tl_media_folders WHERE public_id = ?', [$id]);

        $site->mcpResult('save_media_folder', ['id' => $b, 'name' => 'Move B2', 'parent' => null]);
        $this->assertSame([$a, 'Move B2'], [$this->parentOf($b), $nameOf($b)], 'a null parent leaves the folder where it is');
        $site->mcpResult('save_media_folder', ['id' => $b, 'name' => 'Move B3']);
        $this->assertSame([$a, 'Move B3'], [$this->parentOf($b), $nameOf($b)], 'no parent at all leaves it too');

        $site->mcpText('save_media_folder', ['id' => $a, 'name' => 'Move A2', 'parent' => $b]);
        $this->assertSame(['Move A', null], [$nameOf($a), $this->parentOf($a)], 'a refused move (a cycle) leaves the name unchanged');

        $site->mcpResult('save_media_folder', ['id' => $b, 'name' => 'Move B4', 'parent' => '']);
        $this->assertSame([null, 'Move B4'], [$this->parentOf($b), $nameOf($b)], 'an empty parent moves the folder to the top level and renames it');
    }

    public function testMovingAFolderIsForEditorsNotNewsAuthors(): void
    {
        $site = $this->site();
        $folder = (string) $site->mcpResult('save_media_folder', ['name' => 'Editors only'])['id'];
        $target = (string) $site->mcpResult('save_media_folder', ['name' => 'Editors target'])['id'];
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Folder Author', 'username' => 'folderauthor', 'password' => $site->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $author = $site->client('folderauthor');
        $site->signIn($author, 'folderauthor');
        $csrf = $site->csrf($author, '/admin.php?module=media');

        $author->post('/admin.php?module=media&action=folder_move', ['_csrf' => $csrf, 'folder_id' => $folder, 'parent' => $target]);
        $this->assertNull($this->parentOf($folder), 'a news author cannot move a folder');
        $author->post('/admin.php?module=media&action=folder', ['_csrf' => $csrf, 'folder_id' => $folder, 'name' => 'Renamed by author']);
        $this->assertSame('Renamed by author', $site->value('SELECT name FROM tl_media_folders WHERE public_id = ?', [$folder]), 'renaming stays open');
    }
}
