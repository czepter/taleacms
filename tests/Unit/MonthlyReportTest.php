<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\MonthlyReport;
use Talea\Tests\Support\FakeSettings;

final class MonthlyReportTest extends TestCase
{
    public function testReport(): void
    {
        // 2.9: the monthly report – the previous month across the year boundary, the agency or the site in the header, no address from the
        // data gets through, the month name in the site's language
        $this->assertSame(['2026-12-01 00:00', '2026-02-01'], [MonthlyReport::previousMonth(new \DateTimeImmutable('2027-01-15 10:00:00'))->format('Y-m-d H:i'),
            MonthlyReport::previousMonth(new \DateTimeImmutable('2026-03-31 23:59:59'))->format('Y-m-d')], '2.9: MonthlyReport::previousMonth – January goes to December of the previous year');
        $reportData = ['month' => '2026-09',
            'stats' => ['visits' => 120, 'views' => 300, 'previous_visits' => 100, 'previous_views' => 0, 'pages' => [['path' => '/sluzby', 'n' => 80]], 'sources' => [['site' => 'google.com', 'n' => 40]], 'campaigns' => []],
            'enquiries' => ['total' => 3, 'forms' => [['form' => 'Kontakt jan.novak@visitor.example', 'n' => 3]], 'pages' => [['path' => '/kontakt', 'n' => 3]], 'unanswered' => 2], 'signups' => 1, // check-english: allow
            'backups' => ['created' => 20, 'failed' => 0, 'last' => '2026-09-30 03:00:00'], 'changes' => ['people' => 12, 'claude' => 7],
            'problems' => [['group' => 'Operation', 'name' => 'Cron', 'state' => 'warning', 'info' => 'not set up – ask admin@visitor.example']], 'decisions' => ['enquiries' => 2, 'errors' => 0]];
        $withAgency = MonthlyReport::render($reportData, FakeSettings::site(['agency_name' => 'Studio Talea', 'agency_email' => 'help@studio.example', 'agency_logo' => 'media/logo.svg']), 'https://example.com/');
        $withoutAgency = MonthlyReport::render($reportData, FakeSettings::site([]), 'https://example.com');
        $this->assertSame([true, true, true, false, true, true], [str_contains($withAgency['html'], 'Studio Talea'), str_contains($withAgency['html'], 'https://example.com/media/logo.svg'), str_contains($withAgency['text'], 'help@studio.example'),
            str_contains($withoutAgency['html'], 'Studio Talea'), str_contains($withoutAgency['html'], 'Testovací firma'), str_contains($withoutAgency['html'], '120 (+20 %)')], '2.9: the report carries the agency when it is set, otherwise the site'); // check-english: allow
        $this->assertSame([false, true, true], [str_contains($withAgency['html'] . $withAgency['text'] . $withAgency['subject'], 'visitor.example'), str_contains($withoutAgency['html'], '/sluzby'), str_contains($withoutAgency['text'], 'Kontakt ')], '2.9: no e-mail address from the data gets into the report'); // check-english: allow
        $this->assertSame(['Website report – September 2026 – Testovací firma', 'Zpráva o webu – září 2026 – Testovací firma', 'Website-Bericht – Januar 2027 – Testovací firma'], [$withoutAgency['subject'], MonthlyReport::render($reportData, FakeSettings::site(['site_language' => 'cs']), 'https://example.com')['subject'], // check-english: allow
            MonthlyReport::render(['month' => '2027-01'], FakeSettings::site(['site_language' => 'de']), 'https://example.com')['subject']], '2.9: the subject names the month in the site language'); // check-english: allow
    }

