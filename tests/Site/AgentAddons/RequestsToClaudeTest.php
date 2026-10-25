<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Requests to Claude (was: section 88, 2.15) and Ask Claude on the dashboard (was: section 89, 3.1): staff ask, Claude drafts, a
 * person publishes. The tests run in order and build on each other (the staff user and the request come from the first ones).
 */
#[Group('site')]
final class RequestsToClaudeTest extends SiteTestCase
{
    use AgentHelpers;

    private static ?Http $staff = null;
    private static int $request = 0;

    private function staff(): Http
    {
        return self::$staff ?? throw new \LogicException('The staff user is created by the first test.');
    }

    public function testTheStaffUserHasTheRequestsSectionOnlyAndOpensTheForm(): void
    {
        $this->site()->exec("UPDATE tl_users SET email = 'manager-f19@example.cz' WHERE username = 'admin'");
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Reception', 'username' => 'reception', 'email' => 'reception@example.cz', 'password' => $this->site()->password, 'admin' => 0, 'manual' => 1, 'modules' => ['requests']], '/admin.php?module=users&action=new');

        $this->assertSame('requests', $this->sq("SELECT GROUP_CONCAT(p.module) FROM tl_users u JOIN tl_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'reception'"), 'requests: the staff user has the Requests section only');

        self::$staff = $this->site()->client('requests');
        $this->site()->signIn(self::$staff, 'reception');
        $form = $this->assertPage('/admin.php?module=requests&action=new', 200, as: self::$staff, message: 'requests: the staff user opens the form');
        $this->assertStringContainsString('name="attachments[]"', $form->body, 'requests: the form offers attachments');
        $this->assertStringContainsString('value="page:' . $this->site()->publicId('pages', 1) . '"', $form->body, 'requests: the form offers the pages to choose from');
    }

    public function testARequestWithAnAttachmentIsSavedAndTheAdministratorIsNotified(): void
    {
        $pdf = $this->site()->workDir('requests') . '/pricelist-f19.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $csrf = $this->staff()->get('/admin.php?module=requests&action=new')->csrf();
        $response = $this->staff()->upload('/admin.php?module=requests&action=save', ['_csrf' => $csrf, 'title' => 'New price list for the Services page', 'text' => 'Please replace the old price list with the attached PDF.', 'about' => 'page:' . $this->site()->publicId('pages', 1), 'about_url' => ''], ['attachments[]' => $pdf]);
        self::$request = preg_match('#/([0-9a-f-]{36})#', $response->redirect, $m) ? $this->site()->internalId('requests', $m[1]) : 0;
        $this->assertGreaterThan(0, self::$request, 'the save redirects to the new request');
        $id = self::$request;

        $this->assertSame(
            'new|1|1|1',
            $this->sq("SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM tl_media WHERE image_path LIKE 'media/%pricelist-f19%' AND CONCAT(media_id, '') = CONCAT(JSON_EXTRACT(r.attachments, '\$[0]'), '')), '|', (SELECT COUNT(*) FROM tl_events WHERE type = 'request.created' AND data LIKE '%\"id\":$id,%'), '|', (SELECT COUNT(*) FROM tl_mail WHERE recipient = 'manager-f19@example.cz' AND subject LIKE '%New price list for the Services page%')) FROM tl_requests r WHERE r.id = ?", [$id]),
            'requests: saved as new, the PDF is a Media upload, the event is recorded, the administrator got the title by e-mail',
        );
        $list = $this->assertPage('/admin.php?module=requests', 200, 'New price list for the Services page', as: self::$staff, message: 'requests: the list opens with the new request first');
        $this->assertStringContainsString('name="from" value="requests"', $list->body, '#113: the Ask Claude box stands above the requests');
    }

    public function testClaudeReadsAnswersAndFinishesTheRequest(): void
    {
        $base = $this->site()->base;
        $id = self::$request;

        $list = $this->mcpText('list_requests');
        $this->assertStringContainsString('"id":"' . $this->site()->publicId('requests', $id) . '",', $list, 'requests: list_requests returns the request');
        $this->assertStringContainsString("\"url\":\"$base/media/", $list, 'requests: list_requests returns the Media url');
        $this->assertStringContainsString('"author":"Reception"', $list, 'requests: list_requests names the author');
        $this->assertStringContainsString('"written_by_staff"', $list, 'requests: list_requests warns that staff wrote it');

        $this->site()->mcp('update_request', ['id' => $this->site()->publicId('requests', $id), 'status' => 'in_progress', 'note' => 'I am looking into it.']);
        $this->assertSame('in_progress|1', $this->sq("SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM tl_request_messages WHERE request_id = r.id AND sender = 'claude' AND text = 'I am looking into it.')) FROM tl_requests r WHERE r.id = ?", [$id]), 'requests: update_request marks it in progress with a note');

        $this->assertStringContainsString('needs a note', $this->mcpRawText('update_request', ['id' => $this->site()->publicId('requests', $id), 'status' => 'declined']), 'requests: declining without a reason is refused');

        $done = $this->mcpText('update_request', ['id' => $this->site()->publicId('requests', $id), 'status' => 'done', 'note' => 'The price list is in the draft of the Services page.', 'links' => [['label' => 'Services – draft', 'url' => "$base/services"], ['url' => 'javascript:alert(1)']]]);
        $this->assertStringContainsString('"requester_notified":true', $done, 'requests: done - the result says the requester was notified');
        $this->assertSame(
            'done|1|1|1',
            $this->sq("SELECT CONCAT(r.status, '|', r.done_at IS NOT NULL, '|', (SELECT links LIKE '%$base/services%' AND links NOT LIKE '%javascript%' FROM tl_request_messages WHERE request_id = r.id ORDER BY id DESC LIMIT 1), '|', (SELECT COUNT(*) FROM tl_mail WHERE recipient = 'reception@example.cz' AND subject LIKE '%New price list for the Services page%')) FROM tl_requests r WHERE r.id = ?", [$id]),
            'requests: done with the web link only, the requester got the note by e-mail',
        );
    }

