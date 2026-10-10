<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Appearance;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The design system: live preview, draft look, publishing (was: the design system section of tools/test.sh). */
#[Group('site')]
final class AppearanceTest extends SiteTestCase
{
    public function testTheAppearanceScreenOffersPresets(): void
    {
        $this->assertPage('/admin.php?module=appearance', 200, 'data-preset', message: 'appearance with presets and a preview');
    }

    public function testTheLivePreviewReturnsTokensAndContrasts(): void
    {
        $response = $this->adminPost('/admin.php?module=appearance&action=preview', ['ds' => ['colors' => ['primary' => '#ff00aa'], 'base_min' => '18']], '/admin.php?module=appearance');

        $this->assertStringContainsString('tl-color-primary: #ff00aa', $response->body, 'the live preview returns tokens');
        $this->assertStringContainsString('"contrasts"', $response->body, 'the live preview returns contrasts');
    }

    public function testASavedAppearanceWaitsInTheDraftLook(): void
    {
        $this->adminPost('/admin.php?module=appearance&action=save', [
            'dark_mode' => 'off',
            'ds' => ['colors' => ['primary' => '#9a3412', 'text' => 'red;}body{'], 'font_heading' => 'classic', 'width' => '1280'],
        ], '/admin.php?module=appearance');

        $home = $this->site()->client()->get('/');

        $this->assertStringNotContainsString('tl-color-primary: #9a3412', $home->body, 'the saved appearance is on the site before publishing');
        $this->assertPage('/admin.php?module=appearance', 200, 'Unpublished look changes', message: 'the admin shows the draft look with its changes');
    }

    #[Depends('testASavedAppearanceWaitsInTheDraftLook')]
    public function testThePublishedAppearanceIsOnTheSite(): void
    {
        $this->adminPost('/admin.php?module=appearance&action=publish_look', [], '/admin.php?module=appearance');
        $this->site()->clearPageCache();

        $home = $this->site()->client()->get('/');

        foreach (['tl-color-primary: #9a3412', 'tl-width: 80rem', 'tl-font-heading: Georgia'] as $needle) {
            $this->assertStringContainsString($needle, $home->body, 'the saved appearance is on the site after publishing');
        }
        $this->assertStringNotContainsString('body{', $home->body, 'an invalid colour is replaced by the default');
    }

    public function testTheFontPickerOffersTheLibraryAndSuggestedPairs(): void
    {
        $page = $this->assertPage('/admin.php?module=appearance', 200, ['<optgroup label="Serif">', 'value="lib:playfair-display"', 'Suggested pairs', 'data-font-sample="ds[font_heading]"'], message: 'the font tab');

        $this->assertStringContainsString('@font-face { font-family: "Inter"', $page->body, 'the picker has a preview-only @font-face for every family');
        $this->assertStringContainsString('data-preset="{&quot;font_heading&quot;:&quot;lib:', $page->body, 'a pairing fills in both selects');
    }

    public function testALibraryFontIsChosenByNameOverMcpAndOnlyItsFilesReachTheSite(): void
    {
        $unknown = $this->site()->mcp('update_design_system', ['design' => ['font_heading' => 'Comic Sans Deluxe']]);
        $this->assertStringContainsString('is not available', json_encode($unknown, JSON_UNESCAPED_UNICODE), 'a typo is reported, not silently replaced');

        $this->site()->mcp('update_design_system', ['design' => ['font_heading' => 'Playfair Display', 'font_body' => 'lib:source-sans-3']]);
        $this->site()->mcp('publish_look', []);
        $this->site()->clearPageCache();
        $home = $this->site()->client()->get('/');

        $this->assertStringContainsString('font-family: "Playfair Display"', $home->body);
        $this->assertStringContainsString('font-family: "Source Sans 3"', $home->body);
        $this->assertStringContainsString('--tl-font-heading: "Playfair Display", Georgia', $home->body);
        foreach (['"Inter"', '"Roboto"', '"Caveat"'] as $other) {
            $this->assertStringNotContainsString('font-family: ' . $other, $home->body, "$other is not chosen, so it is not declared");
        }
        $this->assertSame(2, substr_count($home->body, 'rel="preload"'), 'text and heading file are preloaded, nothing else');
        $this->assertStringContainsString('/image/fonts/playfair-display/playfair-display.woff2', $home->body);
        $this->assertDoesNotMatchRegularExpression('~https?://[^"\s]*(fonts\.googleapis|fonts\.gstatic|typekit)~', $home->body, 'no font host is referenced');
        foreach (['/image/fonts/playfair-display/playfair-display.woff2', '/image/fonts/source-sans-3/source-sans-3.woff2'] as $file) {
            $font = $this->site()->client()->get($file);
            $this->assertSame(200, $font->status, $file . ' is served by the site itself');
        }
    }
}
