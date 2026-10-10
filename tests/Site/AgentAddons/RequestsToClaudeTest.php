<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\SiteTestCase;
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
        $this->site()->exec("UPDATE ka_users SET email = 'manager-f19@example.cz' WHERE username = 'admin'");
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Reception', 'username' => 'reception', 'email' => 'reception@example.cz', 'password' => $this->site()->password, 'admin' => 0, 'manual' => 1, 'modules' => ['requests']], '/admin.php?module=users&action=new');

        $this->assertSame('requests', $this->sq("SELECT GROUP_CONCAT(p.module) FROM ka_users u JOIN ka_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'reception'"), 'requests: the staff user has the Requests section only');

        self::$staff = $this->site()->client('requests');
        $this->site()->signIn(self::$staff, 'reception');
        $form = $this->assertPage('/admin.php?module=requests&action=new', 200, as: self::$staff, message: 'requests: the staff user opens the form');
        $this->assertStringContainsString('name="attachments[]"', $form->body, 'requests: the form offers attachments');
        $this->assertStringContainsString('value="page:1"', $form->body, 'requests: the form offers the pages to choose from');
    }

    public function testARequestWithAnAttachmentIsSavedAndTheAdministratorIsNotified(): void
    {
        $pdf = $this->site()->workDir('requests') . '/pricelist-f19.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $csrf = $this->staff()->get('/admin.php?module=requests&action=new')->csrf();
        $response = $this->staff()->upload('/admin.php?module=requests&action=save', ['_csrf' => $csrf, 'title' => 'New price list for the Services page', 'text' => 'Please replace the old price list with the attached PDF.', 'about' => 'page:1', 'about_url' => ''], ['attachments[]' => $pdf]);
        self::$request = preg_match('/id=(\d+)/', $response->redirect, $m) ? (int) $m[1] : 0;
        $this->assertGreaterThan(0, self::$request, 'the save redirects to the new request');
        $id = self::$request;

        $this->assertSame(
            'new|1|1|1',
            $this->sq("SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_media WHERE image_path LIKE 'media/%pricelist-f19%' AND media_id = JSON_EXTRACT(r.attachments, '\$[0]')), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'request.created' AND data LIKE '%\"id\":$id,%'), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'manager-f19@example.cz' AND subject LIKE '%New price list for the Services page%')) FROM ka_requests r WHERE r.id = ?", [$id]),
            'requests: saved as new, the PDF is a Media upload, the event is recorded, the administrator got the title by e-mail',
        );
        $this->assertPage('/admin.php?module=requests', 200, 'New price list for the Services page', as: self::$staff, message: 'requests: the list opens with the new request first');
    }

    public function testClaudeReadsAnswersAndFinishesTheRequest(): void
    {
        $base = $this->site()->base;
        $id = self::$request;

        $list = $this->mcpText('list_requests');
        $this->assertStringContainsString("\"id\":$id,", $list, 'requests: list_requests returns the request');
        $this->assertStringContainsString("\"url\":\"$base/media/", $list, 'requests: list_requests returns the Media url');
        $this->assertStringContainsString('"author":"Reception"', $list, 'requests: list_requests names the author');
        $this->assertStringContainsString('"written_by_staff"', $list, 'requests: list_requests warns that staff wrote it');

        $this->site()->mcp('update_request', ['id' => $id, 'status' => 'in_progress', 'note' => 'I am looking into it.']);
        $this->assertSame('in_progress|1', $this->sq("SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = r.id AND sender = 'claude' AND text = 'I am looking into it.')) FROM ka_requests r WHERE r.id = ?", [$id]), 'requests: update_request marks it in progress with a note');

        $this->assertStringContainsString('needs a note', $this->mcpRawText('update_request', ['id' => $id, 'status' => 'declined']), 'requests: declining without a reason is refused');

        $done = $this->mcpText('update_request', ['id' => $id, 'status' => 'done', 'note' => 'The price list is in the draft of the Services page.', 'links' => [['label' => 'Services – draft', 'url' => "$base/services"], ['url' => 'javascript:alert(1)']]]);
        $this->assertStringContainsString('"requester_notified":true', $done, 'requests: done - the result says the requester was notified');
        $this->assertSame(
            'done|1|1|1',
            $this->sq("SELECT CONCAT(r.status, '|', r.done_at IS NOT NULL, '|', (SELECT links LIKE '%$base/services%' AND links NOT LIKE '%javascript%' FROM ka_request_messages WHERE request_id = r.id ORDER BY id DESC LIMIT 1), '|', (SELECT COUNT(*) FROM ka_mail WHERE recipient = 'reception@example.cz' AND subject LIKE '%New price list for the Services page%')) FROM ka_requests r WHERE r.id = ?", [$id]),
            'requests: done with the web link only, the requester got the note by e-mail',
        );
    }

    public function testTheRequesterSeesTheAnswerAndReplies(): void
    {
        $base = $this->site()->base;
        $id = self::$request;

        $detail = $this->assertPage('/admin.php?module=requests&action=detail&id=' . $id, 200, as: self::$staff, message: 'requests: the requester\'s detail');
        $this->assertStringContainsString('The price list is in the draft of the Services page.', $detail->body, 'requests: the detail shows Claude\'s note');
        $this->assertStringContainsString("href=\"$base/services\"", $detail->body, 'requests: the detail shows the draft link');
        $this->assertStringContainsString('pricelist-f19', $detail->body, 'requests: the detail shows the attachment');

        $this->staff()->post('/admin.php?module=requests&action=reply', ['_csrf' => $detail->csrf(), 'id' => $id, 'text' => 'Thanks, I will publish it.']);
        $this->assertStringContainsString('"from":"person","name":"Reception","text":"Thanks, I will publish it."', $this->mcpText('list_requests', ['id' => $id]), 'requests: the reply is in the conversation Claude reads');
        $this->assertStringNotContainsString("\"id\":$id,", $this->mcpText('list_requests', ['status' => 'open']), 'requests: a done request is not among the open ones');
    }

    public function testADraftsOnlyConnectionAnswersRequestsButCannotPublish(): void
    {
        $id = self::$request;
        $this->site()->mcp('update_request', ['id' => $id, 'status' => 'in_progress', 'note' => 'Reopened by a drafts connection'], $this->draftsToken());

        $this->assertSame('in_progress|1', $this->sq("SELECT CONCAT(status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = $id AND text = 'Reopened by a drafts connection')) FROM ka_requests WHERE id = ?", [$id]), 'requests: a drafts-only connection reopens the request with a note');
        $this->assertStringContainsString('can only save drafts', $this->mcpRawText('publish_build', ['id' => 1], $this->draftsToken()), 'requests: the same drafts-only connection cannot publish');
    }

    public function testAPersonClosesTheRequestAndAnAuthorHasNoAccess(): void
    {
        $id = self::$request;
        $detail = $this->staff()->get('/admin.php?module=requests&action=detail&id=' . $id);
        $this->staff()->post('/admin.php?module=requests&action=status', ['_csrf' => $detail->csrf(), 'id' => $id, 'status' => 'done']);
        $this->assertSame('done', $this->sq('SELECT status FROM ka_requests WHERE id = ?', [$id]), 'requests: the person marks it done in the detail');

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
        $this->assertSame('Closed from 24 to 26 December.|new|admin.php', $this->sq('SELECT CONCAT(title, \'|\', status) FROM ka_requests ORDER BY id DESC LIMIT 1') . '|' . basename($response->redirect), 'ask: the box saves a request titled by its first sentence and goes back to the dashboard');

        $this->staff()->post('/admin.php?module=requests&action=save', ['_csrf' => $page->csrf(), 'quick' => 1, 'from' => 'dashboard', 'text' => '   ']);
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM ka_requests WHERE title = ''"), 'ask: an empty box saves nothing');
    }

    public function testTheDashboardAskBoxForTheAdministratorHasExamples(): void
    {
        $page = $this->site()->admin()->get('/admin.php');
        $this->assertStringContainsString('data-ask-claude-example="triage"', $page->body, 'ask: the administrator gets examples');
        $this->assertStringContainsString('data-ask-claude-copy', $page->body, 'ask: the copy for the Claude app');
        $this->assertStringContainsString('ask-claude-when', $page->body, 'ask: whether a scheduled run picks requests up');

        $this->site()->exec("UPDATE ka_users SET email = '' WHERE username = 'admin'");
    }
}
