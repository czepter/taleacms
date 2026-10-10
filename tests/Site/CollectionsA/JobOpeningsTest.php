<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.11 job openings that close themselves (was: section 60 of tools/test.sh). The preset links the contact to the team collection
 * of section 56, so the team ("Our team", preset people) is created here first.
 */
#[Group('site')]
final class JobOpeningsTest extends SiteTestCase
{
    use CollectionsHelpers;

    private static string $idk = '';
    private static string $source = '';
    private static string $element = '';
    private static string $time = '';
    private static string $signature = '';
    private static string $cvPath = '';
    private static string $applicationId = '';

    public function testPresetAndTheJobPage(): void
    {
        $this->mcpText('create_collection', ['name' => 'Our team', 'preset' => 'people']);
        $text = $this->mcpText('create_collection', ['name' => 'Job openings', 'preset' => 'jobs']);
        self::$idk = $this->sq("SELECT collection_id FROM tl_collections WHERE preset = 'jobs'");
        $this->assertSame('JobPosting|employment_type|1|/job-openings|1', $this->sq("SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.type')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.fields.employmentType')), '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[8].collection')) = (SELECT slug FROM tl_collections WHERE preset = 'people' ORDER BY collection_id LIMIT 1), '|', hidden_redirect, '|', build LIKE '%{{name}}%' AND build LIKE '%\"type\":\"form\"%' AND build LIKE '%\"type\":\"file\"%') FROM tl_collections WHERE collection_id = " . self::$idk),
            'the preset brings JobPosting data, the contact linked to the team, the redirect of hidden jobs to the jobs page and an item template with a form and a CV field');
        $this->assertStringContainsString('valid_until', $text, 'Claude is told to always set the closing date (valid_until)');

        // the collection currency: salaries are published only with it
        $this->site()->exec("UPDATE tl_collections SET schema_org = JSON_SET(schema_org, '\$.currency', 'CZK') WHERE collection_id = " . self::$idk);
        $tomorrow = $this->siteDate('tomorrow');
        $this->mcpText('save_collection_item', ['collection' => 'job-openings', 'name' => 'Carpenter', 'slug' => 'carpenter', 'values' => [
            'location' => 'Brno', 'employment_type' => 'full-time', 'salary_min' => '35000', 'salary_max' => '45000', 'salary_unit' => 'per month', 'description' => '<p>Custom furniture making.</p>'],
            'visible' => true, 'valid_until' => $tomorrow]);

        $job = $this->visitor()->get('/job-openings/carpenter');
        $this->assertStringContainsString('"@type":"JobPosting"', $job->body, 'the item page carries a JobPosting');
        $this->assertStringContainsString("\"validThrough\":\"$tomorrow\"", $job->body, '... with validThrough');
        $this->assertStringContainsString('"employmentType":"FULL_TIME"', $job->body);
        $this->assertStringContainsString('"addressLocality":"Brno","addressCountry":"CZ"', $job->body, '... the place');
        $this->assertStringContainsString('"baseSalary":{"@type":"MonetaryAmount","currency":"CZK","value":{"@type":"QuantitativeValue","minValue":35000,"maxValue":45000,"unitText":"MONTH"}}', $job->body, '... the salary');
        $this->assertStringContainsString('"hiringOrganization":{"@type":"Organization","name":"', $job->body, '... the company');

        $this->assertStringContainsString('name="p3" type="file"', $job->body, 'the application form has a CV field');
        $this->assertStringContainsString('type="hidden" name="p6" value="Carpenter"', $job->body, '... carries the job name in a hidden field');
        $this->assertStringContainsString('enctype="multipart/form-data"', $job->body);
        self::$source = $job->field('source');
        self::$element = $job->field('element');
        self::$time = $job->field('as_time');
        self::$signature = $job->field('as_signature');
        $this->assertSame('collection:' . $this->site()->publicId('collections', (int) $this->sq('SELECT collection_id FROM tl_collections WHERE preset = \'jobs\' LIMIT 1')), self::$source, "the form is served from the collection's item template (the source of its enquiries)");
    }

    /** A job whose closing date passed hides itself and its address leads to the jobs page; a job without a closing date is in the audit. */
    public function testValidityJobClosesAJob(): void
    {
        $yesterday = $this->siteDate('yesterday');
        $this->mcpText('save_collection_item', ['collection' => 'job-openings', 'name' => 'Welder', 'slug' => 'welder', 'values' => ['location' => 'Brno'], 'visible' => true, 'valid_until' => $yesterday]);
        $this->mcpText('save_collection_item', ['collection' => 'job-openings', 'name' => 'No deadline', 'slug' => 'no-deadline', 'values' => ['location' => 'Praha'], 'visible' => true]);
        $this->assertPage('/job-openings/welder', 200, 'Welder', message: 'before the validity job the job that closed yesterday still has its page');

        $this->site()->exec("UPDATE tl_jobs SET last_run = NULL WHERE name = 'validity'");
        $this->site()->runTasks();
        $this->assertSame('carpenter=1,no-deadline=1,welder=0', $this->sq("SELECT GROUP_CONCAT(CONCAT(slug, '=', visible) ORDER BY slug) FROM tl_collection_items WHERE collection_id = " . self::$idk), 'the validity job hid the job whose closing date passed, the open ones stay');

        $redirect = $this->visitor()->get('/job-openings/welder');
        $this->assertSame(301, $redirect->status, "the closed job's address leads to the jobs page");
        $this->assertSame($this->site()->base . '/job-openings', $redirect->redirect);

        $audit = $this->mcpText('site_audit', ['kind' => 'job']);
        $this->assertStringContainsString('No deadline', $audit, 'site_audit kind job lists the visible job without a closing date');
        $this->assertStringNotContainsString('Carpe', $audit, '... and not the one with a closing date');
        $this->assertStringContainsString('job":1', $audit);
        $this->assertStringContainsString('collection":"job-openings', $audit, '... with its target');
        $this->assertPage('/admin.php?module=audit', 200, 'No deadline', message: 'Administration → Site audit lists the job opening without a closing date');
    }

