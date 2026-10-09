<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsB;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 65 (2.11 official notice board – posting and takedown dates, permanent archive, audit trail, the hourly job). */
#[Group('site')]
final class NoticeBoardTest extends SiteTestCase
{
    use Helpers;

    private static int $board = 0;
    private static int $noticeA = 0;
    private static int $noticeB = 0;

    private function day(string $modifier, string $format = 'Y-m-d'): string
    {
        return date($format, strtotime($modifier));
    }

    public function testBoardArchivePagesAndNotices(): void
    {
        $site = $this->site();
        // the job runs only when the test asks (a day ahead: MySQL and PHP may be in different time zones)
        $site->exec("INSERT INTO ka_jobs (name, last_run) VALUES ('notices', NOW() + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)");
        $yesterday = $this->day('-1 day');
        $tomorrow = $this->day('+1 day');
        $tenAgo = $this->day('-10 day');

        $text = $this->mcpText('create_collection', ['name' => 'Úřední deska', 'preset' => 'notices']);
        // over MCP the texts are English (as the field labels of every preset); from the admin the name is translated
        $this->assertSame('notices|2|1|1|Úřední deska – archive', $site->value("SELECT CONCAT((SELECT preset FROM ka_collections WHERE slug = 'uredni-deska'), '|', (SELECT COUNT(*) FROM ka_pages WHERE slug IN ('uredni-deska', 'uredni-deska-archive') AND visible = 0), '|', (SELECT build LIKE '%\"period\":\"probihajici\"%' FROM ka_pages WHERE slug = 'uredni-deska'), '|', (SELECT build LIKE '%\"period\":\"minule\"%' AND build LIKE '%\"kolekce\":\"uredni-deska\"%' FROM ka_pages WHERE slug = 'uredni-deska-archive'), '|', (SELECT title FROM ka_pages WHERE slug = 'uredni-deska-archive'))"),
            'notices: the collection with its board and its archive page, both hidden, each listing its period');
        $this->assertStringContainsString('more_pages', $text, 'notices: Claude is told about more pages');
        $this->assertStringContainsString('uredni-deska-archive', $text, 'notices: Claude is told about the archive page');
        $this->assertSame('1', (string) $site->value("SELECT build LIKE '%{{notice_status}}%' FROM ka_collections WHERE slug = 'uredni-deska'"), 'notices: the item template comes from the preset with the status line');
        self::$board = (int) $site->value("SELECT collection_id FROM ka_collections WHERE slug = 'uredni-deska'");

        self::$noticeA = (int) $site->mcpResult('save_collection_item', ['collection' => 'uredni-deska', 'name' => 'Záměr pronájmu', 'slug' => 'zamer-pronajmu',
            'values' => ['posted' => $yesterday, 'taken_down' => $tomorrow, 'reference' => 'MU/2026/41', 'issuer' => 'Městský úřad', 'category' => 'Majetek', 'summary' => 'Záměr pronajmout pozemek.'], 'visible' => true])['id'];
        self::$noticeB = (int) $site->mcpResult('save_collection_item', ['collection' => 'uredni-deska', 'name' => 'Rozpočet 2026', 'slug' => 'rozpocet-2026',
            'values' => ['posted' => $tenAgo, 'taken_down' => $yesterday, 'reference' => 'MU/2026/12', 'category' => 'Rozpočet'], 'visible' => true])['id'];
        $site->mcp('save_collection_item', ['collection' => 'uredni-deska', 'name' => 'Budoucí vyhláška', 'slug' => 'budouci', 'values' => ['posted' => '2099-01-01']]);
        $this->assertSame('0', (string) $site->value("SELECT visible FROM ka_collection_items WHERE slug = 'budouci'"), 'notices: a notice still to be posted may stay hidden');

        $text = $this->mcpText('save_collection_item', ['collection' => 'uredni-deska', 'name' => 'Skrytá minulá', 'values' => ['posted' => $yesterday]]);
        $this->assertStringContainsString('cannot be hidden', $text, 'MCP: a notice whose posting day has come cannot be created hidden');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_collection_items WHERE name = 'Skrytá minulá'"), 'MCP: the refused notice was not created');

        foreach (['uredni-deska', 'uredni-deska-archive'] as $slug) {
            $site->mcp('update_page', ['id' => (int) $site->value('SELECT page_id FROM ka_pages WHERE slug = ?', [$slug]), 'visible' => true]);
        }
        $board = $site->client()->get('/uredni-deska')->body;
        foreach (['Záměr pronájmu', 'MU/2026/41', 'Majetek'] as $needle) {
            $this->assertStringContainsString($needle, $board, "notices: the board shows the current notice with its reference and the category filter ($needle)");
        }
        $this->assertStringNotContainsString('Rozpočet 2026', $board, 'notices: the board does not show the archived notice');

        $archive = $site->client()->get('/uredni-deska-archive')->body;
        $this->assertStringContainsString('Rozpočet 2026', $archive, 'notices: the archive shows the notice taken down yesterday');
        $this->assertStringNotContainsString('Záměr pronájmu', $archive, 'notices: the archive does not show the current one');

        $item = $this->assertPage('/uredni-deska/zamer-pronajmu', 200, 'Vyvěšeno od ' . $this->day('-1 day', 'j. n. Y') . ' do ' . $this->day('+1 day', 'j. n. Y'), message: 'notices: the item page says from when to when the notice is posted');
        $this->assertStringContainsString('Městský úřad', $item->body, 'notices: the item page has the issuer');

        // as a visitor: the page must not land in the page cache
        $archived = $site->client()->get('/uredni-deska/rozpocet-2026')->body;
        $this->assertStringContainsString('Sejmuto ' . $this->day('-1 day', 'j. n. Y') . ' – archiv', $archived, 'notices: an archived notice says when it was taken down');
        foreach (glob($site->path('storage/cache/stranky/*.html')) ?: [] as $cached) {
            $this->assertStringNotContainsString('Sejmuto', (string) file_get_contents($cached), 'notices: the archived notice page is not cached');
        }
    }