    public function testArchivedReportIsScrubbedAndPrintable(): void
    {
        // #161: e-mail addresses (also spaced or written out) and phone numbers are masked in every string; dates, prices, versions and paths stay
        $this->assertSame(['Call [phone] now', '[e-mail] wrote', '[e-mail]', '[e-mail]', 'tel. [phone]', '2026-09-30 03:00:00', 'Price 12 500 EUR', 'version 4.0.5', '/news/2026/09/post'],
            array_map([MonthlyReport::class, 'redact'], ['Call +420 777 123 456 now', 'ann.smith@studio.example wrote', 'ann [at] studio [dot] example', 'ann @ studio.example', 'tel. (0049) 30 1234 5678',
                '2026-09-30 03:00:00', 'Price 12 500 EUR', 'version 4.0.5', '/news/2026/09/post']), '#161: MonthlyReport::redact');
        $this->assertSame(['title' => 'Ask [e-mail]', 'n' => 5, 'list' => ['[phone]', '2026-09-01 10:00:00']], MonthlyReport::scrub(['title' => 'Ask a@b.example', 'n' => 5, 'list' => ['777 123 456', '2026-09-01 10:00:00']]), '#161: scrub walks the whole report and keeps the counts');
        $this->assertSame(['2024-10', true, false, false], [\Talea\Core\ReportArchive::oldestKept(new \DateTimeImmutable('2026-10-15')), preg_match(\Talea\Core\ReportArchive::MONTH_PATTERN, '2026-09') === 1,
            preg_match(\Talea\Core\ReportArchive::MONTH_PATTERN, '2026-13') === 1, preg_match(\Talea\Core\ReportArchive::MONTH_PATTERN, '../x') === 1], '#161: 24 months are kept; a month is YYYY-MM');

        $data = ['month' => '2026-09', 'stats' => null, 'enquiries' => ['total' => 3, 'forms' => [['form' => 'Contact', 'n' => 3]], 'pages' => [], 'unanswered' => 1, 'categories' => [['category' => 'sales', 'n' => 2]]],
            'backups' => ['created' => 3, 'failed' => 0, 'last' => null], 'changes' => ['people' => 4, 'claude' => 5], 'problems' => [], 'decisions' => ['enquiries' => 1, 'errors' => 0],
            'requests' => ['created' => 2, 'done' => [['title' => 'New price list – call 777 123 456', 'status' => 'done', 'created' => '2026-09-02 10:00:00', 'done' => '2026-09-03 10:00:00']], 'done_count' => 1, 'open' => [], 'open_count' => 0],
            'published' => ['pages' => [], 'pages_count' => 0, 'news' => null, 'news_count' => null], 'claude' => ['sessions' => 0, 'calls' => 0, 'undone' => 0, 'runs' => 0, 'run_statuses' => [], 'run_tasks' => []],
            'security' => ['sign_ins' => 5, 'failed_sign_ins' => 2, 'events' => []], 'audit' => ['kinds' => ['link' => 2], 'total' => 2, 'fixed' => null, 'new' => null], 'bookings' => null, 'newsletters' => null, 'waiting' => ['page_drafts' => 2]];
        $sections = \Talea\Core\ReportPage::sections($data);
        $this->assertSame(['summary', 'waiting', 'requests', 'changes', 'enquiries', 'backups', 'security', 'audit', 'problems'], array_column($sections, 'key'),
            '#161: the printed report has a section for what the site has data for – no traffic without statistics, no bookings or newsletter when they are off, no Claude section without sessions or runs');
        $json = (string) json_encode($sections, JSON_UNESCAPED_UNICODE);
        $this->assertSame([true, false, true], [str_contains($json, 'New price list – call [phone]'), str_contains($json, '777 123 456'), str_contains($json, 'Sales')], '#161: the printed report masks again and names the enquiry categories');
        $this->assertSame(['September 2026', 'září 2026'], [\Talea\Core\ReportPage::monthName('2026-09'), \Talea\Core\Language::runWith('cs', fn (): string => \Talea\Core\ReportPage::monthName('2026-09'), 'admin-')], '#161: the month in the current language'); // check-english: allow
    }
}
