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
        $routesHtml = \Talea\Admin\MenuPaths::links('/admin.php', 'Je k dispozici nová verze 3.0.1 – nainstalujete ji v Nastavení → Zálohy a aktualizace. <b>', ['settings']); // check-english: allow
        $this->assertTrue(str_contains($routesHtml, '<a href="/admin.php?module=settings&amp;tab=backups">Nastavení → Zálohy a aktualizace</a>'), 'Paths: a known path is a link'); // check-english: allow
        $this->assertTrue(str_contains($routesHtml, '&lt;b&gt;'), 'Paths: the rest of the text stays escaped');
        $this->assertSame(1, substr_count($routesHtml, '<a '), 'Paths: a longer path wins and the link does not nest');
        $this->assertFalse(str_contains(\Talea\Admin\MenuPaths::links('/admin.php', 'Nastavení → Pošta', []), '<a '), 'Paths: without the right to the module no link'); // check-english: allow
        \Talea\Core\Language::set('en', 'admin-');
        $this->assertTrue(str_contains(\Talea\Admin\MenuPaths::links('/admin.php', t('A new version %s is available – install it in Settings → Backups and updates.', '3.0.1'), ['settings']), '>Settings → Backups and updates</a>'), 'Menu paths: in English the translated path is linked');
        \Talea\Core\Language::set('cs', 'admin-');
    }
}
