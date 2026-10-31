<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** Media phase 3 (docs/specs/media-library.md): the duplicate check of an upload, moving by drag and drop (JSON) and Undo. */
#[Group('site')]
final class MediaUploadDragTest extends SiteTestCase
{
    use McpHelpers;

    /** A PNG of one colour on disk (its bytes differ per colour). */
    private function pngFile(string $name, int $red): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The PHP used by the test has no GD.');
        }
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, 79, 46));
        $path = $this->site()->workDir('files') . '/' . $name;
        imagepng($image, $path);

        return $path;
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $json = json_decode($body, true);
        $this->assertIsArray($json, $body);

        return $json;
    }

    private function csrf(?\Talea\Tests\Site\Support\Http $as = null): string
    {
        return $this->site()->csrf($as, '/admin.php?module=media');
    }

    private function folder(string $name, ?string $parent = null): string
    {
        return (string) $this->site()->mcpResult('save_media_folder', ['name' => $name] + ($parent !== null ? ['parent' => $parent] : []))['id'];
    }

    private function folderOf(string $mediaId): mixed
    {
        return $this->site()->value('SELECT folder_id FROM tl_media WHERE public_id = ?', [$mediaId]);
    }

    public function testUploadSkipsAFileThatIsAlreadyInMedia(): void
    {
        $site = $this->site();
        $file = $this->pngFile('same-u1.png', 200);
        $first = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => $file])->body);
        $this->assertCount(1, $first['images']);
        $this->assertSame([], $first['duplicates']);
        $id = $first['images'][0]['id'];
        $this->assertNotEmpty($site->value('SELECT source_sha1 FROM tl_media WHERE public_id = ?', [$id]), 'the hash of the uploaded bytes is kept');

        $again = $site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => [$file, 'image/png', 'renamed-copy.png']]);
        $this->assertSame(200, $again->status);
        $second = $this->json($again->body);
        $this->assertSame([], $second['images'], 'nothing is stored twice, whatever the file is called');
        $this->assertSame($id, $second['duplicates'][0]['id']);
        $this->assertSame('renamed-copy.png', $second['duplicates'][0]['file']);
        $this->assertSame(basename((string) $first['images'][0]['url']), $second['duplicates'][0]['name'], 'a file without a description is named by its file name');
        $this->assertSame($id, $second['duplicates'][0]['image']['id'], 'the editor picker gets the existing file to insert');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_media WHERE source_sha1 = ?', [$site->value('SELECT source_sha1 FROM tl_media WHERE public_id = ?', [$id])]));

        // a described file is named by its description; the form (no JavaScript) skips it with a message
        $site->exec('UPDATE tl_media SET name = ? WHERE public_id = ?', ['Red square', $id]);
        $form = $site->admin()->upload('/admin.php?module=media&action=upload', ['_csrf' => $this->csrf()], ['files[]' => $file]);
        $this->assertSame(302, $form->status);
        $this->assertStringContainsString('already in Media as Red square', $this->assertPage('/admin.php?module=media', 200)->body);

        // another picture is uploaded as usual; a row without a hash (uploaded before the check) never matches
        $other = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => $this->pngFile('same-u2.png', 20)])->body);
        $this->assertCount(1, $other['images']);
    }

    public function testDragMovesFilesAndFoldersAndUndoPutsThemBackOnce(): void
    {
        $site = $this->site();
        $parent = $this->folder('Drag parent');
        $child = $this->folder('Drag child', $parent);
        $target = $this->folder('Castle wedding');
        $a = (string) $site->mcpResult('upload_file', ['filename' => 'drag-a.png', 'data' => $this->pngBase64(), 'folder' => $parent])['id'];
        $b = (string) $site->mcpResult('upload_file', ['filename' => 'drag-b.png', 'data' => $this->pngBase64()])['id'];
        $csrf = $this->csrf();

        // files: JSON answer, rows moved
        $moved = $site->admin()->post('/admin.php?module=media&action=bulk&format=json', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $target, 'selected' => [$a, $b]]);
        $this->assertSame(200, $moved->status);
        $this->assertSame(['ok' => true, 'moved' => 2, 'message' => '2 files moved to Castle wedding.'], $this->json($moved->body));
        $targetId = $site->internalId('media_folders', $target);
        $this->assertEquals($targetId, $this->folderOf($a));
        $this->assertEquals($targetId, $this->folderOf($b));

        // the reloaded list shows the notice once, with Undo
        $list = $this->assertPage('/admin.php?module=media', 200, ['2 files moved to Castle wedding.', 'action="/admin/media/undo_move"'])->body;
        $this->assertStringContainsString('>Undo</button>', $list);
        $this->assertStringNotContainsString('files moved to Castle wedding.', $this->assertPage('/admin.php?module=media')->body, 'the notice is shown once');

        // Undo: each file goes back to where it was (one into a folder, one to the top level), and the second try changes nothing
        $site->admin()->post('/admin.php?module=media&action=undo_move', ['_csrf' => $csrf]);
        $this->assertEquals($site->internalId('media_folders', $parent), $this->folderOf($a));
        $this->assertNull($this->folderOf($b));
        $site->exec('UPDATE tl_media SET folder_id = ? WHERE public_id = ?', [$targetId, $b]);
        $site->admin()->post('/admin.php?module=media&action=undo_move', ['_csrf' => $csrf]);
        $this->assertEquals($targetId, $this->folderOf($b), 'Undo works once');

        // folders: a folder cannot go into its own subfolder, a refused move offers no Undo
        $author = $site->client('dragauthor');
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Drag Author', 'username' => 'dragauthor', 'password' => $site->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $site->signIn($author, 'dragauthor');
        $refused = $author->post('/admin.php?module=media&action=folder_move&format=json', ['_csrf' => $this->csrf($author), 'folder_id' => $child, 'parent' => $target]);
        $this->assertSame(403, $refused->status, 'a news author cannot move a folder');
        $this->assertEquals($site->internalId('media_folders', $parent), $site->value('SELECT parent_id FROM tl_media_folders WHERE public_id = ?', [$child]));

        $cycle = $site->admin()->post('/admin.php?module=media&action=folder_move&format=json', ['_csrf' => $csrf, 'folder_id' => $parent, 'parent' => $child]);
        $this->assertSame(409, $cycle->status);
        $this->assertFalse($this->json($cycle->body)['ok']);
        $this->assertEquals($site->internalId('media_folders', $parent), $site->value('SELECT parent_id FROM tl_media_folders WHERE public_id = ?', [$child]));
        $this->assertStringNotContainsString('undo_move', $this->assertPage('/admin.php?module=media')->body);

        // a folder dropped on another one: moved, Undo restores its old parent
        $folderMove = $site->admin()->post('/admin.php?module=media&action=folder_move&format=json', ['_csrf' => $csrf, 'folder_id' => $child, 'parent' => $target]);
        $this->assertSame(['ok' => true, 'message' => 'Folder moved to Castle wedding.'], $this->json($folderMove->body));
        $this->assertEquals($targetId, $site->value('SELECT parent_id FROM tl_media_folders WHERE public_id = ?', [$child]));
        $this->assertPage('/admin.php?module=media', 200, 'Folder moved to Castle wedding.');
        $site->admin()->post('/admin.php?module=media&action=undo_move', ['_csrf' => $csrf]);
        $this->assertEquals($site->internalId('media_folders', $parent), $site->value('SELECT parent_id FROM tl_media_folders WHERE public_id = ?', [$child]));

        // the keyboard path (the selection bar) gets the same notice
        $site->admin()->post('/admin.php?module=media&action=bulk', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $target, 'selected' => [$a]]);
        $this->assertPage('/admin.php?module=media', 200, ['1 file moved to Castle wedding.', 'undo_move']);
    }

    public function testUndoDoesNotTouchFilesThePersonMayNotEditAnyMore(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Media Author', 'username' => 'mediaauthor', 'password' => $site->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $author = $site->client('mediaauthor');
        $site->signIn($author, 'mediaauthor');
        $csrf = $this->csrf($author);
        $folder = $this->folder('Author target');
        $uploaded = $this->json($author->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $csrf], ['files[]' => $this->pngFile('author-u.png', 90)])->body);
        $this->assertCount(1, $uploaded['images'], 'a news author uploads');
        $id = $uploaded['images'][0]['id'];

        $moved = $this->json($author->post('/admin.php?module=media&action=bulk&format=json', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $folder, 'selected' => [$id]])->body);
        $this->assertSame(1, $moved['moved']);

        // the file now belongs to somebody else: the author's Undo leaves it where it is
        $site->exec('UPDATE tl_media SET owner_id = (SELECT MIN(user_id) FROM tl_users WHERE username = ?) WHERE public_id = ?', ['admin', $id]);
        $author->post('/admin.php?module=media&action=undo_move', ['_csrf' => $csrf]);
        $this->assertEquals($site->internalId('media_folders', $folder), $this->folderOf($id));

        // and a file the author does not own is not moved by a drag either
        $none = $author->post('/admin.php?module=media&action=bulk&format=json', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => '', 'selected' => [$id]]);
        $this->assertSame(400, $none->status);
        $this->assertEquals($site->internalId('media_folders', $folder), $this->folderOf($id));
    }

    public function testAnAttachmentIsRecognisedAsADuplicateToo(): void
    {
        $site = $this->site();
        $pdf = $site->workDir('files') . '/same-doc.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $first = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => $pdf])->body);
        $this->assertCount(1, $first['images'], 'a PDF is uploaded');
        $id = $first['images'][0]['id'];
        $this->assertNotEmpty($site->value('SELECT source_sha1 FROM tl_media WHERE public_id = ?', [$id]), 'the hash of an attachment is taken before the file is moved');

        $again = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => [$pdf, 'application/pdf', 'copy.pdf']])->body);
        $this->assertSame([], $again['images']);
        $this->assertSame($id, $again['duplicates'][0]['id'], 'the same PDF is a duplicate');

        // a copy that waits for approval is not "already in Media": the upload is stored
        $site->exec('UPDATE tl_media SET staged_at = NOW() WHERE public_id = ?', [$id]);
        $third = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => [$pdf, 'application/pdf', 'third.pdf']])->body);
        $this->assertCount(1, $third['images'], 'a file waiting for approval does not count as a duplicate');
    }

    public function testReplacingAFileUpdatesItsHash(): void
    {
        $site = $this->site();
        $old = $this->pngFile('rep-old.png', 120);
        $new = $this->pngFile('rep-new.png', 130);
        $id = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => $old])->body)['images'][0]['id'];
        $site->admin()->upload('/admin.php?module=media&action=replace', ['_csrf' => $this->csrf(), 'media_id' => $id], ['file' => $new]);
        $this->assertSame(sha1_file($new), $site->value('SELECT source_sha1 FROM tl_media WHERE public_id = ?', [$id]), 'Replace keeps the hash of the file that is in Media now');
        $again = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => $new])->body);
        $this->assertSame($id, $again['duplicates'][0]['id'], 'the replacement counts as a duplicate');
        $fresh = $this->json($site->admin()->upload('/admin.php?module=media&action=upload&format=json', ['_csrf' => $this->csrf()], ['files[]' => $old])->body);
        $this->assertCount(1, $fresh['images'], 'the file that was replaced no longer counts');
    }

    public function testUndoLeavesWhatMovedAgainAndAnEmptyMoveSaysSo(): void
    {
        $site = $this->site();
        $one = $this->folder('Undo one');
        $two = $this->folder('Undo two');
        $three = $this->folder('Undo three');
        $a = (string) $site->mcpResult('upload_file', ['filename' => 'undo-a.png', 'data' => $this->pngBase64()])['id'];
        $b = (string) $site->mcpResult('upload_file', ['filename' => 'undo-b.png', 'data' => $this->pngBase64()])['id'];
        $csrf = $this->csrf();
        $site->admin()->post('/admin.php?module=media&action=bulk&format=json', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $one, 'selected' => [$a, $b]]);
        $site->exec('UPDATE tl_media SET folder_id = ? WHERE public_id = ?', [$site->internalId('media_folders', $three), $b]); // b was moved again, somewhere else
        $site->admin()->post('/admin.php?module=media&action=undo_move', ['_csrf' => $csrf]);
        $this->assertNull($this->folderOf($a), 'a is back at the top level');
        $this->assertEquals($site->internalId('media_folders', $three), $this->folderOf($b), 'b moved again since: Undo does not overwrite that');

        // a folder
        $site->admin()->post('/admin.php?module=media&action=folder_move&format=json', ['_csrf' => $csrf, 'folder_id' => $two, 'parent' => $one]);
        $site->exec('UPDATE tl_media_folders SET parent_id = ? WHERE public_id = ?', [$site->internalId('media_folders', $three), $two]);
        $site->admin()->post('/admin.php?module=media&action=undo_move', ['_csrf' => $csrf]);
        $this->assertEquals($site->internalId('media_folders', $three), $site->value('SELECT parent_id FROM tl_media_folders WHERE public_id = ?', [$two]), 'a folder that moved again stays where it is');

        // nothing moved at all: said, not silent
        $none = $site->admin()->post('/admin.php?module=media&action=bulk&format=json', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $one, 'selected' => ['00000000-0000-4000-8000-000000000000']]);
        $this->assertSame(400, $none->status);
        $this->assertSame('Nothing was moved.', $this->json($none->body)['message']);
        $site->admin()->post('/admin.php?module=media&action=bulk', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $one, 'selected' => ['00000000-0000-4000-8000-000000000000']]);
        $this->assertPage('/admin.php?module=media', 200, 'Nothing was moved.');
    }

    public function testATargetFolderThatDoesNotExistIsRefused(): void
    {
        $site = $this->site();
        $folder = $this->folder('Real target');
        $a = (string) $site->mcpResult('upload_file', ['filename' => 'ghost-a.png', 'data' => $this->pngBase64(), 'folder' => $folder])['id'];
        $csrf = $this->csrf();
        $ghost = '00000000-0000-4000-8000-000000000001';

        $bulk = $site->admin()->post('/admin.php?module=media&action=bulk&format=json', ['_csrf' => $csrf, 'bulk' => 'move', 'to_folder' => $ghost, 'selected' => [$a]]);
        $this->assertSame(404, $bulk->status);
        $this->assertEquals($site->internalId('media_folders', $folder), $this->folderOf($a), 'a file is not moved to the top level by a stale target');
        $move = $site->admin()->post('/admin.php?module=media&action=folder_move&format=json', ['_csrf' => $csrf, 'folder_id' => $folder, 'parent' => $ghost]);
        $this->assertSame(404, $move->status);
        $site->admin()->post('/admin.php?module=media&action=save', ['_csrf' => $csrf, 'media_id' => $a, 'name' => 'Ghost', 'folder_id' => $ghost]);
        $this->assertEquals($site->internalId('media_folders', $folder), $this->folderOf($a), 'the details panel does not move a file to a folder that is gone');
        $this->assertPage('/admin.php?module=media', 200, 'The target folder does not exist.');
    }
}
