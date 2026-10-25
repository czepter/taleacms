<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Files from a drafts-only connection wait for approval (#150, Core\MediaStaging): stored outside the web root, shown to signed-in
 * users and draft previews, public once published content uses them or a person approves them, deleted after 30 days. The tests
 * run in order.
 */
#[Group('site')]
final class WaitingFilesTest extends SiteTestCase
{
    use AgentHelpers;

    private static string $path = '';
    private static string $page = '';

    /** A drafts-only upload of a small PNG; returns the tool's answer. @return array<string, mixed> */
    private function uploadAsDrafts(string $filename): array
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The PHP used by the test has no GD.');
        }
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 46, 79, 255));
        ob_start();
        imagepng($image);

        return $this->mcpData('upload_file', ['filename' => $filename, 'data' => base64_encode((string) ob_get_clean())], $this->draftsToken());
    }

    private function waiting(string $path): string
    {
        return $this->sq('SELECT COUNT(*) FROM tl_media WHERE image_path = ? AND staged_at IS NOT NULL', [$path]);
    }

    public function testADraftsOnlyUploadWaitsOutsideTheWebRoot(): void
    {
        $answer = $this->uploadAsDrafts('waiting-photo.png');
        self::$path = (string) ($answer['path'] ?? '');
        $this->assertMatchesRegularExpression('#^media/\d{4}/\d{2}/waiting-photo-[a-f0-9]{6}\.png$#', self::$path, 'the answer has the public path the file will have');
        $this->assertTrue(($answer['waiting_for_approval'] ?? false) === true && str_contains((string) ($answer['note'] ?? ''), 'waits for approval'), 'the answer says the file waits for approval');
        $this->assertFileDoesNotExist($this->site()->path(self::$path), 'nothing in media/');
        $this->assertFileExists($this->site()->path('storage/staging/' . self::$path), 'the file waits under storage/staging/');
        $this->assertSame('1', $this->waiting(self::$path), 'the media row is marked waiting');

        $this->assertSame(404, $this->site()->client('visitor')->get('/' . self::$path)->status, 'a visitor cannot load it');
        $this->assertSame(403, $this->site()->client('visitor')->get('/storage/staging/' . self::$path)->status, 'nor from the staging folder');
        $own = $this->site()->admin()->get('/' . self::$path);
        $this->assertSame(200, $own->status, 'a signed-in user sees it');
        $this->assertSame('image/png', $own->headers['content-type'] ?? '');
        $this->assertStringContainsString('no-store', $own->headers['cache-control'] ?? '', 'privately, never in a shared cache');

        $full = $this->site()->mcpResult('upload_file', ['filename' => 'public-photo.png', 'data' => base64_encode((string) file_get_contents($this->site()->path('storage/staging/' . self::$path)))]);
        $public = is_array($full) ? (string) ($full['path'] ?? '') : '';
        $this->assertFileExists($this->site()->path($public), 'a connection with full access uploads straight to media/');
        $this->assertSame('0', $this->waiting($public), 'and its file does not wait');
        $this->assertArrayNotHasKey('waiting_for_approval', is_array($full) ? $full : [], 'nor does its answer say so');
    }

    public function testADraftPreviewShowsTheWaitingFile(): void
    {
        $token = $this->draftsToken();
        $created = $this->mcpText('create_page', ['title' => 'Waiting gallery'], $token);
        $this->assertSame(1, preg_match('/"id":"([0-9a-f-]{36})"/', $created, $m), 'a drafts-only connection creates a hidden page: ' . mb_substr($created, 0, 200));
        self::$page = $m[1];
        $this->mcpText('save_build', ['id' => self::$page, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'image', 'content' => ['src' => self::$path, 'alt' => '[Photo]']]]]]]], $token);
        $this->assertStringContainsString(self::$path, $this->sq('SELECT build_draft FROM tl_pages WHERE public_id = ?', [self::$page]), 'the draft uses the waiting file');

        $preview = (string) ($this->mcpData('preview_link', ['id' => self::$page], $token)['preview'] ?? '');
        $this->assertStringContainsString('preview_key=', $preview);
        $colleague = $this->site()->client('colleague');
        $this->assertStringContainsString(self::$path, $colleague->get($preview)->body, 'the preview shows the image');
        $this->assertSame(200, $colleague->get('/' . self::$path)->status, 'and whoever opened the preview loads the waiting file');
        $this->assertSame(404, $this->site()->client('stranger')->get('/' . self::$path)->status, 'without the preview it stays 404');
        $this->assertSame('1', $this->waiting(self::$path), 'it still waits');
    }

    public function testPublishingTheContentThatUsesItMakesItPublic(): void
    {
        $this->site()->mcp('publish_build', ['id' => self::$page]);
        $this->assertSame('1', $this->waiting(self::$path), 'the build is published but the page is hidden: the file still waits');
        $this->site()->mcp('update_page', ['id' => self::$page, 'visible' => true]);

        $this->assertSame('0', $this->waiting(self::$path), 'the page is on the site: its file is public');
        $this->assertFileExists($this->site()->path(self::$path), 'moved to media/');
        $this->assertFileDoesNotExist($this->site()->path('storage/staging/' . self::$path), 'and gone from the staging folder');
        $this->assertSame([], glob($this->site()->path('storage/staging/' . preg_replace('/\.png$/', '', self::$path)) . '*') ?: [], 'with all its variants');
        $this->assertSame(200, $this->site()->client('visitor')->get('/' . self::$path)->status, 'a visitor loads it');
    }

    public function testAFilePublishedContentAlreadyUsesGoesPublicOnTheFirstRequest(): void
    {
        $path = (string) ($this->uploadAsDrafts('used-elsewhere.png')['path'] ?? '');
        $page = (int) $this->sq('SELECT page_id FROM tl_pages WHERE visible = TRUE AND deleted_at IS NULL ORDER BY page_id LIMIT 1');
        // published content changed outside the usual places (no admin change, no write call followed)
        $this->site()->exec('UPDATE tl_pages SET text = ? WHERE page_id = ?', ['<p><img src="/' . $path . '" alt="[Photo]"></p>', $page]);

        $this->assertSame(200, $this->site()->client('visitor')->get('/' . $path)->status, 'the first request of a visitor publishes it');
        $this->assertSame('0', $this->waiting($path), 'the row is public');
        $this->assertFileExists($this->site()->path($path), 'the file is in media/');
    }

    public function testAPersonApprovesAFileInMedia(): void
    {
        $answer = $this->uploadAsDrafts('to-approve.png');
        $path = (string) ($answer['path'] ?? '');
        $this->assertPage('/admin.php?module=media&waiting=1', 200, ['Waiting for approval', 'Publish file', 'to-approve'], message: 'Media lists the waiting files');
        $this->assertStringContainsString('"kind":"waiting_files"', $this->mcpText('list_pending_review'), 'Waiting for you lists them');

        $this->adminPost('/admin.php?module=media&action=approve', ['media_id' => (string) $answer['id']], '/admin.php?module=media');
        $this->assertSame('0', $this->waiting($path), 'Publish file makes it public');
        $this->assertFileExists($this->site()->path($path), 'in media/');
        $this->assertSame(200, $this->site()->client('visitor')->get('/' . $path)->status, 'for everyone');
    }

    public function testAFileNobodyApprovesIsDeletedAfter30Days(): void
    {
        $path = (string) ($this->uploadAsDrafts('forgotten.png')['path'] ?? '');
        $orphan = $this->site()->path('storage/staging/media/2020/01/orphan-abcdef.png');
        @mkdir(dirname($orphan), 0775, true);
        file_put_contents($orphan, 'x');
        touch($orphan, time() - 40 * 86400);
        $this->site()->exec('UPDATE tl_media SET staged_at = NOW() - INTERVAL 31 DAY WHERE image_path = ?', [$path]);
        $this->site()->exec("UPDATE tl_jobs SET last_run = NOW() - INTERVAL 2 HOUR WHERE name = 'media_staging'");
        $this->site()->runTasks();

        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM tl_media WHERE image_path = ?', [$path]), 'the row is gone');
        $this->assertFileDoesNotExist($this->site()->path('storage/staging/' . $path), 'the file is gone');
        $this->assertFileDoesNotExist($orphan, 'a file left without a row goes too');
        $this->assertFileExists($this->site()->path(self::$path), 'a published file stays');
    }
}
