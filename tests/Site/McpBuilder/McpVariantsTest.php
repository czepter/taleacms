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
        $this->adminPost('/admin.php?module=parts&action=save_variant&type=hlavicka&language=', ['nazev' => 'Landing page', 'stranky' => [$idz]]);

        $this->assertStringContainsString('"varianta":"landing-page"', $this->mcpText('seznam_casti'), 'MCP lists the parts of the site with their variants');
    }

    public function testFooterVariantForOnePage(): void
    {
        $idz = $this->zPage();
        $answer = $this->mcpData('uloz_variantu', ['cast' => 'paticka', 'nazev' => 'Kampaň', 'stranky' => [$idz]]);
        self::$variant = (string) $answer['varianta'];
        $this->assertSame('kampan|[' . $idz . ']', self::$variant . '|' . json_encode($answer['stranky']), 'the footer variant is created');

        $saved = $this->mcpData('stavba_uloz', ['cast' => 'paticka', 'varianta' => self::$variant, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'znacka' => 'footer', 'deti' => [['typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => 'Paticka kampane']]]]]]]);
        $preview = (string) $saved['nahled'];
        $this->assertStringContainsString('variant=' . self::$variant, $preview, 'the preview link names the variant');
        $this->assertStringContainsString('Paticka kampane', $this->visit($preview), 'signed preview of the variant draft');

        $this->site()->mcp('publikuj_stavbu', ['cast' => 'paticka', 'varianta' => self::$variant]);
        $this->site()->clearPageCache();
        $this->assertStringContainsString('Paticka kampane', $this->visit('/z-html'), 'the published footer variant is on the chosen page');
        $this->assertStringNotContainsString('Paticka kampane', $this->visit('/kontakt'), 'and only there');

        $this->site()->mcp('uloz_variantu', ['cast' => 'paticka', 'varianta' => self::$variant, 'smazat' => true]);
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM ka_casti WHERE varianta = ?', [self::$variant]), 'the variant is deleted');
    }

    public function testVersionsOfAPage(): void
    {
        $site = $this->site();
        $site->mcp('stavba_z_html', ['titulek' => 'Verze test', 'html' => '<section><h1>Verze A</h1></section>', 'publikovat' => true]);
        self::$idv = (int) $site->value("SELECT ids FROM ka_stranky WHERE seo_link = 'verze-test'");
        $site->mcp('stavba_z_html', ['id' => self::$idv, 'html' => '<section><h1>Verze B</h1></section>', 'publikovat' => true]);

        $idr = $this->mcpData('stavba_verze', ['id' => self::$idv])['verze'][0]['idr'];
        $site->mcp('obnov_verzi', ['id' => self::$idv, 'idr' => $idr]);
        $this->assertSame('11', (string) $site->value("SELECT CONCAT(stavba_koncept LIKE '%Verze A%', stavba LIKE '%Verze B%') FROM ka_stranky WHERE ids = ?", [self::$idv]), 'the older version is in the draft, the published one stays');

        $site->mcp('zahod_koncept', ['id' => self::$idv]);
        $this->assertSame('1', (string) $site->value('SELECT stavba_koncept IS NULL FROM ka_stranky WHERE ids = ?', [self::$idv]), 'the draft is discarded');
    }

    public function testSubpageWithAScheduledPublication(): void
    {
        $parent = (int) $this->site()->value("SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'");
        $this->site()->mcp('vytvor_stranku', ['titulek' => 'Podstranka MCP', 'nadrazena' => $parent, 'zverejnit_od' => '2099-01-01 10:00']);

        $this->assertSame('o-nas/podstranka-mcp|2099-01-01 10:00:00|0', (string) $this->site()->value("SELECT CONCAT(seo_link, '|', zverejnit_od, '|', zobrazit) FROM ka_stranky WHERE titulek = 'Podstranka MCP'"), 'a subpage with a scheduled publication stays hidden');
    }

    public function testEnquiriesCarryTheirCampaign(): void
    {
        // the old section 12 had stored this enquiry through the contact form (from a page with utm_* parameters)
        $this->site()->exec("INSERT INTO ka_poptavky (datum, formular, stranka, kampan, email, data, stav) VALUES (NOW(), 'Kontakt', '/kontakt', 'utm_source=newsletter&utm_medium=email&utm_campaign=jaro', 'jana@example.cz', '[]', 0)");

        $first = $this->mcpData('seznam_poptavek', ['stav' => 'vse'])[0];

        $this->assertSame('jana@example.cz|newsletter / email / jaro', $first['email'] . '|' . $first['kampan'], 'enquiries with their campaign');
    }
}
