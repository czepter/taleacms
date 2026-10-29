<?php

declare(strict_types=1);

namespace Talea\Tests\Site\SiteMoveSecurity;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The machine addresses of the public site that no other test requests, and the order of the checks in Front\Kernel::handle()
 * (#232: tests first, so the public router of docs/specs/split-large-files.md moves code that is covered).
 */
#[Group('site')]
final class SystemAddressesTest extends SiteTestCase
{
    private const string INDEXNOW_KEY = '0123456789abcdef0123456789abcdef';

    public function testTheIndexNowKeyFileAnswersTheKeyAndNoOtherTextFile(): void
    {
        $site = $this->site();
        $site->setting('indexnow_key', self::INDEXNOW_KEY);

        $response = $site->client()->get('/' . self::INDEXNOW_KEY . '.txt');
        $this->assertSame(200, $response->status, 'the IndexNow key file is there');
        $this->assertSame(self::INDEXNOW_KEY, $response->body, 'the key file holds only the key');
        $this->assertStringStartsWith('text/plain', $response->headers['content-type'] ?? '', 'the key file is plain text');

        $this->assertSame(404, $site->client()->get('/ffffffffffffffffffffffffffffffff.txt')->status, 'another key-like .txt is not found');
        $site->setting('indexnow_key', '');
        $this->assertSame(404, $site->client()->get('/' . self::INDEXNOW_KEY . '.txt')->status, 'without a key there is no key file');
    }

    public function testConsentIsLoggedOnlyWithAValidIdAndOnlyKnownCategories(): void
    {
        $site = $this->site();
        $site->setting('cookies_log', '1');
        $visitor = $site->client('consent');
        $id = bin2hex(random_bytes(16));

        $response = $visitor->post('/consent', ['id' => $id, 'category' => 'analytics,evil,marketing']);
        $this->assertSame(204, $response->status, 'POST /consent answers 204 without a body');
        $this->assertSame('', $response->body);
        $this->assertSame('analytics,marketing', (string) $site->value('SELECT categories FROM tl_consents WHERE visitor_token = ?', [$id]), 'the consent is logged with the known categories only');

        $none = bin2hex(random_bytes(16));
        $visitor->post('/consent', ['id' => $none, 'category' => '']);
        $this->assertSame('none', (string) $site->value('SELECT categories FROM tl_consents WHERE visitor_token = ?', [$none]), 'a refusal of everything is logged as none');

        $this->assertSame(204, $visitor->post('/consent', ['id' => 'not-a-token', 'category' => 'analytics'])->status, 'a bad id still gets 204');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_consents WHERE visitor_token = 'not-a-token'"), 'a bad id is not logged');

        $site->setting('cookies_log', '0');
        $off = bin2hex(random_bytes(16));
        $this->assertSame(204, $visitor->post('/consent', ['id' => $off, 'category' => 'analytics'])->status, 'with logging off /consent still answers 204');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_consents WHERE visitor_token = ?', [$off]), 'with logging off nothing is logged');
        $site->setting('cookies_log', '1');

        $this->assertSame(404, $visitor->get('/consent')->status, 'GET /consent is not an address');
    }

    public function testStatusJsonNeedsTheHealthToken(): void
    {
        $site = $this->site();
        $site->setting('health_token', '');
        $closed = $site->client()->get('/status.json?token=');
        $this->assertSame(403, $closed->status, 'without a health token /status.json is closed');
        $this->assertSame(['error' => 'Invalid token.'], $closed->json());

        $site->setting('health_token', 'health-232');
        $this->assertSame(403, $site->client()->get('/status.json?token=wrong')->status, 'a wrong token is refused');
        $this->assertSame(403, $site->client()->get('/status.json')->status, 'no token is refused');

        $response = $site->client()->get('/status.json?token=health-232');
        $this->assertSame(200, $response->status, 'the right token opens /status.json');
        $this->assertStringStartsWith('application/json', $response->headers['content-type'] ?? '');
        $status = (array) $response->json();
        $this->assertContains($status['status'] ?? null, ['ok', 'warning', 'error'], 'the summary is ok, warning or error');
        $this->assertNotSame('', (string) ($status['version'] ?? ''), 'the version is reported');
        $this->assertNotSame('', (string) ($status['time'] ?? ''), 'the time is reported');
        $this->assertIsArray($status['checks'] ?? null, 'the checks are listed');
        $this->assertNotSame([], $status['checks']);
    }

    public function testAPageCannotTakeASystemAddress(): void
    {
        $site = $this->site();
        foreach (['health', 'robots'] as $slug) {
            $response = $this->adminPost('/admin.php?module=pages&action=save', ['title' => 'System ' . $slug, 'slug' => $slug, 'visible' => 1, 'text' => '<p>Page 232</p>'], '/admin.php?module=pages');
            $this->assertStringContainsString('This URL is used by the system, choose another one.', $response->body, "the editor refuses the address $slug");
        }
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug IN ('health', 'robots')"), 'no page took a system address in the editor');

        $text = $site->mcpText('create_page', ['title' => 'System tasks', 'slug' => 'tasks', 'visible' => true]);
        $this->assertStringContainsString('is used by the system', $text, 'MCP refuses a system address');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_pages WHERE slug = 'tasks'"), 'no page took a system address over MCP');

        // a row that has one anyway (an old import, a direct database edit): the system address still answers first
        foreach (['health', 'tasks'] as $slug) {
            $site->mcp('create_page', ['title' => 'Taken ' . $slug, 'slug' => 'taken-' . $slug . '-232', 'content' => '<p>Page took ' . $slug . '</p>', 'visible' => true]);
            $this->assertSame(1, $site->exec('UPDATE tl_pages SET slug = ? WHERE slug = ?', [$slug, 'taken-' . $slug . '-232']), "a page row with the slug $slug");
        }
        $site->clearPageCache();
        $health = $site->client()->get('/health');
        $this->assertSame("ok\n", $health->body, '/health still answers the probe, not the page');
        $tasks = $site->client()->get('/tasks');
        $this->assertSame(403, $tasks->status, '/tasks still asks for its token, not the page');
        $this->assertStringNotContainsString('Page took', $tasks->body);
        $site->exec("DELETE FROM tl_pages WHERE slug IN ('health', 'tasks')");
    }

    public function testMaintenanceModeShowsVisitorsANoticeAndLetsClaudeAndSignedInPeopleThrough(): void
    {
        $site = $this->site();
        $site->setting('maintenance_text', 'Back soon, test 232.');
        $site->setting('maintenance', '1');
        try {
            $visitor = $site->client('maintenance-visitor');
            foreach (['/', '/services', '/news'] as $path) {
                $response = $visitor->get($path);
                $this->assertSame(503, $response->status, "a visitor gets 503 on $path");
                $this->assertSame('3600', $response->headers['retry-after'] ?? '', "with Retry-After on $path");
                $this->assertStringContainsString('Back soon, test 232.', $response->body, "and the maintenance text on $path");
            }

            $this->assertPage('/', 200, 'Test Company', message: 'a signed-in administrator sees the site');
            $this->assertPageLacks('/', 'Back soon, test 232.', message: 'without the maintenance notice');

            $answer = $site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
            $this->assertNotSame([], $answer['result']['tools'] ?? [], 'Claude (/mcp) still gets through');
        } finally {
            $site->setting('maintenance', '0');
        }
        $this->assertSame(200, $site->client('after-maintenance')->get('/')->status, 'after maintenance visitors see the site again');
    }
}
