<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Content hygiene: media clean-up, alt texts over MCP, content check, translation overview, bulk actions (was: section 78,). */
#[Group('site')]
final class ContentHygieneTest extends SiteTestCase
{
    use McpHelpers;

    private static int $alt = 0;
    private static int $page = 0;
    private static int $bulkA = 0;
    private static int $bulkB = 0;

    private function upload(string $filename): int
    {
        $this->mcpText('upload_file', ['filename' => $filename, 'data' => $this->pngBase64()]);

        return (int) $this->site()->value('SELECT media_id FROM tl_media WHERE image_path LIKE ? ORDER BY media_id DESC LIMIT 1', ['%' . pathinfo($filename, PATHINFO_FILENAME) . '%']);
    }

    /** @return list<array<string, mixed>> */
    private function withoutAlt(): array
    {
        return $this->site()->mcpResult('list_media_without_alt', ['limit' => 200])['images'] ?? [];
    }

    /** "type|German status" of the page in translation_status (the old f16_status). */
    private function translationStatus(array $arguments): string
    {
        $out = '';
        foreach ($this->site()->mcpResult('translation_status', $arguments)['items'] ?? [] as $item) {
            if ($item['id'] === $this->site()->publicId('pages', self::$page)) {
                $out .= $item['type'] . '|' . $item['translations']['de']['status'];
            }
        }

        return $out;
    }

    public function testAnUnusedUploadIsListedAndDeletedFromTheCleanUp(): void
    {
        $id = $this->upload('unused-f16.png');
        $this->assertGreaterThan(0, $id, 'the upload exists');
        $this->assertPage('/admin.php?module=media&action=cleanup', 200, 'name="selected[]" value="' . $this->site()->publicId('media', $id) . '" form="cleanup"', message: 'clean-up: the unused upload is listed with a checkbox of the delete form');

        $this->adminPost('/admin.php?module=media&action=bulk', ['bulk' => 'delete', 'back' => 'cleanup', 'selected' => [$this->site()->publicId('media', $id)]], formPage: '/admin.php?module=media&action=cleanup');

        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_media WHERE media_id = ?', [$id]), 'clean-up: the unused file is deleted');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM tl_change_log WHERE module = 'media' AND action = 'deleted'"), 'clean-up: the change is logged');
    }

    public function testAltTextsOverMcpAndInBulk(): void
    {
        $this->site()->setting('additional_languages', 'de'); // the German version (section 5 switched it on)
        self::$alt = $this->upload('no-description-f16.png');
        $this->site()->exec("UPDATE tl_media SET name = '' WHERE media_id = ?", [self::$alt]);

        $alt = $this->site()->publicId('media', self::$alt);
        $listed = array_filter($this->withoutAlt(), static fn (array $image): bool => ($image['id'] ?? '') === $alt && str_starts_with((string) ($image['path'] ?? ''), 'media/'));
        $this->assertNotEmpty($listed, 'MCP: list_media_without_alt lists the image without a description');

        $this->mcpText('update_media', ['id' => $this->site()->publicId('media', self::$alt), 'alt' => 'Blue square']);
        $this->assertSame('Blue square', (string) $this->site()->value('SELECT name FROM tl_media WHERE media_id = ?', [self::$alt]), 'MCP: update_media writes the description (alt)');
        $this->assertSame([], array_filter($this->withoutAlt(), static fn (array $image): bool => ($image['id'] ?? '') === $alt), 'MCP: a described image leaves the list');

        $this->site()->exec("UPDATE tl_media SET name = '' WHERE media_id = ?", [self::$alt]);
        $this->assertPage('/admin.php?module=media&action=cleanup', 200, 'name="alt[' . $this->site()->publicId('media', self::$alt) . ']"', message: 'clean-up: the image without a description has an input');
        $this->adminPost('/admin.php?module=media&action=save_alts', ['alt' => [$this->site()->publicId('media', self::$alt) => 'Square from the form']], formPage: '/admin.php?module=media&action=cleanup');
        $this->assertSame('Square from the form', (string) $this->site()->value('SELECT name FROM tl_media WHERE media_id = ?', [self::$alt]), 'clean-up: descriptions saved in bulk');
    }

