<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Outbound connectors: OAuth with PKCE, encrypted credentials, the delivery log (was: section 74,). */
#[Group('site')]
final class ConnectorsTest extends SiteTestCase
{
    use ConnectorFakes;
    use McpHelpers;

    public function testGoogleSignInUsesPkceAndStoresEncryptedTokens(): void
    {
        $this->resetFakeLogs();
        $this->assertPage('/admin.php?module=connectors', 200, 'module=connectors&amp;action=callback', message: 'connectors: Administration → Connections lists Google with the redirect address');

        $this->connectFake('google');

        $this->assertStringContainsString('code_challenge_method=S256', $this->authorizeUrl, 'the sign-in asks with PKCE');
        $this->assertStringContainsString('access_type=offline', $this->authorizeUrl, 'the sign-in asks for an offline token');
        $this->assertStringContainsString('/o/oauth2/v2/auth?', $this->authorizeUrl, 'the sign-in goes to the Google authorisation address');
        $this->assertSame('owner@example.com|1|0|0', (string) $this->site()->value("SELECT CONCAT(account, '|', connected_at IS NOT NULL, '|', access_token LIKE '%access-1%', '|', secret LIKE '%test-client-secret%') FROM ka_connectors WHERE service = 'google'"),
            'connectors: Google is connected as the account from the sign-in, the tokens are encrypted');
        $this->assertStringContainsString('"has_verifier":true', $this->fakeLog('oauth'), 'connectors: the code was exchanged with the PKCE verifier');
    }

    public function testTheScreenShowsTheConnectionAndNeverTheSecret(): void
    {
        $response = $this->assertPage('/admin.php?module=connectors', 200, 'owner@example.com', message: 'connectors: the screen shows the connection and never the secret');

        $this->assertDoesNotMatchRegularExpression('/test-client-secret|access-1|refresh-1/', $response->body, 'connectors: no credential on the page');
    }

    public function testACallbackWithAForeignStateChangesNothing(): void
    {
        $this->site()->admin()->get('/admin.php?module=connectors&action=callback&code=test-code&state=0123456789abcdef0123456789abcdef');

        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_connector_log WHERE action = 'oauth.token'"), 'connectors: a callback with a state the session did not issue changes nothing');
    }

    public function testCallsAreAuthorisedAndAnExpiredTokenIsRefreshed(): void
    {
        $this->assertSame('200|Bearer access-1|', $this->connectorCall(), 'connectors: a call is authorised with the stored token');

        $this->site()->exec("UPDATE ka_connectors SET expires_at = NOW() - INTERVAL 1 DAY WHERE service = 'google'");
        $this->assertSame('200|Bearer access-2|', $this->connectorCall(), 'connectors: an expired token is refreshed with the refresh token');

        $this->assertSame('4|0', (string) $this->site()->value("SELECT CONCAT(COUNT(*), '|', SUM(error LIKE '%access%')) FROM ka_connector_log WHERE service = 'google'"), 'connectors: every call is logged, never its content');
    }

    public function testClaudeSeesTheStatusNeverACredential(): void
    {
        $answer = $this->mcpRawAnswer('list_connectors');

        $this->assertStringContainsString('owner@example.com', $answer, 'connectors: Claude sees the status');
        $this->assertDoesNotMatchRegularExpression('/access-2|refresh-1|test-client-secret/', $answer, 'connectors: never a credential');
    }

    public function testDisconnectingRevokesAndForgetsTheTokensButKeepsTheOauthApp(): void
    {
        $this->adminPost('/admin.php?module=connectors&action=disconnect', ['service' => 'google'], formPage: '/admin.php?module=connectors');

        $this->assertSame('1|1|1|1', (string) $this->site()->value("SELECT CONCAT(access_token IS NULL, '|', refresh_token IS NULL, '|', connected_at IS NULL, '|', secret IS NOT NULL) FROM ka_connectors WHERE service = 'google'"),
            'connectors: disconnecting forgets the tokens, the OAuth app stays');
        $this->assertSame(1, substr_count($this->fakeLog('oauth'), 'revoked'), 'connectors: disconnecting revokes the token at the service');
    }
}
