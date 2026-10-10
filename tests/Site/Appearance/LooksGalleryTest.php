<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Appearance;

use Talea\Tests\Site\FormsLook\SiteHelpers;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** #24: the looks gallery – a look is applied as a draft look, never to the public look, and publishes or discards like any look draft. */
#[Group('site')]
final class LooksGalleryTest extends SiteTestCase
{
    use SiteHelpers;

    private const string DS = "SELECT value FROM tl_settings WHERE name = 'design_system'";
    private const string DRAFT = "SELECT value FROM tl_settings WHERE name = 'look_draft'";

    public function testLooksAreListedAndAppliedAsADraftOnly(): void
    {
        $this->call('discard_look');
        $this->assertPage('/admin.php?module=appearance&action=looks', 200, 'Atelier', message: 'admin: the looks gallery');
        $this->assertGreaterThanOrEqual(12, substr_count($this->raw('list_looks'), 'colors_dark'), 'MCP: list_looks');
        $this->assertStringContainsString('The look does not exist', $this->raw('apply_look', ['look' => 'nothing']), 'MCP: an unknown look is refused');

        $published = $this->sql(self::DS);
        $answer = $this->call('apply_look', ['look' => 'atelier']);
        $this->assertSame($published, $this->sql(self::DS), 'the public design system did not change');
        $this->assertStringContainsString('lib:cormorant-garamond', $this->sql(self::DRAFT), 'the design system is in the draft look');
        $this->assertSame('1', $this->sql("SELECT build_draft LIKE '%\"navigation\"%' FROM tl_site_parts WHERE type = 'header' AND language = '' AND variant = ''"), 'the header template is a part draft');
        $this->assertSame('', (string) $this->sql("SELECT build FROM tl_site_parts WHERE type = 'header' AND language = '' AND variant = ''"), 'the published header did not change');
        $this->assertStringContainsString('tl-color-primary: #1a1a1a', $this->site()->client('preview')->get($this->pick($answer, 'preview'))->body, 'the whole-site preview shows the look');
        $this->site()->clearPageCache();
        $this->assertStringNotContainsString('tl-color-primary: #1a1a1a', $this->site()->client()->get('/')->body, 'visitors do not see it');

        $this->site()->admin()->get('/admin.php?module=appearance&action=looks');
        $response = $this->adminPost('/admin.php?module=appearance&action=apply_look', ['look' => 'noir'], '/admin.php?module=appearance&action=looks');
        $this->assertSame(302, $response->status, 'admin: applying a look redirects');
        $draft = $this->sql(self::DRAFT);
        $this->assertSame('1|0', (string) (int) str_contains($draft, 'lib:montserrat') . '|' . (int) str_contains($draft, 'cormorant-garamond'), 'admin: a second look replaces the first in the draft');
    }

    public function testDiscardingAndPublishingALook(): void
    {
        $this->call('discard_look');
        $headerRows = $this->sql("SELECT COUNT(*) FROM tl_site_parts WHERE type = 'header'");
        $published = $this->sql(self::DS);
        $this->call('apply_look', ['look' => 'harbor']);
        $this->call('discard_look');
        $this->assertSame($headerRows . '||' . $published, $this->sql("SELECT COUNT(*) FROM tl_site_parts WHERE type = 'header'") . '|' . $this->sql(self::DRAFT) . '|' . $this->sql(self::DS), 'discard_look throws the look and its part drafts away');

        $this->call('apply_look', ['look' => 'harbor']);
        $this->call('publish_look');
        $this->assertSame('1|1|1', $this->sql("SELECT value LIKE '%lib:plus-jakarta-sans%' FROM tl_settings WHERE name = 'design_system'") . '|' . $this->sql("SELECT build LIKE '%\"navigation\"%' AND build_draft IS NULL FROM tl_site_parts WHERE type = 'header' AND language = '' AND variant = ''")
            . '|' . $this->sql("SELECT build IS NOT NULL AND build_draft IS NULL FROM tl_site_parts WHERE type = 'footer' AND language = '' AND variant = ''"), 'publish_look publishes the design system, the header and the footer');
        $this->assertSame('1', $this->sql('SELECT COUNT(*) > 0 FROM tl_look_versions'), 'the previous look is kept as a version');

        // leave the site as it was
        $this->site()->exec("UPDATE tl_settings SET value = '" . str_replace("'", "''", $published) . "' WHERE name = 'design_system'");
        $this->site()->exec("DELETE FROM tl_site_parts WHERE type IN ('header', 'footer') AND language = '' AND variant = '' AND build LIKE '%\"navigation\"%'");
        $this->site()->clearPageCache();
    }
}
