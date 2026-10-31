<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use PHPUnit\Framework\Attributes\Group;
use Talea\Core\Uuid;
use Talea\Tests\Site\Support\SiteTestCase;

/** The Media screen (docs/specs/media-library.md): the levels, the left menu, the filters, the table view and Show more. */
#[Group('site')]
final class MediaLibraryScreenTest extends SiteTestCase
{
    use McpHelpers;

    /** @var array<string, string> the tile or row of a file by label */
    private static array $files = [];

    /** Uploads a PNG over MCP (into $folder) and returns its public id; the basename is kept in self::$files. */
    private function image(string $label, ?string $folder = null, string $name = ''): string
    {
        $answer = $this->site()->mcpResult('upload_file', ['filename' => $label . '.png', 'data' => $this->pngBase64()] + ($folder !== null ? ['folder' => $folder] : []));
        $id = (string) $answer['id'];
        $this->site()->exec('UPDATE tl_media SET name = ? WHERE public_id = ?', [$name, $id]);
        self::$files[$label] = 'id="m-' . $id . '"';

        return $id;
    }

    private function page(string $query = ''): string
    {
        return $this->assertPage('/admin.php?module=media' . $query, 200)->body;
    }

    public function testLevelsMenuFiltersAndShowMore(): void
    {
        $site = $this->site();
        $weddings = (string) $site->mcpResult('save_media_folder', ['name' => 'Weddings F18'])['id'];
        $castle = (string) $site->mcpResult('save_media_folder', ['name' => 'Castle F18', 'parent' => $weddings])['id'];
        $portraits = (string) $site->mcpResult('save_media_folder', ['name' => 'Portraits F18'])['id'];
        $this->image('top-named', null, 'A named top file');
        $this->image('top-blank');
        $this->image('in-weddings', $weddings, 'In weddings');
        $this->image('in-castle', $castle);
        $doc = $this->image('a-document');
        $site->exec("UPDATE tl_media SET image_path = REPLACE(image_path, '.png', '.pdf'), thumb_path = '' WHERE public_id = ?", [$doc]);
        $f = self::$files;

        // the top level: top-level folder cards and the files that are in no folder
        $root = $this->page();
        $this->assertStringContainsString('Weddings F18', $root);
        $this->assertStringContainsString('Portraits F18', $root);
        $this->assertStringContainsString('id="folder-menu-' . $weddings . '"', $root, 'a folder card has its menu');
        $this->assertStringNotContainsString('id="folder-menu-' . $castle . '"', $root, 'a subfolder is not a card of the top level');
        $this->assertStringContainsString('Not in a folder', $root);
        $this->assertStringContainsString($f['top-named'], $root);
        $this->assertStringNotContainsString($f['in-weddings'], $root, 'a file in a folder is not on the top level');
        $this->assertStringContainsString('1 folder · 1 file', $root, 'the Weddings card counts its direct folders and files');

        // inside a folder: the breadcrumb, its subfolder cards, its own files only
        $inside = $this->page('&folder=' . $weddings);
        $this->assertStringContainsString('Folders in Weddings F18', $inside);
        $this->assertStringContainsString('Files in Weddings F18', $inside);
        $this->assertStringContainsString('id="folder-menu-' . $castle . '"', $inside);
        $this->assertMatchesRegularExpression('#/admin/media" data-media-drop data-id="" data-name="All media">All media</a>#', $inside, 'the breadcrumb links back to All media (and takes a drop)');
        $this->assertMatchesRegularExpression('#<span aria-current="page"><bdi>Weddings F18</bdi></span>#', $inside, 'the last part of the breadcrumb is not a link');
        $this->assertStringContainsString($f['in-weddings'], $inside);
        $this->assertStringNotContainsString($f['in-castle'], $inside, 'files of a subfolder are not counted into the level');
        $this->assertStringNotContainsString($f['top-named'], $inside);

        // the left menu: the tree opens the branch of the current folder and marks the current one
        $this->assertMatchesRegularExpression('#<a class="media-rail-link" href="[^"]*folder=' . $weddings . '"[^>]*aria-current="page">#', $inside, 'the rail marks the current folder');
        $this->assertStringContainsString('<ul class="media-tree-sub"><li>', $inside, 'the branch of the current folder is open');
        $this->assertStringContainsString('<ul class="media-tree-sub closed">', $root, 'on the top level a folder with subfolders is closed');

        // Move: the folder and everything below it cannot be chosen
        $this->assertMatchesRegularExpression('#<option value="' . $weddings . '" disabled>Weddings F18</option>#', $root);
        $this->assertMatchesRegularExpression('#<option value="' . $castle . '" disabled>\x{00A0}\x{00A0}Castle F18</option>#u', $root, 'the folder below is disabled and indented');
        $this->assertMatchesRegularExpression('#<option value="' . $portraits . '">Portraits F18</option>#', $root, 'an unrelated folder can be chosen');

        // Missing description: only images without a name, in the table view
        $missing = $this->page('&missing=1');
        $this->assertStringContainsString('media-table', $missing);
        $this->assertStringContainsString($f['top-blank'], $missing);
        $this->assertStringContainsString($f['in-castle'], $missing, 'it looks over the whole library');
        $this->assertStringNotContainsString($f['top-named'], $missing);
        $this->assertStringNotContainsString($f['a-document'], $missing, 'a document has no description to miss');
        $this->assertStringNotContainsString('class="media-grid"', $this->page('&missing=1&view=table'));
        $this->assertStringContainsString('class="media-grid"', $this->page('&missing=1&view=grid'), 'the grid can be chosen');

        // type chips
        $documents = $this->page('&type=document');
        $this->assertStringContainsString($f['a-document'], $documents);
        $this->assertStringNotContainsString($f['top-named'], $documents);
        $this->assertStringContainsString('Weddings F18', $documents, 'a type filters the files and keeps the folders');
        $images = $this->page('&type=image');
        $this->assertStringContainsString($f['top-named'], $images);
        $this->assertStringNotContainsString($f['a-document'], $images);
        $this->assertStringNotContainsString($f['a-document'], $this->page('&type=video'));

        // cross-cutting views show only files
        $recent = $this->page('&recent=1');
        $this->assertStringContainsString($f['in-castle'], $recent);
        $this->assertStringNotContainsString('class="media-folder-grid"', $recent);
        $this->assertStringContainsString($f['in-weddings'], $this->page('&search=' . urlencode('In weddings')));

        // Show more: 40 at first, 40 more per click
        $seed = (string) $site->value('SELECT public_id FROM tl_media ORDER BY media_id LIMIT 1');
        for ($i = 0; $i < 45; $i++) {
            $site->exec('INSERT INTO tl_media (public_id, owner_id, folder_id, image_path, image_width, image_height, image_size, thumb_path, thumb_width, thumb_height, created_at)
                SELECT ?, owner_id, NULL, image_path, image_width, image_height, image_size, thumb_path, thumb_width, thumb_height, created_at FROM tl_media WHERE public_id = ?', [Uuid::v4(), $seed]);
        }
        $first = $this->page();
        $this->assertSame(40, substr_count($first, 'class="media-tile"'), 'the first screen shows 40 files');
        $this->assertMatchesRegularExpression('#Showing 40 of \d+#', $first);
        $this->assertStringContainsString('show=80', $first, 'Show more raises show by 40');
        $this->assertGreaterThan(40, substr_count($this->page('&show=80'), 'class="media-tile"'));
        $this->assertSame(1, substr_count($this->page('&show=5'), 'Showing 40 of'), 'show below 40 is raised to 40');
    }

    public function testDetailsPanel(): void
    {
        $site = $this->site();
        $target = (string) $site->mcpResult('save_media_folder', ['name' => 'Panel target'])['id'];
        $a = $this->image('panel-a', null, 'Panel A');
        $b = $this->image('panel-b', null, 'Panel B');
        $c = $this->image('panel-c', null, 'Panel C');
        $doc = $this->image('panel-doc');
        $site->exec("UPDATE tl_media SET image_path = REPLACE(image_path, '.png', '.pdf'), thumb_path = '' WHERE public_id = ?", [$doc]);
        $ids = fn (string $pid): int => $site->internalId('media', $pid);
        $this->assertStringNotContainsString('class="media-panel"', $this->page(), 'no panel without item');

        // the panel, with the neighbours in the list order (newest first: C, B, A among these) and the list kept in the links
        $panel = $this->page('&item=' . $b . '&sort=newest&type=image');
        $this->assertStringContainsString('class="media-panel"', $panel);
        $this->assertStringContainsString('data-focal', $panel, 'an image has the focal point');
        $this->assertStringContainsString('name="focus_x"', $panel);
        $this->assertMatchesRegularExpression('#href="[^"]*item=' . $c . '[^"]*type=image[^"]*" aria-label="Previous file"|href="[^"]*type=image[^"]*item=' . $c . '[^"]*" aria-label="Previous file"#', $panel, 'Previous is the file before it in the list, with the list parameters');
        $this->assertStringContainsString('aria-label="Close details"', $panel);
        $this->assertStringContainsString('download', $panel);
        $this->assertStringContainsString('data-copy="#media-address"', $panel);
        $this->assertStringContainsString('Not used anywhere.', $panel);
        $this->assertDoesNotMatchRegularExpression('#form="media-delete"[^>]*disabled#', $panel, 'an unused file can be deleted');
        $this->assertStringContainsString('popovertarget="media-replace"', $panel, 'Replace file for a PNG');

        // the first file of the list has no Previous
        $this->assertStringNotContainsString('aria-label="Previous file"', $this->page('&item=' . $this->site()->publicId('media', (int) $site->value('SELECT MAX(media_id) FROM tl_media')) . '&sort=newest'));

        // a used file: Delete is disabled and says why
        $path = (string) $site->value('SELECT image_path FROM tl_media WHERE public_id = ?', [$a]);
        $site->exec('UPDATE tl_pages SET text = ? WHERE page_id = ?', ['<p><img src="/' . $path . '"></p>', (int) $site->value('SELECT MIN(page_id) FROM tl_pages')]);
        $used = $this->page('&item=' . $a);
        $this->assertStringContainsString('Used in 1 place', $used);
        $this->assertMatchesRegularExpression('#form="media-delete"[^>]*disabled#', $used);
        $this->assertStringContainsString('The file is used in 1 place. Remove it there first to delete it.', $used);

        // an attachment: an extension block instead of the preview, no focal point
        $attachment = $this->page('&item=' . $doc);
        $this->assertStringNotContainsString('data-focal', $attachment);
        $this->assertStringContainsString('media-panel-preview file', $attachment);
        $this->assertStringNotContainsString('popovertarget="media-replace"', $attachment);

        // a file waiting for approval: the status and Publish file instead of Replace
        $site->exec('UPDATE tl_media SET staged_at = ? WHERE public_id = ?', [date('Y-m-d H:i:s'), $c]);
        $waiting = $this->page('&item=' . $c);
        $this->assertStringContainsString('media-flag-waiting', $waiting);
        $this->assertStringContainsString('form="media-approve"', $waiting);
        $this->assertStringNotContainsString('popovertarget="media-replace"', $waiting);
        $site->exec('UPDATE tl_media SET staged_at = NULL WHERE public_id = ?', [$c]);

        // Save: fields, the folder, and the redirect keeps the item and the list
        $csrf = $site->admin()->get('/admin.php?module=media')->csrf();
        $answer = $site->admin()->post('/admin.php?module=media&action=save', ['_csrf' => $csrf, 'media_id' => $b, 'name' => 'Renamed B', 'description' => 'Caption B', 'author' => 'Me', 'folder_id' => $target, 'focus_x' => 20, 'focus_y' => 70, 'list' => 'sort=size&type=image']);
        $this->assertStringContainsString('item=' . $b, $answer->redirect);
        $this->assertStringContainsString('sort=size', $answer->redirect);
        $this->assertSame('Renamed B|Caption B|Me|20% 70%|' . $site->internalId('media_folders', $target), implode('|', [
            $site->value('SELECT name FROM tl_media WHERE media_id = ?', [$ids($b)]), $site->value('SELECT description FROM tl_media WHERE media_id = ?', [$ids($b)]),
            $site->value('SELECT author FROM tl_media WHERE media_id = ?', [$ids($b)]), $site->value('SELECT focal_point FROM tl_media WHERE media_id = ?', [$ids($b)]),
            $site->value('SELECT folder_id FROM tl_media WHERE media_id = ?', [$ids($b)]),
        ]), 'the panel saves the fields and moves the file');
        $site->admin()->post('/admin.php?module=media&action=save', ['_csrf' => $csrf, 'media_id' => $b, 'name' => 'Renamed B', 'folder_id' => '']);
        $this->assertNull($site->value('SELECT folder_id FROM tl_media WHERE media_id = ?', [$ids($b)]), 'an empty folder takes the file out of its folder');

        // Delete of one file goes through the bulk action
        $site->admin()->post('/admin.php?module=media&action=bulk', ['_csrf' => $csrf, 'bulk' => 'delete', 'selected' => [$b]]);
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_media WHERE public_id = ?', [$b]));

        // Rename of a folder card returns to the level it came from
        $answer = $site->admin()->post('/admin.php?module=media&action=folder', ['_csrf' => $csrf, 'folder_id' => $target, 'name' => 'Panel target 2', 'level' => '']);
        $this->assertStringNotContainsString('folder=', $answer->redirect, 'the top level, not the renamed folder');
    }
}
