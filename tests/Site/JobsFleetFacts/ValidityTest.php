<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\JobsFleetFacts;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 55 "2.10 true until and review by" of tools/test.sh. */
#[Group('site')]
final class ValidityTest extends SiteTestCase
{
    use Helpers;

    private static int $page = 0;
    private static int $news = 0;
    private static string $yesterday = '';
    private static string $today = '';

    public function testMcpTakesTrueUntilAndReviewBy(): void
    {
        $site = $this->site();
        self::$yesterday = date('Y-m-d', strtotime('-1 day'));
        self::$today = date('Y-m-d');
        // the old run took the category of the news section (the first of the site)
        $category = (string) $site->value("SELECT nazev FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1");
        $this->assertNotSame('', $category, 'the site has a news category');
        // not due until the test runs it itself (a day ahead: MySQL and PHP may be in different time zones)
        $site->exec("INSERT INTO ka_jobs (name, last_run) VALUES ('validity', NOW() + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)");

        $page = $this->mcpText('create_page', ['title' => 'Expired offer', 'text' => '<p>Only until yesterday.</p>', 'visible' => true, 'valid_until' => self::$yesterday, 'review_by' => self::$today]);
        self::$page = (int) $site->value("SELECT ids FROM ka_stranky WHERE titulek = 'Expired offer'");
        $this->assertStringContainsString('"valid_until":"' . self::$yesterday, $page, 'MCP: create_page returns valid_until');
        $this->assertStringContainsString('"review_by":"' . self::$today, $page, 'MCP: create_page returns review_by');

        $news = $this->mcpText('create_news', ['title' => 'Expired news', 'category' => $category, 'publish' => true, 'valid_until' => self::$yesterday, 'review_by' => self::$today]);
        self::$news = (int) $site->value("SELECT idc FROM ka_novinky WHERE titulek = 'Expired news'");
        $this->assertStringContainsString('"valid_until":"' . self::$yesterday, $news, 'MCP: create_news takes valid_until and review_by');

        $popup = $this->mcpText('save_popup', ['name' => 'Review popup', 'template' => 'blank', 'review_by' => self::$yesterday]);
        $this->assertStringContainsString('"review_by":"' . self::$yesterday, $popup, 'MCP: save_popup takes review_by');
        $this->assertStringContainsString('must be a date', $this->mcpText('update_page', ['id' => self::$page, 'valid_until' => 'nonsense']), 'MCP: a value that is not a date is refused');
        $this->sameValue('1|1', $site->value('SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = ?), \'|\', (SELECT visible FROM ka_novinky WHERE idc = ?))', [self::$page, self::$news]), 'before the job both are still visible');
    }

    public function testTheHourlyJobHidesWhatExpiredAndAsksForReviewsOnce(): void
    {
        $site = $this->site();
        $page = self::$page;
        $news = self::$news;
        $visible = 'SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = ?), \'|\', (SELECT visible FROM ka_novinky WHERE idc = ?))';
        $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'");
        $output = $site->runTasks();
        $this->sameValue('0|0', $site->value($visible, [$page, $news]), 'the job hid the expired page and news item');
        $this->sameValue('1|2|3', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired' AND severity = 'warning' AND data LIKE '%\"kind\":\"page\",\"id\":$page%'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'))"), 'content.expired events with the kind and id, content.review once per content');
        $this->sameValue('2', $site->value("SELECT COUNT(*) FROM ka_protokol WHERE akce = 'expired' AND modul IN ('pages', 'news')"), 'the change log records what hid itself');
        $this->assertStringContainsString('validity: hidden 2, reviews 3', $output, 'the job reports what it did');

        $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'");
        $site->runTasks();
        $this->sameValue('3|2', $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'))"), 'a second run asks for no review twice and hides nothing again');
        $this->assertPage('/expired-offer', 404, message: 'the hidden page is no longer on the site');
    }

    public function testAuditAndFormsShowTheDates(): void
    {
        $site = $this->site();
        $audit = $this->mcpText('site_audit', ['kind' => 'review']);
        foreach (['Expired offer', 'Expired news', 'Review popup', '"page":', '"news":', '"popup":'] as $needle) {
            $this->assertStringContainsString($needle, $audit, "MCP: site_audit kind review lists $needle");
        }
        $this->assertPage('/admin.php?module=audit', 200, 'Expired offer', message: 'Administration → Site audit shows the review-by findings');

        $form = $this->assertPage('/admin.php?module=pages&action=edit&id=' . self::$page, 200, 'name="valid_until" value="' . self::$yesterday . '"', message: 'the page form shows true until and review by');
        $this->assertStringContainsString('name="review_by" value="' . self::$today . '"', $form->body, 'the page form shows the review-by date');

        $this->adminPost('/admin.php?module=pages&action=save', [
            'ids' => self::$page, 'titulek' => 'Expired offer', 'seo_link' => 'expired-offer', 'text' => '<p>x</p>', 'poradi' => 100, 'valid_until' => '', 'review_by' => '2030-01-01',
        ], '/admin.php?module=pages&action=edit&id=' . self::$page);
        $this->sameValue('null|2030-01-01', $site->value("SELECT CONCAT(IFNULL(valid_until, 'null'), '|', IFNULL(review_by, 'null')) FROM ka_stranky WHERE ids = ?", [self::$page]), 'saving the page form clears true until and keeps the new review-by date');

        $this->assertPage('/admin.php?module=pages', 200, 'stitek stitek-koncept" title="V tento den žádá o kontrolu."', message: 'the pages list shows the review-by badge');
        $this->assertPage('/admin.php?module=news&action=edit&id=' . self::$news, 200, 'name="review_by" value="' . self::$today . '"', message: 'the news form shows the two fields');
        $popup = (int) $site->value("SELECT idpp FROM ka_popupy WHERE nazev = 'Review popup'");
        $this->assertPage("/admin.php?module=popups&action=edit&id=$popup", 200, 'name="review_by" value="' . self::$yesterday . '"', message: 'the pop-up form shows the two fields');

        $this->mcpText('update_page', ['id' => self::$page, 'review_by' => '']);
        $this->sameValue('null', $site->value('SELECT IFNULL(review_by, \'null\') FROM ka_stranky WHERE ids = ?', [self::$page]), 'MCP: an empty string clears review by');
    }
}
