<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** #52: the Essentials tool set – the consent screen's choice, the runners, access and guardrails, refresh, My account. */
#[Group('site')]
final class ToolSetTest extends SiteTestCase
{
    use ConnectionHelpers;

    private static string $client = '';
    private static string $essentialsOauth = '';
    private static string $essentialsRefresh = '';
    private static string $draftsToken = '';
    private static string $readToken = '';

    /** @return list<string> the names tools/list shows for a token */
    private function listed(?string $token = null): array
    {
        $answer = $this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $token);

        return array_column($answer['result']['tools'] ?? [], 'name');
    }

    /** @param array<string, mixed> $answer */
    private function failed(array $answer): bool
    {
        return !empty($answer['result']['isError']);
    }

    public function testTheConsentScreenAsksForTheToolSetAndAConnectionWithoutAChoiceKeepsAllTools(): void
    {
        $site = $this->site();
        self::$client = $this->registerClient();
        $site->admin()->get('/oauth/authorize?response_type=code&client_id=' . self::$client . '&redirect_uri=' . self::REDIRECT_URI
            . '&code_challenge=' . $this->pkceChallenge() . '&code_challenge_method=S256&state=ask');
        $consent = $site->admin()->get('/admin.php?action=oauth')->body;
        $this->assertMatchesRegularExpression('/name="tool_set" value="essentials" checked/', $consent, 'Essentials is preselected on the consent screen');

        $all = $this->exchangeCode(self::$client, $this->authorizationCode($site->admin(), self::$client, 'nochoice', ['access' => 'full']));
        $this->assertSame('all', $site->value('SELECT tool_set FROM tl_api_tokens WHERE token_hash = SHA2(?, 256)', [$all['access_token'] ?? '']), 'a consent without a tool set (an old page) lists all tools');
        $this->assertContains('list_redirects', $this->listed((string) $all['access_token']));
    }

    #[Depends('testTheConsentScreenAsksForTheToolSetAndAConnectionWithoutAChoiceKeepsAllTools')]
    public function testAnEssentialsConnectionListsTheCoreToolsAndTheRunnersAndKeepsThemAfterARefresh(): void
    {
        $site = $this->site();
        $tokens = $this->exchangeCode(self::$client, $this->authorizationCode($site->admin(), self::$client, 'ess', ['access' => 'full', 'tool_set' => 'essentials']));
        $refreshed = $site->client('oauth')->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ?? '', 'client_id' => self::$client])->json();
        self::$essentialsOauth = (string) ($refreshed['access_token'] ?? '');
        self::$essentialsRefresh = (string) ($refreshed['refresh_token'] ?? '');
        $this->assertSame('essentials', $site->value('SELECT tool_set FROM tl_api_tokens WHERE token_hash = SHA2(?, 256)', [self::$essentialsOauth]), 'a token refresh never changes the tool set');

        $names = $this->listed(self::$essentialsOauth);
        $this->assertContains('get_page', $names);
        $this->assertContains('find_tools', $names);
        $this->assertContains('run_read_tool', $names);
        $this->assertContains('run_tool', $names);
        $this->assertContains('run_destructive_tool', $names);
        $this->assertNotContains('list_redirects', $names, 'a tool outside the essentials is reached through the runners');
        $this->assertLessThan(60 * 1024, strlen(json_encode($site->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', self::$essentialsOauth))));
    }

    #[Depends('testAnEssentialsConnectionListsTheCoreToolsAndTheRunnersAndKeepsThemAfterARefresh')]
    public function testFindToolsAndTheRunnersWorkLikeADirectCall(): void
    {
        $site = $this->site();
        $token = self::$essentialsOauth;
        $this->assertStringContainsString('"runner":"run_read_tool"', $this->toolText('find_tools', ['query' => 'redirects'], $token));

        $read = $site->mcp('run_read_tool', ['name' => 'list_redirects', 'arguments' => (object) []], $token);
        $this->assertFalse($this->failed($read), 'run_read_tool reads');

        $save = $site->mcp('run_tool', ['name' => 'save_redirect', 'arguments' => ['from' => '/old-tool-set', 'to' => '/new-tool-set', 'reason' => 'tool set test']], $token);
        $this->assertFalse($this->failed($save), 'run_tool changes: ' . $this->answerText($save));
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_change_log WHERE action = 'save_redirect' AND reason = 'tool set test'"), 'a runner call is in the change log like a direct call');

        $this->assertStringContainsString('Use run_tool for it', $this->toolText('run_read_tool', ['name' => 'save_redirect', 'arguments' => (object) []], $token), 'a runner refuses a tool of another class');
        $this->assertStringContainsString('Use run_destructive_tool for it', $this->toolText('run_tool', ['name' => 'trash_page', 'arguments' => (object) []], $token));
        $this->assertStringContainsString('Unknown tool', $this->toolText('run_tool', ['name' => 'no_such_tool'], $token));
        $this->assertStringContainsString('runs the site', $this->toolText('run_tool', ['name' => 'run_tool'], $token), 'runners do not nest');
    }

    #[Depends('testFindToolsAndTheRunnersWorkLikeADirectCall')]
    public function testARunnerNeverGivesMoreThanTheConnectionsAccess(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $csrf = $admin->get('/admin.php?action=account')->csrf();
        $created = $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Essentials drafts', 'access' => 'drafts', 'tool_set' => 'essentials']);
        self::$draftsToken = preg_match('/talea_[a-f0-9]{48}/', $created->body, $m) === 1 ? $m[0] : '';
        $created = $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Essentials read', 'access' => 'read', 'tool_set' => 'essentials']);
        self::$readToken = preg_match('/talea_[a-f0-9]{48}/', $created->body, $m) === 1 ? $m[0] : '';

        $this->assertNotContains('run_destructive_tool', $this->listed(self::$readToken), 'a read-only connection lists no destructive runner');
        $this->assertNotContains('run_tool', $this->listed(self::$readToken), 'a read-only connection lists only the read runner');
        $this->assertContains('run_read_tool', $this->listed(self::$readToken));

        $this->assertStringContainsString('can only read the site', $this->toolText('run_tool', ['name' => 'save_redirect', 'arguments' => ['from' => '/a', 'to' => '/b']], self::$readToken));
        $this->assertStringContainsString('can only save drafts', $this->toolText('run_tool', ['name' => 'save_redirect', 'arguments' => ['from' => '/a', 'to' => '/b']], self::$draftsToken));
        $this->assertStringContainsString('can only save drafts', $this->toolText('run_destructive_tool', ['name' => 'trash_page', 'arguments' => ['id' => $site->publicId('pages', 1)]], self::$draftsToken));
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_redirects WHERE from_path = '/a'"), 'nothing was saved');
    }

    #[Depends('testARunnerNeverGivesMoreThanTheConnectionsAccess')]
    public function testPersonalTokensDefaultToAllToolsAndTheToolSetIsSwitchedInMyAccount(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $csrf = $admin->get('/admin.php?action=account')->csrf();
        $created = $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'op' => 'token_new', 'name' => 'Default set']);
        $this->assertSame('all', $site->value("SELECT tool_set FROM tl_api_tokens WHERE name = 'Default set'"), 'a personal token lists all tools unless asked otherwise');

        $id = (string) $site->value("SELECT public_id FROM tl_api_tokens WHERE name = 'Default set'");
        $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'tool_set_switch' => 'essentials:token:' . $id]);
        $this->assertSame('essentials', $site->value("SELECT tool_set FROM tl_api_tokens WHERE name = 'Default set'"), 'My account switches a personal token to the essentials');

        $client = self::$client;
        $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'tool_set_switch' => 'all:app:' . $client]);
        $this->assertSame('all', $site->value('SELECT tool_set FROM tl_api_tokens WHERE client_id = ? AND token_hash = SHA2(?, 256)', [$client, self::$essentialsOauth]), 'My account switches a connected application to all tools');
        $this->assertContains('list_redirects', $this->listed(self::$essentialsOauth));
    }
}
