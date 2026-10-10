<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\LinksConnectors;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Enquiries to a Google sheet and the CRM (HubSpot, Pipedrive, Raynet) (was: section 85, 2.13). */
#[Group('site')]
final class EnquiryConnectorsTest extends SiteTestCase
{
    use FakeServices;

    private const string CONNECTORS = '/admin.php?module=connectors';

    /** @var array<string, string> signed fields of the enquiry form, taken from its page once */
    private static array $form = [];
    /** @var array<string, string> the same for the job item page */
    private static array $job = [];

    /** The old grep -q on a log: § = anything, ¤ = anything but a quote, ¶ = digits and dashes, ¦ = digits and colons; the rest is literal. */
    private function logHas(string $log, string $pattern): bool
    {
        $regex = str_replace(['§', '¤', '¶', '¦'], ['.*', '[^"]*', '[0-9-]*', '[0-9:]*'], preg_quote($pattern, '~'));

        return preg_match('~' . $regex . '~', $log) === 1;
    }

    private function assertLogHas(string $log, string $pattern, string $message): void
    {
        $this->assertTrue($this->logHas($log, $pattern), $message . ' – not found: ' . $pattern . ' in ' . mb_substr($log, -1500));
    }

    /** @return array<string, string> */
    private function fieldsOf(string $path): array
    {
        $page = $this->site()->client('visitor')->get($path);
        $fields = [];
        foreach (['source', 'element', 'as_time', 'as_signature'] as $name) {
            $fields[$name] = $page->field($name);
        }

        return $fields;
    }

    /** Old crm_submit: that form, the per-IP limit of the earlier form tests cleared first. Returns the redirect address. @param array<string, string> $fields */
    private function submit(array $fields): string
    {
        $this->site()->exec("DELETE FROM ka_ip_checks WHERE type = 'form'");

        return $this->site()->client('visitor')->post('/form', ['source' => self::$form['source'], 'element' => self::$form['element'], 'back' => '/enquiry-crm', 'as_time' => self::$form['as_time'], 'as_signature' => self::$form['as_signature']] + $fields)->redirect;
    }

    private function tick(): void
    {
        $this->site()->runTasks();
    }

    private function queueMax(): int
    {
        return (int) $this->site()->value('SELECT IFNULL(MAX(id), 0) FROM ka_connector_queue');
    }

