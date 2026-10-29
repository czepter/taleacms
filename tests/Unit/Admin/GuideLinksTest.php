<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class GuideLinksTest extends TestCase
{
    /** 2.4: guide links in the administration */
    public function testGuideLinks(): void
    {
        // the articles of the guide on taleacms.com; a new admin module or settings tab needs its article here and in Admin\Guide
        $guideArticles = ['install', 'first-steps', 'extensions', 'builder-basics', 'styling-responsive', 'elements', 'page-settings', 'site-appearance', 'classes', 'components',
            'site-parts', 'popups', 'menus', 'collections', 'collection-lists', 'site-search', 'news', 'forms', 'newsletter', 'company-details', 'seo', 'languages',
            'claude-connect', 'claude-capabilities', 'ai-assistant', 'users-roles', 'wordpress-import', 'backups-updates', 'media', 'statistics', 'privacy-cookies', 'email', 'site-health', 'fleet-console', 'industry-blueprints', 'connections', 'addons', 'bookings', 'claude-operator', 'sites'];
        $guideTargets = [...\Talea\Admin\Guide::MODULES, ...\Talea\Admin\Guide::SETTINGS, ...\Talea\Admin\Guide::BUILDER];
        $this->assertSame([[], [], []], [
            array_values(array_diff(array_map(fn (string $c): string => $c::IDENT, \Talea\Admin\Kernel::MODULES), array_keys(\Talea\Admin\Guide::MODULES), ['settings'])),
            array_values(array_diff(array_keys(\Talea\Admin\Modules\Settings::TABS), array_keys(\Talea\Admin\Guide::SETTINGS))),
            array_values(array_filter($guideTargets, fn (string $a): bool => !in_array(strtok($a, '#'), $guideArticles, true))),
        ], '2.4: every admin module and settings tab links to an existing guide article');
        $this->assertSame(['https://taleacms.com/cs/guide/backups-updates', 'https://taleacms.com/de/guide/builder-basics', 'https://taleacms.com/guide/popups', 'https://taleacms.com/guide/statistics', null], [
            \Talea\Admin\Guide::forScreen('settings', '', 'backups', 'cs'), \Talea\Admin\Guide::forScreen('pages', 'builder', '', 'de'),
            \Talea\Admin\Guide::forScreen('popups', 'builder', '', 'en'), \Talea\Admin\Guide::forScreen('stats', '', '', 'pl'), \Talea\Admin\Guide::forScreen('nothing', '', '', 'en'),
        ], '2.4: guide link follows the admin language');
    }
}
