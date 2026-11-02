<?php

declare(strict_types=1);

namespace Talea\Tests\Site\ImportersBooking;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Old section 98: 2.9 monthly report by e-mail (settings, preview, send now, the background job, recipient validation); #161 the archive of the reports (Settings → Reports, print, download, MCP). */
#[Group('site')]
final class MonthlyReportTest extends SiteTestCase
{
    private function reportMails(): int
    {
        return (int) $this->site()->value("SELECT COUNT(*) FROM tl_mail WHERE subject LIKE '%Website report%'");
    }

    public function testSettingsPreviewAndSendNow(): void
    {
        $site = $this->site();
        // the agency and the site e-mail come from earlier sections of the old walk
        $site->setting('agency_enabled', '1');
        $site->setting('agency_name', 'Studio Test');
        $site->setting('site_email', 'spravce@example.cz');
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        foreach (['report_monthly' => '1', 'report_recipients' => 'owner@example.cz', 'report_last_month' => ''] as $name => $value) {
            $site->setting($name, $value);
        }
        $reports = $site->admin()->get('/admin.php?module=settings&tab=reports');
        $this->assertStringContainsString('name="report_recipients"', $reports->body, 'Settings -> Reports has the recipients field');
        $this->assertStringContainsString('/admin/settings/report_preview', $reports->body, 'Settings -> Reports has the preview button');
        $this->assertStringContainsString('/admin/settings/report_send', $reports->body, 'Settings -> Reports has the send button');
        $this->assertStringNotContainsString('name="report_recipients"', $site->admin()->get('/admin.php?module=settings&tab=mail')->body, '#161: the report fields moved from Settings -> Mail to Settings -> Reports');

        $preview = $this->assertPage('/admin.php?module=settings&action=report_preview', 200, $site->settingValue('site_name'), message: 'the preview is the e-mail of the last month with the site name');
        $this->assertStringContainsString('max-width:600px', $preview->body, 'the preview is an inline-styled e-mail');
        $this->assertStringContainsString('Studio Test', $preview->body, 'the preview shows the agency');
        $this->assertStringNotContainsString('spravce@example.cz', $preview->body, 'the preview does not show the site e-mail');

        $this->adminPost('/admin.php?module=settings&action=report_send', [], '/admin.php?module=settings&tab=reports');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'owner@example.cz' AND (subject LIKE '%Website report%')"), 'send now: the report is queued for the recipient');
        $this->assertSame($lastMonth, $site->settingValue('report_last_month'), 'send now remembers the month, so the job does not send it again');
    }

    public function testJobSendsOnce(): void
    {
        $site = $this->site();
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        // with the month forgotten the job sends once, the second run finds it sent
        $site->setting('report_last_month', '');
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'monthly_report'");
        $before = $this->reportMails();
        $first = $site->runTasks();
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'monthly_report'");
        $second = $site->runTasks();
        $this->assertSame(1, $this->reportMails() - $before, 'the job sends the previous month once: two runs, one more report');
        $this->assertStringContainsString('monthly_report: sent to 1', $first, 'the job reports what it did');
        $this->assertStringContainsString('monthly_report: sent already', $second, 'the second run reports it was sent already');
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM tl_events WHERE type = 'report.sent' AND data LIKE ? AND data NOT LIKE '%@%'", ['%"month":"' . $lastMonth . '"%']), 'report.sent events carry the month and the count, never an address');
    }

