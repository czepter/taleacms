<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Appearance;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The design system: live preview, draft look, publishing (was: section "vzhled webu (design systém)" of tools/test.sh). */
#[Group('site')]
final class AppearanceTest extends SiteTestCase
{
    public function testTheAppearanceScreenOffersPresets(): void
    {
        $this->assertPage('/admin.php?module=appearance', 200, 'data-predvolba', message: 'vzhled s předvolbami a náhledem');
    }

    public function testTheLivePreviewReturnsTokensAndContrasts(): void
    {
        $response = $this->adminPost('/admin.php?module=appearance&action=preview', ['ds' => ['barvy' => ['primarni' => '#ff00aa'], 'zaklad_min' => '18']], '/admin.php?module=appearance');

        $this->assertStringContainsString('ka-barva-primarni: #ff00aa', $response->body, 'živý náhled vrátí tokeny');
        $this->assertStringContainsString('"kontrasty"', $response->body, 'živý náhled vrátí kontrasty');
    }

    public function testASavedAppearanceWaitsInTheDraftLook(): void
    {
        $this->adminPost('/admin.php?module=appearance&action=save', [
            'layout' => 'zakladni', 'tmavy_rezim' => 'vypnuto',
            'ds' => ['barvy' => ['primarni' => '#9a3412', 'text' => 'red;}body{'], 'pismo_titulky' => 'klasicke', 'sirka' => '1280'],
        ], '/admin.php?module=appearance');

        $home = $this->site()->client()->get('/');

        $this->assertStringNotContainsString('ka-barva-primarni: #9a3412', $home->body, 'the saved appearance is on the site before publishing');
        $this->assertPage('/admin.php?module=appearance', 200, 'Nepublikované změny vzhledu', message: 'the admin shows the draft look with its changes');
    }

    #[Depends('testASavedAppearanceWaitsInTheDraftLook')]
    public function testThePublishedAppearanceIsOnTheSite(): void
    {
        $this->adminPost('/admin.php?module=appearance&action=publish_look', [], '/admin.php?module=appearance');
        $this->site()->clearPageCache();

        $home = $this->site()->client()->get('/');

        foreach (['ka-barva-primarni: #9a3412', 'ka-sirka: 80rem', 'ka-pismo-titulky: Georgia'] as $needle) {
            $this->assertStringContainsString($needle, $home->body, 'uložený vzhled je po publikování na webu');
        }
        $this->assertStringNotContainsString('body{', $home->body, 'neplatná barva se nahradí výchozí');
    }
}