    #[Depends('testBoardArchivePagesAndNotices')]
    public function testThePermanentArchiveRefusesHidingAndDeleting(): void
    {
        $site = $this->site();
        $a = self::$noticeA;
        $b = self::$noticeB;
        $board = self::$board;

        $text = $this->mcpText('save_collection_item', ['collection' => 'uredni-deska', 'id' => $a, 'visible' => false]);
        $this->assertStringContainsString('cannot be hidden', $text, 'MCP: a posted notice cannot be hidden');
        $this->assertSame('1', (string) $site->value('SELECT visible FROM ka_collection_items WHERE item_id = ?', [$a]), 'MCP: the posted notice stays visible');

        $text = $this->mcpText('delete_collection_item', ['collection' => 'uredni-deska', 'id' => $b]);
        $this->assertStringContainsString('stay in the archive', $text, 'MCP: delete_collection_item refuses a notice with a clear message');
        $this->assertSame('1', (string) $site->value('SELECT deleted_at IS NULL FROM ka_collection_items WHERE item_id = ?', [$b]), 'MCP: the notice was not deleted');

        $items = $this->assertPage("/admin.php?module=collections&action=items&id=$board");
        $this->assertStringNotContainsString('action=delete_item"', $items->body, 'admin: the notices list has no Delete button');
        $this->assertStringContainsString('archiv', $items->body, 'admin: the notices list mentions the archive');
        $token = $items->csrf();

        $site->admin()->post('/admin.php?module=collections&action=delete_item', ['_csrf' => $token, 'collection_id' => $board, 'item_id' => $b]);
        $this->assertSame('1', (string) $site->value('SELECT deleted_at IS NULL FROM ka_collection_items WHERE item_id = ?', [$b]), 'admin: the delete action refuses a notice');
        $this->assertPage("/admin.php?module=collections&action=items&id=$board", 200, 'změňte místo toho datum sejmutí', message: 'admin: the refusal is explained');

        $text = $this->mcpText('delete_collection', ['collection' => 'uredni-deska']);
        $this->assertStringContainsString('cannot be deleted', $text, 'MCP: the board cannot be deleted while it has notices');
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_collections WHERE slug = 'uredni-deska'"), 'MCP: the board is still there');

        $site->admin()->post('/admin.php?module=collections&action=delete', ['_csrf' => $token, 'collection_id' => $board]);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM ka_collections WHERE slug = 'uredni-deska'"), 'admin: the collection delete refuses a board with notices');
    }

    #[Depends('testThePermanentArchiveRefusesHidingAndDeleting')]
    public function testTheAuditTrailOfCreatedAndChangedNotices(): void
    {
        $site = $this->site();
        $a = self::$noticeA;
        $b = self::$noticeB;
        $board = self::$board;
        $yesterday = $this->day('-1 day');
        $tenAgo = $this->day('-10 day');

        $this->assertSame('3|Claude|MU/2026/41', $site->value("SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(DISTINCT `by`), '|', (SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '\$.reference[1]')) FROM ka_notice_log WHERE item_id = $a AND action = 'created')) FROM ka_notice_log WHERE action = 'created'"),
            'notices: a created row per notice, written by Claude, with the values');

        $save = ['collection' => 'uredni-deska', 'id' => $b, 'values' => ['summary' => 'Schválený rozpočet.']];
        $site->mcp('save_collection_item', $save);
        $site->mcp('save_collection_item', $save);
        $this->assertSame('1||Schválený rozpočet.|0', $site->value("SELECT CONCAT(COUNT(*), '|', IFNULL(MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '\$.summary[0]'))), ''), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '\$.summary[1]'))), '|', MAX(JSON_CONTAINS_PATH(fields, 'one', '\$.reference'))) FROM ka_notice_log WHERE item_id = $b AND action = 'changed'"),
            'notices: a change is logged once with the field, the old and the new value');

        $token = $this->assertPage("/admin.php?module=collections&action=item&id=$board&item=$b")->csrf();
        $form = ['_csrf' => $token, 'collection_id' => $board, 'item_id' => $b, 'name' => 'Rozpočet 2026', 'slug' => 'rozpocet-2026', 'sort_order' => 100, 'visible' => 1,
            'data' => ['posted' => $tenAgo, 'taken_down' => $yesterday, 'reference' => 'MU/2026/12', 'issuer' => 'Rada města', 'category' => 'Rozpočet', 'document' => '', 'summary' => 'Schválený rozpočet.']];
        $site->admin()->post('/admin.php?module=collections&action=save_item', $form);
        $this->assertSame('Tester|Rada města', $site->value("SELECT CONCAT(`by`, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$.issuer[1]'))) FROM ka_notice_log WHERE item_id = $b AND action = 'changed' ORDER BY id DESC LIMIT 1"),
            "admin: saving the form logs the change under the user's name");

        unset($form['visible'], $form['data']['category'], $form['data']['document'], $form['data']['summary']);
        $site->admin()->post('/admin.php?module=collections&action=save_item', $form);
        $this->assertSame('1', (string) $site->value('SELECT visible FROM ka_collection_items WHERE item_id = ?', [$b]), 'admin: the form cannot hide a posted notice either');
    }