    public function testTheRequesterSeesTheAnswerAndReplies(): void
    {
        $base = $this->site()->base;
        $id = self::$request;

        $detail = $this->assertPage('/admin.php?module=requests&action=detail&id=' . $this->site()->publicId('requests', $id), 200, as: self::$staff, message: 'requests: the requester\'s detail');
        $this->assertStringContainsString('The price list is in the draft of the Services page.', $detail->body, 'requests: the detail shows Claude\'s note');
        $this->assertStringContainsString("href=\"$base/services\"", $detail->body, 'requests: the detail shows the draft link');
        $this->assertStringContainsString('pricelist-f19', $detail->body, 'requests: the detail shows the attachment');

        $this->staff()->post('/admin.php?module=requests&action=reply', ['_csrf' => $detail->csrf(), 'id' => $this->site()->publicId('requests', $id), 'text' => 'Thanks, I will publish it.']);
        $this->assertStringContainsString('"from":"person","name":"Reception","text":"Thanks, I will publish it."', $this->mcpText('list_requests', ['id' => $this->site()->publicId('requests', $id)]), 'requests: the reply is in the conversation Claude reads');
        $this->assertStringNotContainsString('"id":"' . $this->site()->publicId('requests', $id) . '",', $this->mcpText('list_requests', ['status' => 'open']), 'requests: a done request is not among the open ones');
    }

    public function testADraftsOnlyConnectionAnswersRequestsButCannotPublish(): void
    {
        $id = self::$request;
        $this->site()->mcp('update_request', ['id' => $this->site()->publicId('requests', $id), 'status' => 'in_progress', 'note' => 'Reopened by a drafts connection'], $this->draftsToken());

        $this->assertSame('in_progress|1', $this->sq("SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM tl_request_messages WHERE request_id = $id AND text = 'Reopened by a drafts connection')) FROM tl_requests WHERE id = ?", [$id]), 'requests: a drafts-only connection reopens the request with a note');
        $this->assertStringContainsString('can only save drafts', $this->mcpRawText('publish_build', ['id' => 1], $this->draftsToken()), 'requests: the same drafts-only connection cannot publish');
    }

    public function testAPersonClosesTheRequestAndAnAuthorHasNoAccess(): void
    {
        $id = self::$request;
        $detail = $this->staff()->get('/admin.php?module=requests&action=detail&id=' . $this->site()->publicId('requests', $id));
        $this->staff()->post('/admin.php?module=requests&action=status', ['_csrf' => $detail->csrf(), 'id' => $this->site()->publicId('requests', $id), 'status' => 'done']);
        $this->assertSame('done', $this->sq('SELECT status FROM tl_requests WHERE id = ?', [$id]), 'requests: the person marks it done in the detail');

        // the author-level session of section 5 (JAR2): a user without the section
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Autor', 'username' => 'autor', 'password' => $this->site()->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $author = $this->site()->client('author');
        $this->site()->signIn($author, 'autor');
        $this->assertPage('/admin.php?module=requests', 403, as: $author, message: 'requests: a user without the section gets a 403');
    }

    public function testTheDashboardAskBoxForStaffSavesARequest(): void
    {
        $page = $this->staff()->get('/admin.php');
        $this->assertStringContainsString('id="ask-claude-text"', $page->body, 'ask: a staff user with only Requests gets the box');
        $this->assertStringContainsString('name="quick" value="1"', $page->body, 'ask: the box is the quick form');
        $this->assertStringNotContainsString('data-ask-claude-example', $page->body, 'ask: no examples for sections they cannot open');
        $this->assertStringContainsString('New price list for the Services page', $page->body, 'ask: the dashboard lists their requests');

        $response = $this->staff()->post('/admin.php?module=requests&action=save', ['_csrf' => $page->csrf(), 'quick' => 1, 'from' => 'dashboard', 'text' => 'Closed from 24 to 26 December. Please put it on the home page and in the footer.']);
        $this->assertSame('Closed from 24 to 26 December.|new|admin', $this->sq('SELECT CONCAT(title, \'|\', status) FROM tl_requests ORDER BY id DESC LIMIT 1') . '|' . basename($response->redirect), 'ask: the box saves a request titled by its first sentence and goes back to the dashboard');

        $this->staff()->post('/admin.php?module=requests&action=save', ['_csrf' => $page->csrf(), 'quick' => 1, 'from' => 'dashboard', 'text' => '   ']);
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM tl_requests WHERE title = ''"), 'ask: an empty box saves nothing');
    }

