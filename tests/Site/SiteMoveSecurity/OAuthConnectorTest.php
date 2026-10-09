<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 43 of tools/test.sh – OAuth for the Claude connector (dynamic registration, consent, PKCE, tokens). */
#[Group('site')]
final class OAuthConnectorTest extends SiteTestCase
{
    private const string REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    private function json(string $path, string $body): \Kaleta\Tests\Site\Support\Response
    {
        return $this->site()->client()->post($path, $body, ['Content-Type: application/json']);
    }

    public function testTheConnectorFlowWithPkce(): void
    {
        $site = $this->site();
        $visitor = $site->client();
        $admin = $site->admin();
        $uri = self::REDIRECT_URI;

        $initialize = $this->json('/mcp', '{"jsonrpc":"2.0","id":1,"method":"initialize"}');
        $this->assertMatchesRegularExpression('#^Bearer resource_metadata="http://127\.0\.0\.1:[0-9]*/\.well-known/oauth-protected-resource"#i', $initialize->headers['www-authenticate'] ?? '', 'MCP without a token points to the OAuth metadata');
        $this->assertPage('/.well-known/oauth-protected-resource', 200, '"authorization_servers"', message: 'protected resource metadata');
        $this->assertPage('/.well-known/oauth-authorization-server', 200, '"code_challenge_methods_supported":["S256"]', message: 'authorization server metadata');

        $registered = $this->json('/oauth/register', json_encode(['client_name' => 'Claude', 'redirect_uris' => [$uri], 'token_endpoint_auth_method' => 'none']));
        $client = (string) ($registered->json()['client_id'] ?? '');
        $this->assertNotSame('', $client, 'dynamic client registration: ' . $registered->body);
        $this->assertSame(400, $this->json('/oauth/register', '{"redirect_uris":["http://zly.example/cb"]}')->status, 'registration refuses an http return address');

        $verifier = str_repeat('v', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertSame(400, $visitor->get("/oauth/authorize?response_type=code&client_id=$client&redirect_uri=https://zly.example/&code_challenge=$challenge&code_challenge_method=S256")->status, 'a foreign return address is not redirected to');
        // 3.3.2 (N8): a request without the code flow or PKCE is answered here, never redirected to the registered address
        $noCode = $visitor->get("/oauth/authorize?response_type=token&client_id=$client&redirect_uri=$uri");
        $noPkce = $visitor->get("/oauth/authorize?response_type=code&client_id=$client&redirect_uri=$uri&code_challenge=x");
        $this->assertSame('400 |400 ', "{$noCode->status} {$noCode->redirect}|{$noPkce->status} {$noPkce->redirect}", '3.3.2 OAuth: an error before consent is a page, not a redirect');

        $authorize = $admin->get("/oauth/authorize?response_type=code&client_id=$client&redirect_uri=$uri&code_challenge=$challenge&code_challenge_method=S256&state=xyz&scope=mcp");
        $this->assertSame(302, $authorize->status, 'signing in leads to the consent in the administration: status');
        $this->assertMatchesRegularExpression('/action=oauth$/', $authorize->redirect, 'signing in leads to the consent in the administration');

        $consent = $admin->get('/admin.php?action=oauth');
        $this->assertStringContainsString('Povolit přístup', $consent->body, 'the consent page');
        $this->assertStringContainsString("form-action 'self' https://claude.ai;", $consent->headers['content-security-policy'] ?? '', 'the consent CSP allows the return to the app (form-action)');
        $this->assertArrayNotHasKey('x-kaleta-form-action', $consent->headers, 'the internal form-action header does not leak');

        $csrf = $consent->csrf();
        $redirect = $admin->post('/admin.php?action=oauth', ['_csrf' => $csrf, 'allow' => 1])->redirect;
        $code = preg_match('/code=([a-f0-9]*)/', $redirect, $m) === 1 ? $m[1] : '';
        $this->assertTrue(str_starts_with($redirect, "$uri?code=") && str_contains($redirect, 'state=xyz'), "the consent returns the code and state to the app: $redirect");

        $token = fn (array $fields) => $visitor->post('/oauth/token', $fields);
        $this->assertSame(400, $token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $uri, 'client_id' => $client, 'code_verifier' => 'spatny-overovac-spatny-overovac-spatny-overovac'])->status, 'a wrong code_verifier (PKCE) does not pass');

        $admin->get("/oauth/authorize?response_type=code&client_id=$client&redirect_uri=$uri&code_challenge=$challenge&code_challenge_method=S256&state=abc");
        $redirect = $admin->post('/admin.php?action=oauth', ['_csrf' => $csrf, 'allow' => 1])->redirect;
        $code = preg_match('/code=([a-f0-9]*)/', $redirect, $m) === 1 ? $m[1] : '';
        $exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $uri, 'client_id' => $client, 'code_verifier' => $verifier];
        $tokens = $token($exchange)->json() ?? [];
        $access = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        $this->assertTrue($access !== '' && $refresh !== '', 'exchanging the code for tokens (PKCE)');
        $this->assertSame(400, $token($exchange)->status, 'a code can be used only once');

        $list = $this->site()->mcpRaw('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $access);
        $this->assertContains('builder_schema', array_column($list['result']['tools'] ?? [], 'name'), 'MCP with the access token from OAuth');
        $this->assertSame(401, $this->json('/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')->status, 'MCP without any token is refused');
        $viaRefresh = $visitor->post('/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ["Authorization: Bearer $refresh", 'Content-Type: application/json']);
        $this->assertSame(401, $viaRefresh->status, 'the refresh token cannot be used for MCP');

        $this->assertStringContainsString('"access_token"', $token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client])->body, 'refreshing the token');
        $this->assertSame(400, $token(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client])->status, 'the refresh token is replaced after use');
    }
}
