<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Admin\Kernel;

/** The single settings the command palette finds (Kernel::SETTINGS_SEARCH) stay in step with the forms they jump to. */
final class PaletteSettingsTest extends TestCase
{
    public function testEverySettingPointsAtAFieldWithThatLabel(): void
    {
        $screens = [];
        foreach (Kernel::SETTINGS_MENU as $entries) {
            foreach ($entries as $entry) {
                $screens[$entry[0] . ':' . $entry[1]] = true;
            }
        }
        foreach (Kernel::SETTINGS_SEARCH as [$ident, $tab, $id, $label]) {
            $this->assertArrayHasKey($ident . ':' . $tab, $screens, $id . ': the screen is in the Settings sidebar');
            // the Business details screen shows the Settings tab "company"
            $view = (string) file_get_contents(TALEA_ROOT . '/system/views/admin/settings/' . ($ident === 'business' ? 'company' : $tab) . '.php');
            $this->assertMatchesRegularExpression('/\$field\(\'' . preg_quote($id, '/') . '\'|id="' . preg_quote($id, '/') . '"/', $view, $id . ': the form has a field with this id');
            $this->assertStringContainsString("'" . $label . "'", $view, $id . ': the palette uses the label the form shows');
        }
    }

    public function testOnlyWhatThePersonMayOpen(): void
    {
        $settings = (new \ReflectionClass(\Talea\Core\Settings::class))->newInstanceWithoutConstructor(); // not read without the Settings module
        $url = fn (string $query): string => '/admin?' . $query;
        $this->assertSame([], Kernel::settingsSearch([], $settings, $url), 'no module, no settings');
        $found = Kernel::settingsSearch(['business' => \Talea\Admin\Modules\Business::class], $settings, $url);
        $this->assertNotSame([], $found);
        foreach ($found as $entry) {
            $this->assertStringStartsWith('/admin?module=business#company_', $entry['u'], 'only the Business details fields, with the field to jump to');
            $this->assertSame('Settings → Company', $entry['s'], 'it says where the setting lives');
        }
    }

    /** the Settings search: every word, any case, with or without diacritics; an empty search finds nothing */
    public function testSearchWords(): void
    {
        $this->assertTrue(Kernel::textMatches('zone TIME', 'Time zone'));
        $this->assertTrue(Kernel::textMatches('casove', 'Časové pásmo'), 'without diacritics'); // check-english: allow
        $this->assertFalse(Kernel::textMatches('mail backup', 'Mail'), 'every word must be there');
        $this->assertFalse(Kernel::textMatches('  ', 'Mail'), 'an empty search matches nothing');
    }
}