    public function testTheDashboardAskBoxForTheAdministratorHasExamples(): void
    {
        $page = $this->site()->admin()->get('/admin.php');
        $this->assertStringContainsString('data-ask-claude-example="triage"', $page->body, 'ask: the administrator gets examples');
        $this->assertStringContainsString('data-ask-claude-copy', $page->body, 'ask: the copy for the Claude app');
        $this->assertStringContainsString('ask-claude-when', $page->body, 'ask: whether a scheduled run picks requests up');

        $this->site()->exec("UPDATE tl_users SET email = '' WHERE username = 'admin'");
    }

    /** #154: the status chip on every screen, the panel in the AI settings, the messages, the 48-hour highlight and get_health. */
    public function testTheClaudeConnectionStatus(): void
    {
        $site = $this->site();
        $site->exec("UPDATE tl_requests SET status = 'done' WHERE status = 'new'");
        $site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'); // a served call: the connection is active
        $this->assertPage('/admin.php', 200, ['class="claude-chip status status-live"', 'data-claude-state="active"', 'Claude connected'], message: '#154: the chip says Claude is connected');
        $this->assertPage('/admin.php?module=claude_settings', 200, ['id="claude-status-heading"', 'Claude is connected', 'picked up only when someone asks Claude'], message: '#154: the AI settings open with the status panel');
        $this->assertPage('/admin.php?module=requests', 200, ['data-claude-state="active"', 'Requests are picked up only when someone asks Claude'], as: $this->staff(), message: '#154: staff with Ask AI see the chip and how requests are picked up');

        $csrf = $this->staff()->get('/admin.php')->csrf();
        $this->staff()->post('/admin.php?module=requests&action=save', ['_csrf' => $csrf, 'quick' => 1, 'from' => 'dashboard', 'text' => 'Add the winter opening hours to the contact page.']);
        $this->assertStringContainsString('Claude picks it up the next time someone asks it', $this->staff()->get('/admin.php')->body, '#154: the message says when Claude sees the request');
        $site->exec("UPDATE tl_requests SET created_at = ? WHERE status = 'new'", [date('Y-m-d H:i:s', time() - 50 * 3600)]);
        $this->assertPageLacks('/admin.php?module=requests', 'waiting over 48 hours', as: $this->staff(), message: '#154: a request is not highlighted while it is picked up when someone asks');

        // a scheduled run of the task "requests" that missed its last run: nothing picks the requests up
        $site->exec("INSERT INTO tl_agent_schedules (name, task, text, cadence, day, time, active, next_due, created_at) VALUES ('Requests', 'requests', '', 'daily', 1, '07:00', TRUE, ?, NOW())", [date('Y-m-d H:i:s', time() + 86400)]);
        $site->exec("INSERT INTO tl_agent_runs (schedule_id, due_at, finished_at, status) SELECT MAX(id), NOW(), NOW(), 'missed' FROM tl_agent_schedules");
        $this->assertPage('/admin.php', 200, ['class="claude-chip status status-hidden"', 'Claude: requests wait'], message: '#154: the chip says requests wait when the scheduled run stopped');
        $this->assertPage('/admin.php?module=requests', 200, ['waiting over 48 hours – nothing picks it up', 'The scheduled run that picks up requests has stopped'], as: $this->staff(), message: '#154: a new request waiting over 48 hours with nothing to pick it up is highlighted');
        $health = $this->mcpData('get_health');
        $this->assertSame(['active', 'stalled'], [$health['claude_connection']['state'] ?? '', $health['claude_connection']['pickup'] ?? ''], '#154: get_health returns claude_connection');
        $this->assertContains('Claude connection', array_column($health['problems'] ?? [], 'check'), '#154: System status warns when requests wait and nothing picks them up');
        $site->exec("DELETE FROM tl_agent_schedules WHERE task = 'requests'");

        // the only connection belongs to an administrator without two-factor sign-in, which the site now requires of administrators
        $site->setting('require_2fa', 'admins');
        $this->assertPage('/admin.php?module=requests', 200, ['data-claude-state="none"', 'Claude not connected', 'ask-claude-disconnected'], as: $this->staff(), message: '#154: a connection that cannot call is no connection');
        $site->setting('require_2fa', '');
    }

    public function testWithoutAClaudeConnectionHomeHasNoAskBox(): void
    {
        $this->site()->exec('DELETE FROM tl_api_tokens');
        $this->assertStringNotContainsString('id="ask-claude-text"', $this->site()->admin()->get('/admin.php')->body, 'ask: Home has no box while Claude is not connected');
        $this->assertStringContainsString('ask-claude-disconnected', $this->staff()->get('/admin.php?module=requests')->body, 'ask: above the requests the box stays and says Claude is not connected');
    }
}
