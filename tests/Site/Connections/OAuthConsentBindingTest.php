<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** The consent is bound to the request it was shown for, and a code or refresh token is spent exactly once. */
#[Group('site')]
final class OAuthConsentBindingTest extends SiteTestCase
{
    use ConnectionHelpers;

    private function authorizeUrl(string $client, string $state): string
    {
        return '/oauth/authorize?response_type=code&client_id=' . $client . '&redirect_uri=' . self::REDIRECT_URI
            . '&code_challenge=' . $this->pkceChallenge() . '&code_challenge_method=S256&state=' . $state;
    }

    public function testAllowOnAnEarlierConsentPageIssuesNoCodeForALaterClient(): void
    {
        $admin = $this->site()->admin();
        $clientA = $this->registerClient();
        $clientB = $this->registerClient();

        $admin->get($this->authorizeUrl($clientA, 'a'));
        $pageA = $admin->get('/admin.php?action=oauth');
        // a later top-level request of another client in the same session replaces the pending one
        $admin->get($this->authorizeUrl($clientB, 'b'));

        $stale = $admin->post('/admin.php?action=oauth', ['_csrf' => $pageA->csrf(), 'request' => $pageA->field('request'), 'allow' => '1', 'current_password' => $this->site()->password]);
        $this->assertSame(400, $stale->status, 'the consent page of the earlier request is refused');
        $this->assertSame('', $stale->redirect, 'no redirect with a code');
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_oauth_codes'), 'no code was issued');

        $pageB = $admin->get('/admin.php?action=oauth');
        $this->assertNotSame('', $pageB->field('request'));
        $this->assertNotSame($pageA->field('request'), $pageB->field('request'), 'every authorization request has its own nonce');
        $redirect = $admin->post('/admin.php?action=oauth', ['_csrf' => $pageB->csrf(), 'request' => $pageB->field('request'), 'allow' => '1', 'current_password' => $this->site()->password])->redirect;
        $this->assertStringContainsString('code=', $redirect, 'the current consent page still works');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_oauth_codes WHERE client_id = ?', [$clientB]));
    }

    public function testAConsentWithoutTheNonceIsRefused(): void
    {
        $admin = $this->site()->admin();
        $admin->get($this->authorizeUrl($this->registerClient(), 'x'));
        $page = $admin->get('/admin.php?action=oauth');
        $before = (string) $this->site()->value('SELECT COUNT(*) FROM tl_oauth_codes');
        $this->assertSame(400, $admin->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'allow' => '1'])->status);
        $this->assertSame($before, (string) $this->site()->value('SELECT COUNT(*) FROM tl_oauth_codes'));
        $admin->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '0']); // leave nothing pending
    }

    public function testConsentNeedsTheCurrentPassword(): void
    {
        $site = $this->site();
        $admin = $site->admin();
        $client = $this->registerClient();
        $admin->get($this->authorizeUrl($client, 'pw'));
        $page = $admin->get('/admin.php?action=oauth');
        $this->assertStringContainsString('name="current_password"', $page->body, 'the consent form asks for the password');
        foreach (['', 'wrong'] as $password) {
            $answer = $admin->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1', 'current_password' => $password]);
            $this->assertSame('', $answer->redirect, 'no redirect with a code');
            $this->assertStringContainsString('The current password is not correct.', $answer->body);
            $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_oauth_codes WHERE client_id = ?', [$client]), 'no code without the password');
        }
        // the pending request survived: the right password now works on the same page
        $redirect = $admin->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1', 'current_password' => $site->password])->redirect;
        $this->assertStringContainsString('code=', $redirect);
    }

    public function testACodeAndARefreshTokenAreRedeemedOnce(): void
    {
        $client = $this->registerClient();
        $code = $this->authorizationCode($this->site()->admin(), $client, 'once');
        $first = $this->exchangeCode($client, $code);
        $this->assertNotSame('', (string) ($first['access_token'] ?? ''));
        $this->assertArrayNotHasKey('access_token', $this->exchangeCode($client, $code), 'a code issues tokens once');

        $refresh = ['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token'] ?? '', 'client_id' => $client];
        $http = $this->site()->client('oauth');
        $this->assertNotSame('', (string) ($http->post('/oauth/token', $refresh)->json()['refresh_token'] ?? ''), 'the refresh token rotates');
        $this->assertArrayNotHasKey('refresh_token', (array) $http->post('/oauth/token', $refresh)->json(), 'the old refresh token is spent');
    }
}