    /** The retention of applications next to the enquiries retention, with the usual practice of the company country as a hint. */
    public function testApplicationRetentionIsSavedAndEnforced(): void
    {
        $page = $this->assertPage('/admin.php?module=enquiries', 200, 'name="applicant_months" value="0"', message: 'Enquiries offers the retention of job applications with the usual practice for the company country');
        $this->assertStringContainsString('CZ: 6', $page->body, 'the hint names the country and the months');

        $this->adminPost('/admin.php?module=enquiries&action=settings', ['months' => '24', 'applicant_months' => '3'], formPage: '/admin.php?module=enquiries');
        $this->assertSame('3|24', $this->sq("SELECT CONCAT((SELECT value FROM tl_settings WHERE name = 'job_applications_months'), '|', (SELECT value FROM tl_settings WHERE name = 'enquiries_months'))"), 'the retention of applications is saved next to the enquiries retention');

        // an application with a CV: an enquiry from the job's page; the hidden job name comes back as plain text only
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $cv = $this->site()->workDir('cv') . '/cv.pdf';
        file_put_contents($cv, "%PDF-1.4 test CV\n");
        sleep(4); // the antispam minimum time, as the old script waited
        $response = $this->visitor()->upload('/form', [
            'source' => self::$source, 'element' => self::$element, 'back' => '/job-openings/carpenter', 'as_time' => self::$time, 'as_signature' => self::$signature,
            'p0' => 'John', 'p1' => 'jan@example.cz', 'p2' => '', 'p4' => 'I am applying.', 'p5' => '1', 'p6' => '<b>Carpenter</b>',
        ], ['p3' => $cv]);
        $this->assertStringContainsString('/job-openings/carpenter?form=' . self::$element . '&result=ok#', $response->redirect, 'an application with a CV was sent');

        self::$applicationId = $this->sq("SELECT MAX(enquiry_id) FROM tl_enquiries WHERE source = 'collection:" . self::$idk . "'");
        $this->assertSame('/job-openings/carpenter|jan@example.cz|Carpenter|1', $this->sq("SELECT CONCAT(page, '|', email, '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[6][1]')), '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) REGEXP '^[0-9]{4}/[0-9]{2}/[a-f0-9]{24}[.]pdf\$') FROM tl_enquiries WHERE enquiry_id = " . self::$applicationId),
            "the application is an enquiry from the job's page with the job name as plain text and the CV outside the web root");
        self::$cvPath = $this->sq("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) FROM tl_enquiries WHERE enquiry_id = " . self::$applicationId);
        $this->assertNotSame('', self::$cvPath);
        $this->assertFileExists($this->site()->path('storage/attachments/' . self::$cvPath), 'the CV is stored in storage/attachments');

        // the daily clean-up deletes applications past their retention (3 months) with the CV and records it; an ordinary enquiry of the same age stays (24 months)
        $this->site()->exec("INSERT INTO tl_enquiries (created_at, form, source, page, email, data) VALUES (NOW(), 'Contact', 'page:1', '/contact', 'ordinary@example.cz', '[]')");
        $ordinary = $this->sq("SELECT MIN(enquiry_id) FROM tl_enquiries WHERE source LIKE 'page:%'");
        $this->site()->exec('UPDATE tl_enquiries SET created_at = NOW() - INTERVAL 4 MONTH WHERE enquiry_id IN (?, ?)', [self::$applicationId, $ordinary]);
        $this->site()->runTasks();
        $this->assertSame('0|1|1|1', $this->sq("SELECT CONCAT((SELECT COUNT(*) FROM tl_enquiries WHERE enquiry_id = " . self::$applicationId . "), '|', (SELECT COUNT(*) FROM tl_enquiries WHERE enquiry_id = $ordinary), '|', (SELECT COUNT(*) FROM tl_events WHERE type = 'applications.purged' AND data LIKE '%\"count\":1,\"months\":3%'), '|', (SELECT COUNT(*) FROM tl_change_log WHERE module = 'enquiries' AND action = 'purge_applications'))"),
            'the clean-up deleted the application after its retention and recorded it; the ordinary enquiry of the same age stays');
        $this->assertFileDoesNotExist($this->site()->path('storage/attachments/' . self::$cvPath), 'the CV was deleted with the application');
    }
}
