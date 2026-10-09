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
        $this->site()->exec("UPDATE ka_users SET email = 'spravce-f19@example.cz' WHERE username = 'admin'");
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'jmeno' => 'Recepce', 'username' => 'recepce', 'email' => 'recepce@example.cz', 'password' => $this->site()->password, 'admin' => 0, 'rucne' => 1, 'modules' => ['requests']], '/admin.php?module=users&action=new');

        $this->assertSame('requests', $this->sq("SELECT GROUP_CONCAT(p.module) FROM ka_users u JOIN ka_user_permissions p ON p.user_id = u.user_id WHERE u.username = 'recepce'"), 'requests: the staff user has the Requests section only');

        self::$staff = $this->site()->client('requests');
        $this->site()->signIn(self::$staff, 'recepce');
        $form = $this->assertPage('/admin.php?module=requests&action=new', 200, as: self::$staff, message: 'requests: the staff user opens the form');
        $this->assertStringContainsString('name="prilohy[]"', $form->body, 'requests: the form offers attachments');
        $this->assertStringContainsString('value="page:1"', $form->body, 'requests: the form offers the pages to choose from');
    }

    public function testARequestWithAnAttachmentIsSavedAndTheAdministratorIsNotified(): void
    {
        $pdf = $this->site()->workDir('requests') . '/cenik-f19.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $csrf = $this->staff()->get('/admin.php?module=requests&action=new')->csrf();
        $response = $this->staff()->upload('/admin.php?module=requests&action=save', ['_csrf' => $csrf, 'title' => 'Nový ceník na stránku Služby', 'text' => 'Prosím nahraďte starý ceník přiloženým PDF.', 'about' => 'page:1', 'about_url' => ''], ['prilohy[]' => $pdf]);
        self::$request = preg_match('/id=(\d+)/', $response->redirect, $m) ? (int) $m[1] : 0;
        $this->assertGreaterThan(0, self::$request, 'the save redirects to the new request');
        $id = self::$request;

        $this->assertSame(
            'new|1|1|1',
            $this->sq("SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_media WHERE image_path LIKE 'media/%cenik-f19%' AND ido = JSON_EXTRACT(r.attachments, '\$[0]')), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'request.created' AND data LIKE '%\"id\":$id,%'), '|', (SELECT COUNT(*) FROM ka_mail WHERE komu = 'spravce-f19@example.cz' AND predmet LIKE '%Nový ceník na stránku Služby%')) FROM ka_requests r WHERE r.id = ?", [$id]),
            'requests: saved as new, the PDF is a Media upload, the event is recorded, the administrator got the title by e-mail',
        );
        $this->assertPage('/admin.php?module=requests', 200, 'Nový ceník na stránku Služby', as: self::$staff, message: 'requests: the list opens with the new request first');
    }

    public function testClaudeReadsAnswersAndFinishesTheRequest(): void
    {
        $base = $this->site()->base;
        $id = self::$request;

        $list = $this->mcpText('list_requests');
        $this->assertStringContainsString("\"id\":$id,", $list, 'requests: list_requests returns the request');
        $this->assertStringContainsString("\"url\":\"$base/media/", $list, 'requests: list_requests returns the Media url');
        $this->assertStringContainsString('"author":"Recepce"', $list, 'requests: list_requests names the author');
        $this->assertStringContainsString('"written_by_staff"', $list, 'requests: list_requests warns that staff wrote it');

        $this->site()->mcp('update_request', ['id' => $id, 'status' => 'in_progress', 'note' => 'Dívám se na to.']);
        $this->assertSame('in_progress|1', $this->sq("SELECT CONCAT(r.status, '|', (SELECT COUNT(*) FROM ka_request_messages WHERE request_id = r.id AND sender = 'claude' AND text = 'Dívám se na to.')) FROM ka_requests r WHERE r.id = ?", [$id]), 'requests: update_request marks it in progress with a note');

        $this->assertStringContainsString('needs a note', $this->mcpRawText('update_request', ['id' => $id, 'status' => 'declined']), 'requests: declining without a reason is refused');

        $done = $this->mcpText('update_request', ['id' => $id, 'status' => 'done', 'note' => 'Ceník je v konceptu stránky Služby.', 'links' => [['label' => 'Služby – koncept', 'url' => "$base/sluzby"], ['url' => 'javascript:alert(1)']]]);
        $this->assertStringContainsString('"requester_notified":true', $done, 'requests: done - the result says the requester was notified');
        $this->assertSame(
            'done|1|1|1',
            $this->sq("SELECT CONCAT(r.status, '|', r.done_at IS NOT NULL, '|', (SELECT links LIKE '%$base/sluzby%' AND links NOT LIKE '%javascript%' FROM ka_request_messages WHERE request_id = r.id ORDER BY id DESC LIMIT 1), '|', (SELECT COUNT(*) FROM ka_mail WHERE komu = 'recepce@example.cz' AND predmet LIKE '%Nový ceník na stránku Služby%')) FROM ka_requests r WHERE r.id = ?", [$id]),
            'requests: done with the web link only, the requester got the note by e-mail',
        );
    }

    public function testTheRequesterSeesTheAnswerAndReplies(): void
    {
        $base = $this->site()->base;
        $id = self::$request;

        $detail = $this->assertPage('/admin.php?module=requests&action=detail&id=' . $id, 200, as: self::$staff, message: 'requests: the requester\'s detail');
        $this->assertStringContainsString('Ceník je v konceptu stránky Služby.', $detail->body, 'requests: the detail shows Claude\'s note');
        $this->assertStringContainsString("href=\"$base/sluzby\"", $detail->body, 'requests: the detail shows the draft link');
        $this->assertStringContainsString('cenik-f19', $detail->body, 'requests: the detail shows the attachment');

        $this->staff()->post('/admin.php?module=requests&action=reply', ['_csrf' => $detail->csrf(), 'id' => $id, 'text' => 'Díky, zveřejním to.']);
        $this->assertStringContainsString('"from":"person","name":"Recepce","text":"Díky, zveřejním to."', $this->mcpText('list_requests', ['id' => $id]), 'requests: the reply is in the conversation Claude reads');
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
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'jmeno' => 'Autor', 'username' => 'autor', 'password' => $this->site()->password, 'admin' => 0], '/admin.php?module=users&action=new');
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
        $this->assertStringContainsString('Nový ceník na stránku Služby', $page->body, 'ask: the dashboard lists their requests');

        $response = $this->staff()->post('/admin.php?module=requests&action=save', ['_csrf' => $page->csrf(), 'quick' => 1, 'from' => 'dashboard', 'text' => 'Zavřeno od 24. do 26. prosince. Dejte to prosím na úvodní stránku i do patičky.']);
        $this->assertSame('Zavřeno od 24. do 26. prosince.|new|admin.php', $this->sq('SELECT CONCAT(title, \'|\', status) FROM ka_requests ORDER BY id DESC LIMIT 1') . '|' . basename($response->redirect), 'ask: the box saves a request titled by its first sentence and goes back to the dashboard');

        $this->staff()->post('/admin.php?module=requests&action=save', ['_csrf' => $page->csrf(), 'quick' => 1, 'from' => 'dashboard', 'text' => '   ']);
        $this->assertSame('0', $this->sq("SELECT COUNT(*) FROM ka_requests WHERE title = ''"), 'ask: an empty box saves nothing');
    }

    public function testTheDashboardAskBoxForTheAdministratorHasExamples(): void
    {
        $page = $this->site()->admin()->get('/admin.php');
        $this->assertStringContainsString('data-ask-claude-example="triage"', $page->body, 'ask: the administrator gets examples');
        $this->assertStringContainsString('data-ask-claude-copy', $page->body, 'ask: the copy for the Claude app');
        $this->assertStringContainsString('ask-claude-kdy', $page->body, 'ask: whether a scheduled run picks requests up');

        $this->site()->exec("UPDATE ka_users SET email = '' WHERE username = 'admin'");
    }
}
