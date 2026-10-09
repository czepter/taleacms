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
        $this->adminPost('/admin.php?module=parts&action=save_variant&type=hlavicka&language=', ['name' => 'Landing page', 'pages' => [$idz]]);

        $this->assertStringContainsString('"variant":"landing-page"', $this->mcpText('seznam_casti'), 'MCP lists the parts of the site with their variants');
    }

    public function testFooterVariantForOnePage(): void
    {
        $idz = $this->zPage();
        $answer = $this->mcpData('uloz_variantu', ['part' => 'paticka', 'nazev' => 'Kampaň', 'pages' => [$idz]]);
        self::$variant = (string) $answer['variant'];
        $this->assertSame('kampan|[' . $idz . ']', self::$variant . '|' . json_encode($answer['pages']), 'the footer variant is created');

        $saved = $this->mcpData('stavba_uloz', ['part' => 'paticka', 'variant' => self::$variant, 'build' => ['v' => 1, 'deti' => [['type' => 'sekce', 'znacka' => 'footer', 'deti' => [['type' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => 'Paticka kampane']]]]]]]);
        $preview = (string) $saved['nahled'];
        $this->assertStringContainsString('variant=' . self::$variant, $preview, 'the preview link names the variant');
        $this->assertStringContainsString('Paticka kampane', $this->visit($preview), 'signed preview of the variant draft');

        $this->site()->mcp('publikuj_stavbu', ['part' => 'paticka', 'variant' => self::$variant]);
        $this->site()->clearPageCache();
        $this->assertStringContainsString('Paticka kampane', $this->visit('/z-html'), 'the published footer variant is on the chosen page');
        $this->assertStringNotContainsString('Paticka kampane', $this->visit('/kontakt'), 'and only there');

        $this->site()->mcp('uloz_variantu', ['part' => 'paticka', 'variant' => self::$variant, 'smazat' => true]);
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM ka_site_parts WHERE variant = ?', [self::$variant]), 'the variant is deleted');
    }

    public function testVersionsOfAPage(): void
    {
        $site = $this->site();
        $site->mcp('stavba_z_html', ['title' => 'Verze test', 'html' => '<section><h1>Verze A</h1></section>', 'publikovat' => true]);
        self::$idv = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'verze-test'");
        $site->mcp('stavba_z_html', ['id' => self::$idv, 'html' => '<section><h1>Verze B</h1></section>', 'publikovat' => true]);

        $idr = $this->mcpData('stavba_verze', ['id' => self::$idv])['verze'][0]['idr'];
        $site->mcp('obnov_verzi', ['id' => self::$idv, 'idr' => $idr]);
        $this->assertSame('11', (string) $site->value("SELECT CONCAT(build_draft LIKE '%Verze A%', build LIKE '%Verze B%') FROM ka_pages WHERE page_id = ?", [self::$idv]), 'the older version is in the draft, the published one stays');

        $site->mcp('zahod_koncept', ['id' => self::$idv]);
        $this->assertSame('1', (string) $site->value('SELECT build_draft IS NULL FROM ka_pages WHERE page_id = ?', [self::$idv]), 'the draft is discarded');
    }

    public function testSubpageWithAScheduledPublication(): void
    {
        $parent = (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'o-nas'");
        $this->site()->mcp('vytvor_stranku', ['title' => 'Podstranka MCP', 'parent_id' => $parent, 'publish_at' => '2099-01-01 10:00']);

        $this->assertSame('o-nas/podstranka-mcp|2099-01-01 10:00:00|0', (string) $this->site()->value("SELECT CONCAT(slug, '|', publish_at, '|', visible) FROM ka_pages WHERE title = 'Podstranka MCP'"), 'a subpage with a scheduled publication stays hidden');
    }

    public function testEnquiriesCarryTheirCampaign(): void
    {
        // the old section 12 had stored this enquiry through the contact form (from a page with utm_* parameters)
        $this->site()->exec("INSERT INTO ka_enquiries (created_at, form, page, campaign, email, data, status) VALUES (NOW(), 'Kontakt', '/kontakt', 'utm_source=newsletter&utm_medium=email&utm_campaign=jaro', 'jana@example.cz', '[]', 0)");

        $first = $this->mcpData('seznam_poptavek', ['status' => 'vse'])[0];

        $this->assertSame('jana@example.cz|newsletter / email / jaro', $first['email'] . '|' . $first['campaign'], 'enquiries with their campaign');
    }
}
