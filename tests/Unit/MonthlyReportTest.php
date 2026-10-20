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
            'updates' => [['type' => 'update.applied', 'date' => '2026-09-10 10:00:00', 'message' => 'Version 2.8.0 was installed (from 2.7.0).']],
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
}