    #[Depends('testTheAuditTrailOfCreatedAndChangedNotices')]
    public function testTheHourlyJobRecordsPostedAndTakenDownOnce(): void
    {
        $site = $this->site();
        $a = self::$noticeA;
        $b = self::$noticeB;
        $board = self::$board;
        $yesterday = $this->day('-1 day');

        $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'");
        $out = $site->runTasks();
        $this->assertStringContainsString('notices: posted 2, taken down 1', $out, 'notices: the job reports what it recorded');

        $this->assertSame('1|1|3|0', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_notice_log WHERE item_id = $a AND action = 'posted'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE item_id = $b AND action = 'taken_down' AND JSON_UNQUOTE(JSON_EXTRACT(fields, '\$.taken_down')) = '$yesterday'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down') AND `by` = 'system'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE item_id = (SELECT item_id FROM ka_collection_items WHERE slug = 'budouci') AND action <> 'created'))"),
            'notices: posted for both visible notices, taken_down for the archived one, by system');

        $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'");
        $out = $site->runTasks();
        $this->assertStringContainsString('notices: posted 0, taken down 0', $out, 'notices: a second run reports nothing new');
        $this->assertSame('3', (string) $site->value("SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down')"), 'notices: a second run records nothing twice');

        // the log under the item form and the CSV for an administrator, not for a guest
        $form = $this->assertPage("/admin.php?module=collections&action=item&id=$board&item=$b", 200, 'action=notice_log', message: 'notices: the item form shows the log with the CSV link');
        $this->assertStringContainsString('Rada města', $form->body, 'notices: the log shows the changes');
        $this->assertStringContainsString("taken_down: $yesterday", $form->body, 'notices: the log shows the takedown');

        $csv = $site->admin()->get("/admin.php?module=collections&action=notice_log&id=$board");
        $this->assertSame('200 text/csv; charset=utf-8', $csv->status . ' ' . ($csv->headers['content-type'] ?? ''), 'notices: the administrator downloads the log as CSV');
        $this->assertSame('1|2|3', substr_count($csv->body, ';taken_down;') . '|' . substr_count($csv->body, ';posted;') . '|' . substr_count($csv->body, ';created;'), 'notices: the CSV has every row of the board');
        $this->assertStringContainsString('Rada města', $csv->body, 'notices: the CSV has the changes');

        $guest = $site->client()->get("/admin.php?module=collections&action=notice_log&id=$board");
        $this->assertStringStartsWith('text/html', $guest->headers['content-type'] ?? '', 'notices: a guest gets a page, not the CSV');
        $this->assertStringNotContainsString('taken_down', $guest->body, 'notices: a guest sees nothing of the log');
        $this->assertStringContainsString('Heslo', $guest->body, 'notices: a guest gets the sign-in form instead of the CSV');
    }

    #[Depends('testTheHourlyJobRecordsPostedAndTakenDownOnce')]
    public function testTheLogOverMcpIsReadOnly(): void
    {
        $site = $this->site();
        $yesterday = $this->day('-1 day');

        $all = $site->mcpResult('list_notice_log', ['collection' => 'uredni-deska']);
        $this->assertSame('8|created|Claude|MU/2026/41', $all['count'] . '|' . $all['entries'][0]['action'] . '|' . $all['entries'][0]['by'] . '|' . $all['entries'][0]['fields']['reference'][1], 'MCP: list_notice_log lists the whole trail with who and what');

        $one = $site->mcpResult('list_notice_log', ['collection' => 'uredni-deska', 'id' => self::$noticeB]);
        $this->assertSame("5|taken_down|$yesterday", $one['count'] . '|' . $one['entries'][4]['action'] . '|' . $one['entries'][4]['fields']['taken_down'], 'MCP: list_notice_log of one notice');

        $tools = array_column($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')['result']['tools'], 'annotations', 'name');
        $this->assertTrue($tools['list_notice_log']['readOnlyHint'] === true && !isset($tools['edit_notice_log']) && !isset($tools['delete_notice_log']), 'MCP: the notice log is read-only – no tool edits or deletes it');
    }
}
