<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Claude (MCP): variants, versions, pages and enquiries like in the administration (was: section 16). */
#[Group('site')]
final class McpVariantsTest extends SiteTestCase
{
    use McpBuilderHelpers;

    private static string $variant = '';
    private static int $idv = 0;

    public function testPartsListShowsTheHeaderVariant(): void
    {
        // the old section 15 had created this header variant for the Claude page
        $idz = $this->zPage();
        $this->adminPost('/admin.php?module=parts&action=save_variant&type=header&language=', ['name' => 'Landing page', 'pages' => [$idz]]);

        $this->assertStringContainsString('"variant":"landing-page"', $this->mcpText('list_site_parts'), 'MCP lists the parts of the site with their variants');
    }

    public function testFooterVariantForOnePage(): void
    {
        $idz = $this->zPage();
        $answer = $this->mcpData('save_part_variant', ['part' => 'footer', 'name' => 'Campaign', 'pages' => [$idz]]);
        self::$variant = (string) $answer['variant'];
        $this->assertSame('campaign|[' . $idz . ']', self::$variant . '|' . json_encode($answer['pages']), 'the footer variant is created');

        $saved = $this->mcpData('save_build', ['part' => 'footer', 'variant' => self::$variant, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'tag' => 'footer', 'children' => [['type' => 'heading', 'tag' => 'p', 'content' => ['text' => 'Campaign footer']]]]]]]);
        $preview = (string) $saved['preview'];
        $this->assertStringContainsString('variant=' . self::$variant, $preview, 'the preview link names the variant');
        $this->assertStringContainsString('Campaign footer', $this->visit($preview), 'signed preview of the variant draft');

        $this->site()->mcp('publish_build', ['part' => 'footer', 'variant' => self::$variant]);
        $this->site()->clearPageCache();
        $this->assertStringContainsString('Campaign footer', $this->visit('/z-html'), 'the published footer variant is on the chosen page');
        $this->assertStringNotContainsString('Campaign footer', $this->visit('/contact'), 'and only there');

        $this->site()->mcp('save_part_variant', ['part' => 'footer', 'variant' => self::$variant, 'delete' => true]);
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM ka_site_parts WHERE variant = ?', [self::$variant]), 'the variant is deleted');
    }

    public function testVersionsOfAPage(): void
    {
        $site = $this->site();
        $site->mcp('build_from_html', ['title' => 'Version test', 'html' => '<section><h1>Version A</h1></section>', 'publish' => true]);
        self::$idv = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'version-test'");
        $site->mcp('build_from_html', ['id' => self::$idv, 'html' => '<section><h1>Version B</h1></section>', 'publish' => true]);

        $versionId = $this->mcpData('list_build_versions', ['id' => self::$idv])['versions'][0]['version_id'];
        $site->mcp('restore_build_version', ['id' => self::$idv, 'version_id' => $versionId]);
        $this->assertSame('11', (string) $site->value("SELECT CONCAT(build_draft LIKE '%Version A%', build LIKE '%Version B%') FROM ka_pages WHERE page_id = ?", [self::$idv]), 'the older version is in the draft, the published one stays');

        $site->mcp('discard_draft', ['id' => self::$idv]);
        $this->assertSame('1', (string) $site->value('SELECT build_draft IS NULL FROM ka_pages WHERE page_id = ?', [self::$idv]), 'the draft is discarded');
    }

    public function testSubpageWithAScheduledPublication(): void
    {
        $parent = (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'about-us'");
        $this->site()->mcp('create_page', ['title' => 'MCP subpage', 'parent' => $parent, 'publish_at' => '2099-01-01 10:00']);

        $this->assertSame('about-us/mcp-subpage|2099-01-01 10:00:00|0', (string) $this->site()->value("SELECT CONCAT(slug, '|', publish_at, '|', visible) FROM ka_pages WHERE title = 'MCP subpage'"), 'a subpage with a scheduled publication stays hidden');
    }

    public function testEnquiriesCarryTheirCampaign(): void
    {
        // the old section 12 had stored this enquiry through the contact form (from a page with utm_* parameters)
        $this->site()->exec("INSERT INTO ka_enquiries (created_at, form, page, campaign, email, data, status) VALUES (NOW(), 'Contact', '/contact', 'utm_source=newsletter&utm_medium=email&utm_campaign=spring', 'jane@example.com', '[]', 0)");

        $first = $this->mcpData('list_enquiries', ['status' => 'all'])[0];

        $this->assertSame('jane@example.com|newsletter / email / spring', $first['email'] . '|' . $first['campaign'], 'enquiries with their campaign');
    }
}