    public function testRecipientsAreValidated(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'reports', 'report_monthly' => '1', 'report_recipients' => "owner@example.cz, nonsense"], '/admin.php?module=settings&tab=reports');
        $this->assertSame('owner@example.cz', $site->settingValue('report_recipients'), 'recipients: an invalid address is not saved');
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'reports', 'report_recipients' => 'owner@example.cz, agentura@example.cz'], '/admin.php?module=settings&tab=reports');
        $this->assertSame('owner@example.cz|agentura@example.cz:0', str_replace("\n", '|', $site->settingValue('report_recipients')) . ':' . $site->settingValue('report_monthly'), 'recipients: valid addresses are saved one per line, the switch off when unchecked');
    }

    /** #161: the job stores the previous month once (scrubbed, with a public id) and keeps 24 months; Settings → Reports lists it, prints it as A4, downloads and sends it; MCP reads it. */
    public function testArchivePrintDownloadSendAndMcp(): void
    {
        $site = $this->site();
        $start = new \DateTimeImmutable('first day of last month');
        $lastMonth = $start->format('Y-m');
        $author = (int) $site->value("SELECT user_id FROM tl_users WHERE username = 'admin'");
        // a request handled last month whose title carries a phone number and an e-mail address
        $site->exec("INSERT INTO tl_requests (created_at, updated_at, author_id, title, text, status, done_at) VALUES (?, ?, ?, ?, '', 'done', ?)",
            [$lastMonth . '-10 10:00:00', $lastMonth . '-11 10:00:00', $author, 'Call Jan on +420 777 123 456 or jan.novak@visitor.example', $lastMonth . '-11 10:00:00']);
        $site->exec('DELETE FROM tl_monthly_reports');
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'report_archive'");
        $this->assertStringContainsString('report_archive: stored ' . $lastMonth, $site->runTasks(), 'the job stores the previous month');
        $row = $site->rows('SELECT public_id, data FROM tl_monthly_reports WHERE month = ?', [$lastMonth])[0] ?? [];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) ($row['public_id'] ?? ''), 'a stored month has a public id');
        $data = (string) ($row['data'] ?? '');
        $this->assertStringContainsString('Call Jan on [phone] or [e-mail]', $data, 'the request title is stored with its phone number and e-mail address masked');
        $this->assertSame([false, false], [str_contains($data, 'visitor.example'), str_contains($data, '777 123 456')], 'no e-mail address or phone number in the archive');

        // stored once; months older than 24 are removed
        $site->exec("INSERT INTO tl_monthly_reports (month, created_at, data) VALUES ('2000-01', NOW(), '{}')");
        $site->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'report_archive'");
        $this->assertStringContainsString('report_archive: stored already, removed 1', $site->runTasks(), 'the second run keeps the stored month and removes the old one');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_monthly_reports'), 'one month in the archive');

        // Settings → Reports
        $this->assertPage('/admin.php?module=settings&tab=reports', 200, [$start->format('F Y'), 'report_view', 'report_download', 'Reports exist from'], message: 'the Reports screen lists the month and says from when reports exist');
        $view = $this->assertPage('/admin.php?module=settings&action=report_view&month=' . $lastMonth, 200, ['@page', 'size: A4', 'counter(pages)', 'data-print', 'print.js', 'Requests to Claude', 'Call Jan on [phone]'], message: 'the printable A4 page');
        $this->assertStringNotContainsString('visitor.example', $view->body, 'the printed report has no e-mail address');
        $this->assertStringContainsString("script-src 'self'", $view->headers['content-security-policy'] ?? '', 'only the Print button script may run');
        $download = $site->admin()->get('/admin.php?module=settings&action=report_download&month=' . $lastMonth);
        $this->assertSame([200, true, false], [$download->status, str_contains($download->headers['content-disposition'] ?? '', 'attachment; filename="website-report-'), str_contains($download->body, '<script')], 'the download is the page as an HTML file without a script');
        $this->assertSame([404, 404], [$site->admin()->get('/admin.php?module=settings&action=report_view&month=1999-01')->status, $site->admin()->get('/admin.php?module=settings&action=report_view&month=../x')->status], 'a month that is not stored is not found');

        // send an archived month: queued for the recipients, the job's month stays
        $site->setting('report_recipients', 'owner@example.cz');
        $site->setting('report_last_month', $lastMonth);
        $before = (int) $site->value("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'owner@example.cz' AND subject LIKE '%Website report%'");
        $this->adminPost('/admin.php?module=settings&action=report_send', ['month' => $lastMonth], '/admin.php?module=settings&tab=reports');
        $this->assertSame($before + 1, (int) $site->value("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'owner@example.cz' AND subject LIKE '%Website report%'"), 'send by e-mail queues the archived month');
        $this->adminPost('/admin.php?module=settings&action=report_send', ['month' => '1999-01'], '/admin.php?module=settings&tab=reports');
        $this->assertSame($before + 1, (int) $site->value("SELECT COUNT(*) FROM tl_mail WHERE recipient = 'owner@example.cz' AND subject LIKE '%Website report%'"), 'a month that is not stored is not sent');

        // MCP: read-only, the same data
        $list = (array) $site->mcpResult('list_monthly_reports');
        $this->assertSame([$lastMonth, (string) $row['public_id'], 24], [$list['reports'][0]['month'] ?? null, $list['reports'][0]['id'] ?? null, $list['kept_months'] ?? null], 'list_monthly_reports names the stored months by public id');
        $report = (array) $site->mcpResult('get_monthly_report', ['month' => $lastMonth]);
        $this->assertSame([$lastMonth, true, 'Call Jan on [phone] or [e-mail]'], [$report['month'] ?? null, str_contains((string) ($report['print_url'] ?? ''), 'report_view'), $report['report']['requests']['done'][0]['title'] ?? null], 'get_monthly_report gives the archived data and the printable page');
        $this->assertSame([true, true], [($site->mcp('get_monthly_report', ['month' => '1999-01'])['result']['isError'] ?? false) === true, ($site->mcp('get_monthly_report', ['month' => 'last'])['result']['isError'] ?? false) === true], 'a month that is not stored, or not YYYY-MM, is an error');
    }
}
