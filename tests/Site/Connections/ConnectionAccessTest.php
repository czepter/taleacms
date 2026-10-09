<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Connections;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.2: connection access levels, the change log, owner instructions, prompts and settings over MCP (was: section 44 of tools/test.sh).
 * The OAuth client of section 43 is recreated by the first test.
 */
#[Group('site')]
final class ConnectionAccessTest extends SiteTestCase
{
    use ConnectionHelpers;

    private static string $client = '';
    private static string $draftsOauth = '';
    private static string $readToken = '';
    private static string $draftToken = '';
    private static int $draftPage = 0;

    public function testOauthConnectionsKeepTheAccessTheOwnerChose(): void
    {
        $site = $this->site();
        self::$client = $this->registerClient();

        // section 43 left a connection approved without a choice (the consent page before 2.2)
        $this->exchangeCode(self::$client, $this->authorizationCode($site->admin(), self::$client, 'full'));
        $this->assertSame('full', $site->value('SELECT GROUP_CONCAT(DISTINCT access) FROM ka_api_tokens WHERE client_id = ?', [self::$client]),
            'an OAuth connection approved without a choice (a consent page from before 2.2) has full access');

        $tokens = $this->exchangeCode(self::$client, $this->authorizationCode($site->admin(), self::$client, 'drafts', ['access' => 'drafts']));
        $refreshed = $site->client('oauth')->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ?? '', 'client_id' => self::$client])->json();
        self::$draftsOauth = (string) ($refreshed['access_token'] ?? '');
        $this->assertSame('drafts', $site->value('SELECT access FROM ka_api_tokens WHERE token_hash = SHA2(?, 256)', [self::$draftsOauth]),
            'drafts only chosen on the consent screen stays after a token refresh');

        $list = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', self::$draftsOauth));
        $this->assertStringContainsString('"name":"save_build"', $list, 'a drafts-only connection lists the draft tools');
        $this->assertStringNotContainsString('"name":"publish_build"', $list, 'a drafts-only connection does not list publishing');
        $this->assertStringNotContainsString('"name":"update_settings"', $list, 'a drafts-only connection does not list settings');
    }

    #[Depends('testOauthConnectionsKeepTheAccessTheOwnerChose')]
    public function testPersonalTokensWithLimitedAccess(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $csrf = $admin->get('/admin.php?action=account')->csrf();
        $created = $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Claude read', 'access' => 'read']);
        self::$readToken = preg_match('/kaleta_[a-f0-9]{48}/', $created->body, $m) === 1 ? $m[0] : '';
        $created = $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Claude drafts', 'access' => 'drafts']);
        self::$draftToken = preg_match('/kaleta_[a-f0-9]{48}/', $created->body, $m) === 1 ? $m[0] : '';

        $this->assertSame('drafts,read', $site->value("SELECT GROUP_CONCAT(access ORDER BY name) FROM ka_api_tokens WHERE name IN ('Claude read', 'Claude drafts')"),
            'tokens from My account keep the chosen access');

        $answer = $site->mcp('create_page', ['title' => 'From a read-only connection'], self::$readToken);
        $this->assertStringContainsString('can only read the site', $this->answerRaw($answer), 'a read-only connection is told it can only read');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_pages WHERE title = 'From a read-only connection'"), 'a read-only connection changes nothing');

        $answer = $site->mcp('get_page', ['id' => 1], self::$readToken);
        $this->assertArrayNotHasKey('isError', $answer['result'] ?? [], 'a read-only connection reads');
        $this->assertArrayNotHasKey('error', $answer, 'a read-only connection reads (no protocol error)');
    }

    #[Depends('testPersonalTokensWithLimitedAccess')]
    public function testDraftsOnlyConnectionSavesDraftsButNeverPublishesOrChangesSettings(): void
    {
        $site = $this->site();
        self::$draftPage = $this->firstId($site->mcp('create_page', ['title' => 'Drafted by Claude', 'visible' => true], self::$draftToken));
        $this->assertGreaterThan(0, self::$draftPage, 'the page was created');
        $this->assertSame('0', (string) $site->value('SELECT visible FROM ka_pages WHERE page_id = ?', [self::$draftPage]), 'a drafts-only connection creates a page, but hidden');

        $build = ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'heading', 'content' => ['text' => 'Draft']]]]]];
        $answer = $site->mcp('save_build', ['id' => self::$draftPage, 'publish' => true, 'build' => $build], self::$draftToken);
        $this->assertStringContainsString('Publishing needs', $this->answerRaw($answer), 'publishing over a drafts-only connection is refused');
        $this->assertSame('none', $site->value("SELECT COALESCE(build_draft, 'none') FROM ka_pages WHERE page_id = ?", [self::$draftPage]),
            'publishing over a drafts-only connection is refused before anything is saved');

        $answer = $site->mcp('save_build', ['id' => self::$draftPage, 'build' => $build], self::$draftToken);
        $this->assertStringContainsString('"status":"draft', $this->answerText($answer), 'a drafts-only connection saves a draft build');

        $answer = $site->mcp('update_settings', ['settings' => ['site_name' => 'Hijacked']], self::$draftToken);
        $this->assertStringContainsString('can only save drafts', $this->answerRaw($answer), 'a drafts-only connection does not change settings');

        // 2.5.1: a drafts-only connection must not reach the administrator's browser through a draft preview
        $html = ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'custom_html', 'content' => ['code' => '<p>drafted-code</p>']]]]]];
        $site->mcp('save_build', ['id' => self::$draftPage, 'build' => $html], self::$draftToken);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_pages WHERE page_id = ? AND build_draft LIKE '%drafted-code%'", [self::$draftPage]),
            'a drafts-only connection cannot insert Custom HTML');

        $site->exec("INSERT INTO ka_newsletters (subject, intro, status, scheduled_at, created) VALUES ('Scheduled 251', 'Original intro', 'scheduled', NOW() + INTERVAL 1 DAY, NOW())");
        $newsletter = (int) $site->value("SELECT id FROM ka_newsletters WHERE subject = 'Scheduled 251'");
        $site->mcp('draft_newsletter', ['id' => $newsletter, 'intro' => 'Changed by a drafts connection'], self::$draftToken);
        $this->assertSame('Original intro', $site->value('SELECT intro FROM ka_newsletters WHERE id = ?', [$newsletter]), 'a drafts-only connection cannot change a scheduled newsletter');
        $site->exec('DELETE FROM ka_newsletters WHERE id = ?', [$newsletter]);
    }

    #[Depends('testDraftsOnlyConnectionSavesDraftsButNeverPublishesOrChangesSettings')]
    public function testWrongTokensDoNotLockOutAValidOne(): void
    {
        $site = $this->site();
        $wrong = 'kaleta_' . str_repeat('0', 48);
        for ($i = 0; $i < 21; $i++) {
            $site->mcp('get_page', ['id' => 1], $wrong);
        }
        $answer = $site->mcp('get_page', ['id' => 1]);
        $this->assertArrayNotHasKey('error', $answer, 'wrong tokens do not lock out a valid token (protocol error)');
        $this->assertArrayNotHasKey('isError', $answer['result'] ?? [], 'wrong tokens do not lock out a valid token');
        $site->exec("DELETE FROM ka_ip_checks WHERE type = 'mcp'");
    }

    #[Depends('testDraftsOnlyConnectionSavesDraftsButNeverPublishesOrChangesSettings')]
    public function testChangeLogNamesTheClaudeConnection(): void
    {
        $site = $this->site();
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) > 0 FROM ka_change_log WHERE via = 'Claude drafts' AND module = 'claude'"), 'the change log names the Claude connection');
        $text = $this->toolText('list_changes', ['by' => 'claude', 'limit' => 5]);
        $this->assertStringContainsString('"claude_connection":"Claude drafts"', $text, "list_changes tells Claude's changes and their connection");
        $this->assertPage('/admin.php?module=changelog&by=claude', 200, 'Claude: Claude drafts', message: "the change log in the admin filters Claude's changes");
    }

    #[Depends('testDraftsOnlyConnectionSavesDraftsButNeverPublishesOrChangesSettings')]
    public function testInstructionsResourcesPromptsAndProtocolVersion(): void
    {
        $site = $this->site();
        $site->mcp('update_settings', ['settings' => ['claude_instructions' => 'Always say renovation, never reconstruction.']]);

        $initialize = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}'));
        $this->assertStringContainsString('"protocolVersion":"2025-03-26"', $initialize, "initialize: the client's protocol version");
        $this->assertStringContainsString('Always say renovation', $initialize, "initialize: the owner's instructions");
        $this->assertStringContainsString('"prompts"', $initialize, 'initialize: prompts offered');

        $drafts = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}', self::$draftToken));
        $this->assertStringContainsString('"protocolVersion":"2025-06-18"', $drafts, 'initialize falls back to the newest protocol version');
        $this->assertStringContainsString('CAN ONLY SAVE DRAFTS', $drafts, 'initialize tells a drafts-only connection its limits');

        $resource = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://instructions"}}'));
        $this->assertStringContainsString('Always say renovation', $resource, 'the instructions as an MCP resource');

        $prompt = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"build_page","arguments":{"topic":"kitchens"}}}'));
        $this->assertStringContainsString('Build a new page about kitchens', $prompt, 'prompts/get fills in a ready-made task');

        $unknown = $this->answerRaw($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://nope"}}'));
        $this->assertStringContainsString('"code":-32602', $unknown, 'an unknown resource is a JSON-RPC error');
    }

    #[Depends('testInstructionsResourcesPromptsAndProtocolVersion')]
    public function testSettingsThatWereAdminOnlyBefore22(): void
    {
        $site = $this->site();
        $answer = $site->mcp('update_settings', ['settings' => ['extensions' => ['news', 'enquiries']]]);
        $this->assertStringContainsString('cannot switch itself off', $this->answerRaw($answer), 'Claude cannot switch its own connection off');

        $before = $site->settingValue('extensions');
        $extensions = array_merge(explode(',', $before), ['assistant']);
        $answer = $site->mcp('update_settings', ['settings' => ['extensions' => $extensions, 'additional_languages' => ['xx'], 'llms_txt' => '0', 'indexing' => '1']]);
        $text = $this->answerText($answer);
        $this->assertStringContainsString('Unknown language codes: xx', $text, 'unknown language codes are reported');
        $this->assertStringContainsString('"assistant"', $text, 'the extensions list is echoed');
        $this->assertSame('0', $site->settingValue('llms_txt'), 'extensions, SEO switches and languages over MCP, checked');

        $site->mcp('update_settings', ['settings' => ['extensions' => explode(',', $before), 'llms_txt' => '1']]);
        $this->assertPage('/.well-known/openid-configuration', 200, '"token_endpoint"', message: 'OAuth metadata for a site in a subfolder (openid-configuration)');
    }
}
