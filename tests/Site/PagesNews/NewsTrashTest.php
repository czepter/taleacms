<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\PagesNews;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The news trash: hidden from the web and the editor, restore as a draft, emptied after 30 days (was: section 22 of tools/test.sh). */
#[Group('site')]
final class NewsTrashTest extends SiteTestCase
{
    public function testNewsTrashLifecycle(): void
    {
        $site = $this->site();
        $idc = (int) $site->value("SELECT idc FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'");
        $this->assertPage('/admin.php?module=news', 200, 'Smazat označené', message: 'news list');

        $this->adminPost('/admin.php?module=news&action=delete', ['smaz' => [$idc]], '/admin.php?module=news');
        $this->assertPage('/novinky/vitejte-v-kalete', 404, message: 'news in the trash is not on the web');
        $this->assertPage('/novinky/vitejte-v-kalete?preview=1', 404, message: 'news in the trash is not in the preview either');
        $this->assertPage('/admin.php?module=news&status=kos', 200, 'Vítejte', message: 'Trash tab');
        $this->assertPage("/admin.php?module=news&action=edit&id=$idc", 404, message: 'news in the trash cannot be edited');

        $this->adminPost('/admin.php?module=news&action=restore', ['smaz' => [$idc]], '/admin.php?module=news');
        $this->assertSame('0/1', (string) $site->value('SELECT CONCAT(visible, \'/\', smazano IS NULL) FROM ka_novinky WHERE idc = ?', [$idc]), 'restored news comes back as a draft');

        $site->exec('UPDATE ka_novinky SET visible = 1, smazano = NOW() - INTERVAL 31 DAY WHERE idc = ?', [$idc]);
        $this->assertPage('/admin.php', 200, 'Přehled', message: 'entering the administration empties the old trash');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM ka_novinky WHERE idc = ?', [$idc]), 'news older than 30 days in the trash is deleted for good');
    }
}
