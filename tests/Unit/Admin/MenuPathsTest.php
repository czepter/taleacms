<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Core\Language;

final class MenuPathsTest extends TestCase
{
    protected function tearDown(): void
    {
        Language::set('en'); // a language a test switched to does not leak into the next test
    }

    /** menu paths as links (messages, System status, help) */
    public function testMenuPathsAsLinks(): void
    {
        \Talea\Core\Language::set('cs', 'admin-');
        $routesHtml = \Talea\Admin\MenuPaths::links('/admin', 'Zálohu obnovíte v Nastavení → Zálohy. <b>', ['settings']); // check-english: allow
        $this->assertTrue(str_contains($routesHtml, '<a href="/admin/settings?tab=backups">Nastavení → Zálohy</a>'), 'Paths: a known path is a link'); // check-english: allow
        $this->assertTrue(str_contains($routesHtml, '&lt;b&gt;'), 'Paths: the rest of the text stays escaped');
        $this->assertSame(1, substr_count($routesHtml, '<a '), 'Paths: a longer path wins and the link does not nest');
        $this->assertFalse(str_contains(\Talea\Admin\MenuPaths::links('/admin', 'Nastavení → Pošta', []), '<a '), 'Paths: without the right to the module no link'); // check-english: allow
        \Talea\Core\Language::set('en', 'admin-');
        $this->assertTrue(str_contains(\Talea\Admin\MenuPaths::links('/admin', t('Restore it in Settings → Backups.'), ['settings']), '>Settings → Backups</a>'), 'Menu paths: in English the translated path is linked');
        \Talea\Core\Language::set('cs', 'admin-');
    }
}
