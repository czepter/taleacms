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
}
