<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsLook;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Ready-made templates of site parts (was: section 19 of tools/test.sh). */
#[Group('site')]
final class PartTemplatesTest extends SiteTestCase
{
    use SiteHelpers;

    private const string FOOTER = "typ = 'paticka' AND jazyk = '' AND varianta = ''";

    public function testTemplatesAreOfferedInTheAdminAndThroughMcp(): void
    {
        $this->assertPage('/admin.php?module=parts&action=templates&type=hlavicka', 200, 'Logo uprostřed', message: 'templates of the header');
        $schema = $this->call('builder_schema');
        $this->assertSame(1, $this->lines($this->pick($schema, 'part_templates', 'header', 'na-stred'), 'Centred logo'), 'MCP: builder_schema lists the header templates');
        $this->assertSame(1, $this->lines($this->pick($schema, 'part_templates', 'footer', 'tiraz'), 'imprint'), 'MCP: builder_schema lists the footer templates');
    }

    public function testATemplateGoesToTheDraftAndTheUnknownOneIsRefused(): void
    {
        // the old run had a published footer from the section about site parts; recreate it
        $this->call('stavba_uloz', ['cast' => 'paticka', 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'znacka' => 'footer', 'deti' => [['typ' => 'udaje', 'obsah' => ['udaj' => 'copyright']]]]]], 'publikovat' => true]);
        $published = $this->sql('SELECT SHA2(COALESCE(stavba, \'\'), 256) FROM ka_casti WHERE ' . self::FOOTER);
        $this->assertNotSame(hash('sha256', ''), $published, 'a published footer exists');

        $answer = $this->call('apply_part_template', ['part' => 'footer', 'template' => 'kompaktni']);
        $this->assertSame('1|' . $published, $this->sql('SELECT stavba_koncept LIKE \'%"udaj":"copyright"%\' AND stavba_koncept NOT LIKE \'%"mrizka"%\' FROM ka_casti WHERE ' . self::FOOTER) . '|' . $this->sql('SELECT SHA2(COALESCE(stavba, \'\'), 256) FROM ka_casti WHERE ' . self::FOOTER), 'MCP: a template goes to the draft, the published footer stays');
        $this->assertStringContainsString('<footer', $this->site()->client()->get($this->pick($answer, 'preview'))->body, 'MCP: the part preview shows the template');
        $this->assertStringContainsString('Unknown template', $this->raw('apply_part_template', ['part' => 'footer', 'template' => 'nothing']), 'MCP: an unknown template is refused');
    }

    public function testAdminAppliesATemplateAndOpensTheBuilder(): void
    {
        $page = '/admin.php?module=parts&action=templates&type=nenalezeno';
        $this->site()->admin()->get($page);
        $response = $this->adminPost('/admin.php?module=parts&action=apply_template&type=nenalezeno', ['sablona' => 's-hledanim'], $page);
        $this->assertSame(302, $response->status, 'admin: applying a template redirects');
        $this->assertStringContainsString('module=parts&action=builder&type=nenalezeno', $response->redirect, 'admin: a template opens in the builder');
        $this->assertSame('1', $this->sql('SELECT stavba_koncept LIKE \'%"typ":"hledani"%\' FROM ka_casti WHERE typ = \'nenalezeno\' AND jazyk = \'\''), 'admin: the 404 wrapper got the search template as a draft');

        $this->call('discard_draft', ['part' => 'footer']);
        $this->site()->exec("DELETE FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = '' AND stavba IS NULL");
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_casti WHERE typ = 'nenalezeno'"), 'the draft rows are cleaned up');
    }
}
