<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Connections;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;
use Talea\Tests\Site\Support\SoftwareAuthenticator;

/** A passkey confirmation (admin.php?action=proof) counts where the password is asked: the OAuth consent (#332). */
#[Group('site')]
final class FreshProofTest extends SiteTestCase
{
    use ConnectionHelpers;
    use SoftwareAuthenticator;

    private const string PROOF = '/admin.php?action=proof';

    private static ?Http $person = null;
    private static ?\OpenSSLAsymmetricKey $key = null;
    private static string $keyId = '';

    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    /** A person with one registered passkey whose sign-in was restored from "stay signed in": signed in, but with no proof recorded in the session. */
    private function person(): Http
    {
        if (self::$person === null) {
            $site = $this->site();
            $site->signIn($site->admin());
            $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Prover', 'username' => 'prover', 'password' => $site->password, 'admin' => 1]);
            [self::$key, self::$keyId] = $this->registerKey('prover', '1b7a5c1e-2d3f-4a5b-8c9d-0e1f2a3b4c5d');
            self::$person = $site->client('prover');
            $this->assertSame(200, $this->signInWithKey(self::$person, self::$key, self::$keyId, true)->status);
            self::$person->dropCookie('talea');
        }

        return self::$person;
    }

    private function startConsent(Http $browser, string $state): Response
    {
        $browser->get('/oauth/authorize?response_type=code&client_id=' . $this->registerClient() . '&redirect_uri=' . self::REDIRECT_URI
            . '&code_challenge=' . $this->pkceChallenge() . '&code_challenge_method=S256&state=' . $state);

        return $browser->get('/admin.php?action=oauth');
    }

    /** @return array{int, array<string, mixed>} status and JSON of the confirmation */
    private function confirm(Http $browser, int $flags): array
    {
        $csrf = $browser->get('/admin.php?action=oauth')->csrf();
        $options = $browser->post(self::PROOF, ['_csrf' => $csrf, 'step' => 'options']);
        $this->assertSame(200, $options->status);
        $this->assertCount(1, $options->json()['allowCredentials'] ?? [], 'the challenge names the key of the account');
        $answer = $browser->post(self::PROOF, ['_csrf' => $csrf, 'step' => 'verify', 'answer' => $this->keyAnswer(self::$key, self::$keyId, (string) $options->json()['challenge'], $flags)]);

        return [$answer->status, (array) $answer->json()];
    }

    public function testAPasswordSignInDoesNotSkipTheConsentPassword(): void
    {
        $this->assertStringContainsString('data-passkey-confirm', $this->startConsent($this->person(), 'pw')->body, 'the consent offers the passkey next to the password');
        $site = $this->site();
        $person = $site->client('password-only');
        $site->signIn($person); // the administrator has no passkey: a sign-in with the password, which the consent does not take as proof
        $page = $this->startConsent($person, 'pw');
        $answer = $person->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1']);
        $this->assertSame('', $answer->redirect, 'a sign-in with the password alone does not replace the password here');
        $this->assertStringContainsString('The current password is not correct.', $answer->body);
    }

    public function testAKeyWithoutUserVerificationDoesNotConfirm(): void
    {
        $person = $this->person();
        $page = $this->startConsent($person, 'uv');
        [$status, $json] = $this->confirm($person, 0x01);
        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $json);
        $answer = $person->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1']);
        $this->assertSame('', $answer->redirect, 'nothing was recorded');
        $this->site()->exec("UPDATE tl_users SET failed_logins = 0, locked_until = NULL WHERE username = 'prover'");
    }

    public function testConsentAcceptsAPasskeyConfirmation(): void
    {
        $person = $this->person();
        $page = $this->startConsent($person, 'key');
        [$status, $json] = $this->confirm($person, 0x05);
        $this->assertSame([200, true], [$status, $json['ok'] ?? null]);
        $answer = $person->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1']);
        $this->assertStringContainsString('code=', $answer->redirect, 'the consent passes without the password');
    }

    public function testALockedAccountStillConfirmsWithItsPasskey(): void
    {
        $site = $this->site();
        $person = $this->person();
        $page = $this->startConsent($person, 'lock');
        $site->exec("UPDATE tl_users SET locked_until = NOW() + INTERVAL 15 MINUTE, failed_logins = 10 WHERE username = 'prover'");
        [$status, $json] = $this->confirm($person, 0x05);
        $this->assertSame([200, true], [$status, $json['ok'] ?? null], 'wrong sign-ins of strangers do not block a signature that cannot be guessed');
        $answer = $person->post('/admin.php?action=oauth', ['_csrf' => $page->csrf(), 'request' => $page->field('request'), 'allow' => '1']);
        $this->assertStringContainsString('code=', $answer->redirect);
        $site->exec("UPDATE tl_users SET locked_until = NULL, failed_logins = 0 WHERE username = 'prover'");
    }

    public function testProofEndpointWithoutPasskeys(): void
    {
        $site = $this->site();
        $site->setting('site_name', 'Proof');
        $admin = $site->admin();
        $answer = $admin->post(self::PROOF, ['_csrf' => $admin->get('/admin.php')->csrf(), 'step' => 'options']);
        $this->assertSame(400, $answer->status, 'an account without a passkey gets no challenge');
        $this->assertArrayHasKey('error', (array) $answer->json());
    }
}
