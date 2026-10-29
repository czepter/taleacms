<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Tests\Support\FakeSettings;

final class ExtensionsTest extends TestCase
{
    /** 3.2: feature defaults – Bookings is a feature, Statistics has one switch */
    public function testFeatureDefaults(): void
    {
        $this->assertSame([false, ['stats'], 'bookings', 'bookings', true, false], [
            \Talea\Core\Extensions::CATALOG['bookings'][2],
            array_values(array_intersect(['bookings', 'stats'], \Talea\Core\Extensions::enabled(FakeSettings::site(['extensions' => ''])))),
            \Talea\Admin\Modules\Bookings::EXTENSION, \Talea\Builder\Elements\Booking::EXTENSION,
            in_array(\Talea\Builder\Elements\Booking::TYPE, \Talea\Builder\ElementSchema::disabledTypes(['news', 'enquiries']), true), in_array(\Talea\Builder\Elements\Booking::TYPE, \Talea\Builder\ElementSchema::disabledTypes(['bookings']), true),
            ], '3.2: Bookings is a feature that new installations start without; a site that never saved its choice does not get it');
        $this->assertSame([false, true, [''], 6], [
            \Talea\Core\Booking::isOn(FakeSettings::site(['extensions' => 'news,claude'])), \Talea\Core\Booking::isOn(FakeSettings::site(['extensions' => 'news,bookings'])),
            array_values(array_unique(array_map(fn (string $tool): string => \Talea\Mcp\Catalog::TOOLS[$tool][1], ['list_bookings', 'booking_availability', 'save_booking_service', 'save_booking_staff', 'cancel_booking', 'confirm_booking', 'decline_booking', 'propose_booking_times']))),
            substr_count((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/BookingTools.php'), '$this->requireBookings();')], '3.2: the public booking pages, reminders and MCP tools follow the Bookings feature; no MCP tool is gated');
        $this->assertSame([true, false, null, false, true, true], [
            \Talea\Front\Stats::enabled(FakeSettings::site(['extensions' => 'stats', 'stats' => '0'])), \Talea\Front\Stats::enabled(FakeSettings::site(['extensions' => 'news,claude', 'stats' => '1'])),
            \Talea\Admin\Modules\Settings::verifyValue('stats', '1'), str_contains((string) file_get_contents(TALEA_SYSTEM . '/views/admin/settings/analytics.php'), "\$field('stats'"),
            (bool) preg_match((new \ReflectionClassConstant(\Talea\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), 'stats'), isset(\Talea\Core\Settings::DEFAULTS['stats'])], '3.2: Statistics have one switch – the feature; the old setting is not read, not saved by the Analytics tab and still accepted over MCP');
    }

    /** An imported site keeps the default of a feature its archive does not name, and the choice of one it names. */
    public function testImportedFeatures(): void
    {
        $imported = fn (string $on, ?string $off): array => \Talea\Core\Extensions::imported($on, $off);
        $this->assertSame(['news', 'stats', 'page_check'], array_values(array_intersect(['news', 'stats', 'page_check'], $imported('news,stats', 'redirects,bookings'))), 'a default-on feature the archive does not name is on');
        $this->assertNotContains('redirects', $imported('news,stats', 'redirects,bookings'), 'one it switched off stays off');
        $this->assertSame(['news', 'stats'], $imported('news,stats,unknown', null), 'an archive without an off list names every feature');
        $this->assertNotContains('page_check', $imported('news', 'page_check'));
    }
}