    public function testTheFormPageAndTheConnections(): void
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => 'Enquiry CRM', 'visible' => true]);
        $page = (int) $site->value("SELECT page_id FROM ka_pages WHERE slug = 'enquiry-crm'");
        $site->mcp('save_build', ['id' => $page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => ['name' => 'Enquiry CRM', 'fields' => [
            ['label' => 'Full name', 'type' => 'text', 'required' => true], ['label' => 'Email', 'type' => 'email', 'required' => true], ['label' => 'Phone', 'type' => 'tel'],
            ['label' => 'Message', 'type' => 'textarea'], ['label' => 'Consent', 'type' => 'checkbox', 'required' => true],
        ]]]]]]]]);
        $site->clearPageCache();
        self::$form = $this->fieldsOf('/enquiry-crm');
        $this->assertNotSame('', self::$form['element'], 'enquiries: the test form with every field type the mapping uses is on its page');

        $this->connectFake('google');
        $screen = $site->admin()->get(self::CONNECTORS)->body;
        foreach (['name="config[enquiries]" value="1"', 'action=sheet', 'name="config[domain]"', 'name="config[instance]"', 'name="config[enquiry_jobs]"'] as $needle) {
            $this->assertStringContainsString($needle, $screen, 'enquiries: Connections offers ' . $needle);
        }

        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'google', 'client_id' => 'test-client', 'config' => ['enquiries' => '1']], self::CONNECTORS);
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'hubspot', 'secret' => 'hs-token', 'config' => ['enquiries' => '1']], self::CONNECTORS);
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'pipedrive', 'secret' => 'pd-token', 'config' => ['domain' => 'acme', 'enquiries' => '1']], self::CONNECTORS);
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'raynet', 'account' => 'user@example.cz', 'secret' => 'rn-key', 'config' => ['instance' => 'acme-crm', 'enquiries' => '1']], self::CONNECTORS);
        $this->assertSame('google=110,hubspot=110,pipedrive=110,raynet=110', $site->value("SELECT GROUP_CONCAT(CONCAT(service, '=', connected_at IS NOT NULL, JSON_UNQUOTE(JSON_EXTRACT(config, '$.enquiries')), secret LIKE '%-token%' OR secret LIKE '%rn-key%') ORDER BY service SEPARATOR ',') FROM ka_connectors"),
            'enquiries: the CRMs are connected by their keys, Google by the sign-in, the switch is on everywhere, the keys are encrypted');
        $this->assertPage(self::CONNECTORS, 200, 'Create the sheet first', message: 'enquiries: the switch is on but the sheet is missing – the screen says so');
    }

    #[Depends('testTheFormPageAndTheConnections')]
    public function testTheSheetIsCreatedWithItsHeaderRow(): void
    {
        $site = $this->site();
        $this->adminPost('/admin.php?module=connectors&action=sheet', [], self::CONNECTORS);
        $this->assertSame('sheet-test-1|1', $site->value("SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(config, '$.sheet_id')), '|', JSON_UNQUOTE(JSON_EXTRACT(config, '$.enquiries'))) FROM ka_connectors WHERE service = 'google'"), 'enquiries: Create the sheet made the spreadsheet with the Google sign-in and kept its id with the settings');
        $log = $this->fakeLogContents('sheets.log');
        $this->assertStringContainsString('"call":"create"', $log, 'enquiries: the sheet was created');
        $this->assertStringContainsString('"title":"' . $site->settingValue('site_name') . ' – enquiries"', $log, 'enquiries: the sheet is named after the site');
        $this->assertStringContainsString('"stringValue":"Date"', $log, 'enquiries: the header row has a date');
        $this->assertStringContainsString('"stringValue":"Email"', $log, 'enquiries: the header row has an e-mail');
        $this->assertStringContainsString('"authorization":"Bearer access-1"', $log, 'enquiries: the call carried the OAuth token');
        $this->assertPage(self::CONNECTORS, 200, 'https://docs.google.com/spreadsheets/d/sheet-test-1', message: 'enquiries: Connections links the sheet');
    }

    #[Depends('testTheSheetIsCreatedWithItsHeaderRow')]
    public function testAnEnquiryIsDeliveredToTheSheetAndEveryCrmAfterTheVisitorLeft(): void
    {
        $site = $this->site();
        $first = $this->queueMax();
        sleep(4); // the form is signed with its time; a too fast submission is refused as a bot
        // the visit trigger of background jobs runs at most once a minute – mark it as just run, so only the cron call below delivers
        $site->exec("INSERT INTO ka_settings (name, value) VALUES ('notification_check', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $location = $this->submit(['p0' => 'Karl Smith', 'p1' => 'karl@example.cz', 'p2' => '+420 777 123 456', 'p3' => 'I want a new kitchen.', 'p4' => '1']);
        $this->assertStringContainsString('result=ok', $location, 'enquiries: the form was sent');
        $idp = (int) $site->value('SELECT MAX(enquiry_id) FROM ka_enquiries');
        $formName = (string) $site->value('SELECT form FROM ka_enquiries WHERE enquiry_id = ?', [$idp]);

        $queued = $site->value("SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(action ORDER BY action), '|', SUM(delivered_at IS NULL), '|', SUM(payload LIKE '%\"enquiry\":$idp,%')) FROM ka_connector_queue WHERE id > ?", [$first]);
        $this->assertSame('4|crm.lead,crm.lead,crm.lead,sheets.append|4|4', $queued, 'enquiries: one delivery per destination waits in the queue');
        $this->assertStringNotContainsString('karl', $this->fakeLogContents('crm.log'), 'enquiries: nothing went out while the visitor waited');

        $tasks = $site->runTasks();
        $this->assertStringContainsString('connectors: delivered 4', $tasks, 'enquiries: the connectors job delivered the four');
        $this->assertSame('4|4|4', $site->value("SELECT CONCAT(SUM(delivered_at IS NOT NULL), '|', SUM(payload IS NULL), '|', SUM(last_error = '')) FROM ka_connector_queue WHERE id > ?", [$first]), 'enquiries: delivered rows lose their payload (personal data), no error stays');

        $sheets = $this->fakeLogContents('sheets.log');
        $this->assertLogHas($sheets, '"call":"append","sheet":"sheet-test-1","range":"A1","query":{"valueInputOption":"RAW","insertDataOption":"INSERT_ROWS"},"authorization":"Bearer access-1"', 'enquiries: the sheet got an append with the OAuth token');
        $this->assertLogHas($sheets, '"values":[["¶ ¦","' . $formName . '","¤","karl@example.cz","' . $site->base . '/enquiry-crm","Karl Smith","+420 777 123 456","¤: I want a new kitchen."]]', 'enquiries: the sheet row – date, form, topic, e-mail, page, name, phone, then the message');

        $crm = $this->fakeLogContents('crm.log');
        $this->assertLogHas($crm, '"crm":"hubspot","method":"POST","path":"/crm/v3/objects/contacts/search","authorization":"Bearer hs-token"§"value":"karl@example.cz"', 'enquiries: HubSpot – the contact searched by e-mail');
        $this->assertLogHas($crm, '"method":"POST","path":"/crm/v3/objects/contacts",§"properties":{"email":"karl@example.cz","firstname":"Karl","lastname":"Smith","phone":"+420 777 123 456"}', 'enquiries: HubSpot – the contact created with name and phone');
        $this->assertLogHas($crm, '"path":"/crm/v3/objects/notes",§"hs_note_body":"¤I want a new kitchen.§"to":{"id":"777"}§"associationTypeId":202', 'enquiries: HubSpot – the note with the message associated to the contact');
        $this->assertLogHas($crm, '"crm":"pipedrive","method":"GET","path":"/api/v1/persons/search","api_token":"pd-token","query":{"term":"karl@example.cz","fields":"email","exact_match":"true","limit":"1"}', 'enquiries: Pipedrive – the token as api_token, the person searched');
        $this->assertLogHas($crm, '"path":"/api/v1/persons","api_token":"pd-token"§"name":"Karl Smith","email":[{"value":"karl@example.cz","primary":true}],"phone":[{"value":"+420 777 123 456","primary":true}]', 'enquiries: Pipedrive – the person created');
        $this->assertLogHas($crm, '"path":"/api/v1/leads"§"title":"' . $formName . '¤","person_id":42', 'enquiries: Pipedrive – the lead titled after the form');
        $this->assertLogHas($crm, '"path":"/api/v1/notes"§"lead_id":"lead-uuid-1","person_id":42', 'enquiries: Pipedrive – the note');
        $this->assertLogHas($crm, '"crm":"raynet","method":"PUT","path":"/api/v2/lead/","username":"user@example.cz","key_ok":true,"instance":"acme-crm"§"topic":"' . $formName . '¤","firstName":"Karl","lastName":"Smith","contactInfo":{"email":"karl@example.cz","tel1":"+420 777 123 456"},"notice":"¤I want a new kitchen', 'enquiries: Raynet – HTTP Basic with the instance header, the lead with the contact and the message');

        $this->assertSame('8|8|8|0', $site->value("SELECT CONCAT(COUNT(*), '|', SUM(ok), '|', SUM(action LIKE 'crm.%'), '|', SUM(error LIKE '%karel%' OR error LIKE '%token%')) FROM ka_connector_log WHERE service IN ('hubspot', 'pipedrive', 'raynet')"), 'enquiries: every CRM call is in the log by its action, never with the content');
    }

    #[Depends('testAnEnquiryIsDeliveredToTheSheetAndEveryCrmAfterTheVisitorLeft')]
    public function testAKnownSenderUpdatesTheContactAndReusesThePerson(): void
    {
        $site = $this->site();
        $first = $this->queueMax();
        $this->submit(['p0' => 'Known Person', 'p1' => 'known@example.cz', 'p2' => '', 'p3' => 'again', 'p4' => '1']);
        $this->tick();
        $crm = $this->fakeLogContents('crm.log');
        $this->assertLogHas($crm, '"method":"PATCH","path":"/crm/v3/objects/contacts/501"', 'enquiries: a known sender – HubSpot updates the contact');
        $this->assertLogHas($crm, '"path":"/crm/v3/objects/notes"§"to":{"id":"501"}', 'enquiries: HubSpot notes it');
        $this->assertSame(1, substr_count($crm, '"path":"/api/v1/persons",'), 'enquiries: Pipedrive created no second person');
        $this->assertLogHas($crm, '"path":"/api/v1/leads"§"person_id":31', 'enquiries: Pipedrive adds the lead to the existing person');
        $this->assertSame('4|4', $site->value('SELECT CONCAT(COUNT(*), \'|\', SUM(delivered_at IS NOT NULL)) FROM ka_connector_queue WHERE id > ?', [$first]), 'enquiries: the known sender\'s deliveries went through');
    }

    #[Depends('testAKnownSenderUpdatesTheContactAndReusesThePerson')]
    public function testJobApplicationsGoOnlyWhereTheAdministratorTickedThemAndWithoutTheCv(): void
    {
        $site = $this->site();
        // the jobs collection of section 60 (2.11): an item page whose form takes a CV
        $site->mcp('create_collection', ['name' => 'Job openings', 'preset' => 'jobs']);
        $site->exec("UPDATE ka_collections SET schema_org = JSON_SET(schema_org, '$.currency', 'CZK') WHERE preset = 'jobs'");
        $site->mcp('save_collection_item', ['collection' => 'job-openings', 'name' => 'Carpenter', 'slug' => 'carpenter', 'values' => ['location' => 'Brno', 'employment_type' => 'full-time', 'description' => '<p>Custom furniture making.</p>'], 'visible' => true, 'valid_until' => $this->siteDate('+1 day')]);
        $site->clearPageCache();
        self::$job = $this->fieldsOf('/job-openings/carpenter');
        $this->assertNotSame('', self::$job['element'], 'the job item page carries the application form');
        $cv = $site->workDir('files') . '/cv.pdf';
        file_put_contents($cv, "%PDF-1.4 test CV\n");
        sleep(4);

        $apply = function (string $email) use ($site, $cv): string {
            $site->exec("DELETE FROM ka_ip_checks WHERE type = 'form'");

            return $site->client('visitor')->upload('/form', ['source' => self::$job['source'], 'element' => self::$job['element'], 'back' => '/job-openings/carpenter', 'as_time' => self::$job['as_time'], 'as_signature' => self::$job['as_signature'],
                'p0' => 'Petr', 'p1' => $email, 'p2' => '', 'p4' => 'I am applying.', 'p5' => '1', 'p6' => 'Carpenter'], ['p3' => $cv])->redirect;
        };

        $first = $this->queueMax();
        $this->assertStringContainsString('result=ok', $apply('f13-applicant@example.cz'), 'enquiries: an application with a CV was sent');
        $this->assertSame('1|0', $site->value('SELECT CONCAT((SELECT COUNT(*) FROM ka_enquiries WHERE email = ?), \'|\', (SELECT COUNT(*) FROM ka_connector_queue WHERE id > ?))', ['f13-applicant@example.cz', $first]), 'enquiries: the application is stored but goes to no CRM and no sheet by default');

        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => 'raynet', 'account' => 'user@example.cz', 'config' => ['instance' => 'acme-crm', 'enquiries' => '1', 'enquiry_jobs' => '1']], self::CONNECTORS);
        $apply('petra@example.cz');
        $this->tick();
        $this->assertSame('1|crm.lead|1|1', $site->value("SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(action), '|', SUM(delivered_at IS NOT NULL), '|', (SELECT connected_at IS NOT NULL FROM ka_connectors WHERE service = 'raynet')) FROM ka_connector_queue WHERE id > ?", [$first]), 'enquiries: with the tick only that destination gets the application; the key saved before stays');
        $crm = $this->fakeLogContents('crm.log');
        $this->assertLogHas($crm, '"crm":"raynet"§petra@example.cz§cv.pdf', 'enquiries: the application reached Raynet with the CV\'s name');
        $this->assertDoesNotMatchRegularExpression('~storage/attachments|[0-9]{4}/[0-9]{2}/[a-f0-9]{24}\.pdf~', $crm, 'enquiries: never the file or its path');
    }

    #[Depends('testJobApplicationsGoOnlyWhereTheAdministratorTickedThemAndWithoutTheCv')]
    public function testAFailingCrmWaitsForARetryAndDisconnectingStopsTheSending(): void
    {
        $site = $this->site();
        touch($this->fakeLog('crm.fail'));
        $first = $this->queueMax();
        $this->submit(['p0' => 'Failing', 'p1' => 'fail@example.cz', 'p2' => '', 'p3' => 'x', 'p4' => '1']);
        $this->tick();
        $this->assertSame('3|1', $site->value("SELECT CONCAT(SUM(action = 'crm.lead' AND attempts = 1 AND next_attempt IS NOT NULL AND delivered_at IS NULL AND last_error LIKE 'HTTP 500%'), '|', SUM(action = 'sheets.append' AND delivered_at IS NOT NULL)) FROM ka_connector_queue WHERE id > ?", [$first]), 'enquiries: the CRMs answer 500 – their deliveries wait for a retry with the error, the sheet row went through');
        $this->assertSame('google:0,hubspot:1,pipedrive:1,raynet:1', $site->value("SELECT GROUP_CONCAT(CONCAT(service, ':', last_error LIKE 'HTTP 500%') ORDER BY service) FROM ka_connectors WHERE service IN ('google', 'hubspot', 'pipedrive', 'raynet')"), 'enquiries: each CRM keeps its last error for the Connections screen');
        $this->assertPage(self::CONNECTORS, 200, 'HTTP 500: The fake CRM is broken.', message: 'enquiries: Connections shows the error');

        unlink($this->fakeLog('crm.fail'));
        $site->exec('UPDATE ka_connector_queue SET next_attempt = NOW() - INTERVAL 1 DAY WHERE id > ? AND delivered_at IS NULL', [$first]);
        $this->tick();
        $this->assertSame('4|4', $site->value("SELECT CONCAT(SUM(delivered_at IS NOT NULL AND last_error = ''), '|', (SELECT SUM(last_error = '') FROM ka_connectors WHERE service IN ('google', 'hubspot', 'pipedrive', 'raynet'))) FROM ka_connector_queue WHERE id > ?", [$first]), 'enquiries: the retry delivers and clears the errors');

        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'hubspot'], self::CONNECTORS);
        $after = $this->queueMax();
        $this->submit(['p0' => 'After', 'p1' => 'after@example.cz', 'p2' => '', 'p3' => 'x', 'p4' => '1']);
        $this->assertSame('3|0|1', $site->value("SELECT CONCAT(COUNT(*), '|', SUM(payload LIKE '%\"service\":\"hubspot\"%'), '|', (SELECT secret IS NULL FROM ka_connectors WHERE service = 'hubspot')) FROM ka_connector_queue WHERE id > ?", [$after]), 'enquiries: a disconnected CRM gets nothing more, the others still do; disconnecting forgot its key');
        $this->tick();

        $connectors = $this->mcpText('list_connectors');
        $this->assertStringContainsString('raynet', $connectors, 'enquiries: Claude sees Raynet\'s status');
        $this->assertStringContainsString('pipedrive', $connectors, 'enquiries: Claude sees Pipedrive\'s status');
        foreach (['hs-token', 'pd-token', 'rn-key', 'sheet_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $connectors, 'enquiries: Claude never sees ' . $secret);
        }
    }
}
