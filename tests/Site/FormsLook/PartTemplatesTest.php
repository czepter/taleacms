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

    private const string FOOTER = "type = 'paticka' AND language = '' AND variant = ''";

    public function testTemplatesAreOfferedInTheAdminAndThroughMcp(): void
    {
        $this->assertPage('/admin.php?module=parts&action=templates&type=hlavicka', 200, 'Logo uprostřed', message: 'templates of the header');
        $schema = $this->call('builder_schema');
        $this->assertSame(1, $this->lines($this->pick($schema, 'part_templates', 'header', 'na-stred'), 'Centred logo'), 'MCP: builder_schema lists the header templates');
        $this->assertSame(1, $this->lines($this->pick($schema, 'part_templates', 'footer', 'imprint'), 'imprint'), 'MCP: builder_schema lists the footer templates');
    }

    public function testATemplateGoesToTheDraftAndTheUnknownOneIsRefused(): void
    {
        // the old run had a published footer from the section about site parts; recreate it
        $this->call('stavba_uloz', ['part' => 'footer', 'build' => ['v' => 1, 'children' => [['type' => 'sekce', 'tag' => 'footer', 'children' => [['type' => 'company_details', 'obsah' => ['detail' => 'copyright']]]]]], 'publikovat' => true]);
        $published = $this->sql('SELECT SHA2(COALESCE(build, \'\'), 256) FROM ka_site_parts WHERE ' . self::FOOTER);
        $this->assertNotSame(hash('sha256', ''), $published, 'a published footer exists');

        $answer = $this->call('apply_part_template', ['part' => 'footer', 'template' => 'kompaktni']);
        $this->assertSame('1|' . $published, $this->sql('SELECT build_draft LIKE \'%"detail":"copyright"%\' AND build_draft NOT LIKE \'%"mrizka"%\' FROM ka_site_parts WHERE ' . self::FOOTER) . '|' . $this->sql('SELECT SHA2(COALESCE(build, \'\'), 256) FROM ka_site_parts WHERE ' . self::FOOTER), 'MCP: a template goes to the draft, the published footer stays');
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
        $this->assertSame('1', $this->sql('SELECT build_draft LIKE \'%"type":"hledani"%\' FROM ka_site_parts WHERE type = \'nenalezeno\' AND language = \'\''), 'admin: the 404 wrapper got the search template as a draft');

        $this->call('discard_draft', ['part' => 'footer']);
        $this->site()->exec("DELETE FROM ka_site_parts WHERE type = 'nenalezeno' AND language = '' AND build IS NULL");
        $this->assertSame('0', $this->sql("SELECT COUNT(*) FROM ka_site_parts WHERE type = 'nenalezeno'"), 'the draft rows are cleaned up');
    }
}
