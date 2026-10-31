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
        $this->assertStringContainsString('/admin/media">All media</a>', $inside, 'the breadcrumb links back to All media');
        $this->assertMatchesRegularExpression('#<span aria-current="page"><bdi>Weddings F18</bdi></span>#', $inside, 'the last part of the breadcrumb is not a link');
        $this->assertStringContainsString($f['in-weddings'], $inside);
        $this->assertStringNotContainsString($f['in-castle'], $inside, 'files of a subfolder are not counted into the level');
        $this->assertStringNotContainsString($f['top-named'], $inside);

        // the left menu: the tree opens the branch of the current folder and marks the current one
        $this->assertMatchesRegularExpression('#<a class="rail-link" href="[^"]*folder=' . $weddings . '" aria-current="page">#', $inside, 'the rail marks the current folder');
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
        $this->assertStringNotContainsString('class="folder-grid"', $recent);
        $this->assertStringContainsString($f['in-weddings'], $this->page('&search=' . urlencode('In weddings')));

        // Show more: 40 at first, 40 more per click
        $seed = (string) $site->value('SELECT public_id FROM tl_media ORDER BY media_id LIMIT 1');
        for ($i = 0; $i < 45; $i++) {
            $site->exec('INSERT INTO tl_media (public_id, owner_id, folder_id, image_path, image_width, image_height, image_size, thumb_path, thumb_width, thumb_height, created_at)
                SELECT ?, owner_id, NULL, image_path, image_width, image_height, image_size, thumb_path, thumb_width, thumb_height, created_at FROM tl_media WHERE public_id = ?', [Uuid::v4(), $seed]);
        }
        $first = $this->page();
        $this->assertSame(40, substr_count($first, 'class="tile"'), 'the first screen shows 40 files');
        $this->assertMatchesRegularExpression('#Showing 40 of \d+#', $first);
        $this->assertStringContainsString('show=80', $first, 'Show more raises show by 40');
        $this->assertGreaterThan(40, substr_count($this->page('&show=80'), 'class="tile"'));
        $this->assertSame(1, substr_count($this->page('&show=5'), 'Showing 40 of'), 'show below 40 is raised to 40');
    }
}