    public function testTheContentCheckOfAPage(): void
    {
        self::$page = $this->createPage(['title' => 'Content check F16', 'content' => '<h2>What we offer</h2><p>Text about kitchens.</p><h4>Jump</h4><img src="/media/x.jpg">']);

        $checks = $this->site()->mcpResult('get_page', ['id' => $this->site()->publicId('pages', self::$page)])['content_check'] ?? [];
        $ok = array_column($checks, 'ok', 'check');

        $this->assertSame('10000', (int) $ok['single_h1'] . (int) $ok['heading_order'] . (int) $ok['images_alt'] . (int) $ok['title_length'] . (int) $ok['description_length'],
            'MCP: get_page content_check – one H1 (the title), a skipped level, an image without alt, a short title, no description');
        $this->assertPage('/admin.php?module=pages&action=edit&id=' . $this->site()->publicId('pages', self::$page), 200, 'data-check="heading_order"', message: 'the page editor shows the content check of the saved version');
    }

    public function testTheTranslationOverview(): void
    {
        $matrix = $this->assertPage('/admin.php?module=pages&action=translations', 200, 'translation_of=' . $this->site()->publicId('pages', self::$page), message: 'translations: the overview offers to create the missing German version');
        $this->assertTrue($matrix->contains('data-status="missing"'), 'translations: the cell says missing');
        $this->assertSame('page|missing', $this->translationStatus(['type' => 'page']), 'MCP: translation_status reports the missing German version');

        $this->mcpText('create_page', ['title' => 'Content check F16 DE', 'language' => 'de', 'translation_of' => $this->site()->publicId('pages', self::$page)]);
        $this->assertSame('', $this->translationStatus(['type' => 'page']), 'MCP: a translated page is not reported');

        sleep(1);
        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', self::$page), 'description' => 'The original changed after the translation.']);
        $this->assertSame('page|outdated', $this->translationStatus(['status' => 'outdated']), 'MCP: the original changed after the translation – outdated');
        $this->assertPage('/admin.php?module=pages&action=translations', 200, 'data-status="outdated"', message: 'translations: the matrix marks the older translation');
    }

    public function testBulkActionsInThePagesList(): void
    {
        $this->mcpText('create_page', ['title' => 'Bulk A', 'visible' => true]);
        $this->mcpText('create_page', ['title' => 'Bulk B', 'visible' => true]);
        self::$bulkA = $this->pageIdBySlug('bulk-a');
        self::$bulkB = $this->pageIdBySlug('bulk-b');
        $list = '/admin.php?module=pages';

        $this->assertPage($list, 200, 'name="selected[]" value="' . $this->site()->publicId('pages', self::$bulkA) . '" form="bulk"', message: 'pages list: row checkboxes belong to the bulk form'); // check-english: allow

        $this->adminPost($list . '&action=bulk', ['bulk' => 'hide', 'selected' => [$this->site()->publicId('pages', self::$bulkA), $this->site()->publicId('pages', self::$bulkB)]], formPage: $list);
        $this->assertSame('0,0|2', $this->site()->value('SELECT GROUP_CONCAT(visible ORDER BY page_id) FROM tl_pages WHERE page_id IN (?, ?)', [self::$bulkA, self::$bulkB]) . '|' . $this->site()->value("SELECT COUNT(*) FROM tl_change_log WHERE module = 'pages' AND action = 'bulk hidden'"),
            'bulk: two pages hidden at once, a change log entry each');

        $this->adminPost($list . '&action=bulk', ['bulk' => 'language', 'language' => 'de', 'selected' => [$this->site()->publicId('pages', self::$bulkA)]], formPage: $list);
        $this->adminPost($list . '&action=bulk', ['bulk' => 'trash', 'selected' => [$this->site()->publicId('pages', self::$bulkB)]], formPage: $list);
        $this->assertSame('de|1', $this->site()->value('SELECT language FROM tl_pages WHERE page_id = ?', [self::$bulkA]) . '|' . $this->site()->value('SELECT deleted_at IS NOT NULL FROM tl_pages WHERE page_id = ?', [self::$bulkB]),
            'bulk: a page moved to the German version, another to the trash');

        $this->assertSame(400, $this->site()->admin()->post($list . '&action=bulk', ['bulk' => 'trash', 'selected' => [$this->site()->publicId('pages', self::$bulkA)]])->status, 'bulk: a POST without CSRF is refused');

        $this->site()->exec('UPDATE tl_pages SET deleted_at = NOW() WHERE page_id IN (?, ?) OR translation_of = ?', [self::$bulkA, self::$page, self::$page]);
    }
}
