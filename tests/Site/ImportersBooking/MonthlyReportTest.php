<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Old section 98: 2.9 monthly report by e-mail (settings, preview, send now, the background job, recipient validation). */
#[Group('site')]
final class MonthlyReportTest extends SiteTestCase
{
    private function reportMails(): int
    {
        return (int) $this->site()->value("SELECT COUNT(*) FROM ka_mail WHERE subject LIKE '%Zpráva o webu%' OR subject LIKE '%Website report%'");
    }

    public function testSettingsPreviewAndSendNow(): void
    {
        $site = $this->site();
        // the agency and the site e-mail come from earlier sections of the old walk
        $site->setting('agency_name', 'Studio Test');
        $site->setting('site_email', 'spravce@example.cz');
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        foreach (['report_monthly' => '1', 'report_recipients' => 'owner@example.cz', 'report_last_month' => ''] as $name => $value) {
            $site->setting($name, $value);
        }
        $mail = $site->admin()->get('/admin.php?module=settings&tab=mail');
        $this->assertStringContainsString('name="report_recipients"', $mail->body, 'Settings -> Mail has the recipients field');
        $this->assertStringContainsString('action=report_preview', $mail->body, 'Settings -> Mail has the preview button');
        $this->assertStringContainsString('action=report_send', $mail->body, 'Settings -> Mail has the send button');

        $preview = $this->assertPage('/admin.php?module=settings&action=report_preview', 200, $site->settingValue('site_name'), message: 'the preview is the e-mail of the last month with the site name');
        $this->assertStringContainsString('max-width:600px', $preview->body, 'the preview is an inline-styled e-mail');
        $this->assertStringContainsString('Studio Test', $preview->body, 'the preview shows the agency');
        $this->assertStringNotContainsString('spravce@example.cz', $preview->body, 'the preview does not show the site e-mail');

        $this->adminPost('/admin.php?module=settings&action=report_send', [], '/admin.php?module=settings&tab=mail');
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM ka_mail WHERE recipient = 'owner@example.cz' AND (subject LIKE '%Zpráva o webu%' OR subject LIKE '%Website report%')"), 'send now: the report is queued for the recipient');
        $this->assertSame($lastMonth, $site->settingValue('report_last_month'), 'send now remembers the month, so the job does not send it again');
    }

    public function testJobSendsOnce(): void
    {
        $site = $this->site();
        $lastMonth = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        // with the month forgotten the job sends once, the second run finds it sent
        $site->setting('report_last_month', '');
        $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'");
        $before = $this->reportMails();
        $first = $site->runTasks();
        $site->exec("UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'");
        $second = $site->runTasks();
        $this->assertSame(1, $this->reportMails() - $before, 'the job sends the previous month once: two runs, one more report');
        $this->assertStringContainsString('monthly_report: sent to 1', $first, 'the job reports what it did');
        $this->assertStringContainsString('monthly_report: sent already', $second, 'the second run reports it was sent already');
        $this->assertSame(2, (int) $site->value("SELECT COUNT(*) FROM ka_events WHERE type = 'report.sent' AND data LIKE ? AND data NOT LIKE '%@%'", ['%"month":"' . $lastMonth . '"%']), 'report.sent events carry the month and the count, never an address');
    }

    public function testRecipientsAreValidated(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'mail', 'mail_mode' => 'mail', 'report_monthly' => '1', 'report_recipients' => "owner@example.cz, nonsense"], '/admin.php?module=settings&tab=mail');
        $this->assertSame('owner@example.cz', $site->settingValue('report_recipients'), 'recipients: an invalid address is not saved');
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'mail', 'mail_mode' => 'mail', 'report_recipients' => 'owner@example.cz, agentura@example.cz'], '/admin.php?module=settings&tab=mail');
        $this->assertSame('owner@example.cz|agentura@example.cz:0', str_replace("\n", '|', $site->settingValue('report_recipients')) . ':' . $site->settingValue('report_monthly'), 'recipients: valid addresses are saved one per line, the switch off when unchecked');
    }
}
