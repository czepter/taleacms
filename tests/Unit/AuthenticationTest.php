<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Totp;
use Talea\Tests\Support\FakeSettings;

final class AuthenticationTest extends TestCase
{
    /** TOTP (RFC 6238, secret "12345678901234567890") */
    public function testTotp(): void
    {
        $totpSeed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($totpSeed, intdiv(59, 30)), 'TOTP: vektor T=59');
        $this->assertSame('081804', Totp::code($totpSeed, intdiv(1111111109, 30)), 'TOTP: vektor T=1111111109');
        $this->assertSame('279037', Totp::code($totpSeed, intdiv(2000000000, 30)), 'TOTP: vektor T=2000000000');
        $this->assertTrue(Totp::verify($totpSeed, '287082', 59), 'TOTP: a valid code passes');
        $this->assertTrue(Totp::verify($totpSeed, '287082', 59 + 30), 'TOTP: the neighbouring window passes');
        $this->assertFalse(Totp::verify($totpSeed, '287082', 59 + 300), 'TOTP: an old code fails');
        $this->assertFalse(Totp::verify($totpSeed, 'abcdef', 59), 'TOTP: nesmysl neprojde');
        $this->assertSame([1, 1, null], [Totp::step($totpSeed, '287082', 59), Totp::step($totpSeed, '287 082', 59 + 30), Totp::step($totpSeed, '287082', 59 + 300)], 'TOTP: step() names the step of the code, which the sign-in spends');
        $this->assertSame(32, strlen(Totp::newSecret()), 'TOTP: a new secret has 160 bits');
    }

    /** passkeys (WebAuthn): a software authenticator against the verification core */
    public function testPasskeys(): void
    {
        $pkRp = 'redakce.example'; $pkOrigin = 'https://redakce.example';
        $pkKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pkDescription = openssl_pkey_get_details($pkKey);
        $pkDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkDescription['key']));
        $pkX = str_pad($pkDescription['ec']['x'], 32, "\0", STR_PAD_LEFT); $pkY = str_pad($pkDescription['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $pkCose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $pkX . "\x22\x58\x20" . $pkY;
        $pkId = random_bytes(20);
        $pkClient = static fn (string $type, string $challenge, string $origin): string => \Talea\Core\Passkey::b64((string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES));
        $pkRegData = static fn (string $rp, int $flags = 0x45): string => hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($pkId)) . $pkId . $pkCose;
        $pkChallenge = \Talea\Core\Passkey::challenge();
        $pkReg = ['clientDataJSON' => $pkClient('webauthn.create', $pkChallenge, $pkOrigin), 'authenticatorData' => \Talea\Core\Passkey::b64($pkRegData($pkRp)), 'publicKey' => \Talea\Core\Passkey::b64($pkDer), 'publicKeyAlgorithm' => -7];
        $pkSaved = \Talea\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, $pkOrigin, $pkRp);
        $this->assertSame(\Talea\Core\Passkey::b64($pkId), $pkSaved['id'], 'Passkey: registration returns the key id');
        $this->assertTrue(str_contains($pkSaved['key'], 'BEGIN PUBLIC KEY'), 'Passkey: registration returns the public key in PEM');
        $pkRejects = static function (callable $f): bool { try { $f(); return false; } catch (\RuntimeException) { return true; } };
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifyRegistration($pkReg, \Talea\Core\Passkey::challenge(), $pkOrigin, $pkRp)), 'Passkey: registration with a foreign challenge fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, 'https://podvrh.example', $pkRp)), 'Passkey: registration from another origin fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, $pkOrigin, 'jina.example')), 'Passkey: registration for another domain fails');
        $pkForeign = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifyRegistration(['publicKey' => \Talea\Core\Passkey::b64(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkForeign['key'])))] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), 'Passkey: a planted public key fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifyRegistration(['clientDataJSON' => $pkClient('webauthn.get', $pkChallenge, $pkOrigin)] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), 'Passkey: a sign-in response cannot be used for registration');
        $pkSignIn = static function (string $challenge, int $counter, string $rp = 'redakce.example', string $origin = 'https://redakce.example', int $flags = 0x05) use ($pkKey, $pkClient): array {
            $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $counter);
            $client = $pkClient('webauthn.get', $challenge, $origin);
            openssl_sign($data . hash('sha256', \Talea\Core\Passkey::fromB64($client), true), $signature, $pkKey, OPENSSL_ALGO_SHA256);

            return ['clientDataJSON' => $client, 'authenticatorData' => \Talea\Core\Passkey::b64($data), 'signature' => \Talea\Core\Passkey::b64($signature)];
        };
        $pkV2 = \Talea\Core\Passkey::challenge();
        $this->assertSame(5, \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 4), 'Passkey: a valid sign-in returns the new counter');
        $this->assertSame(0, \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 0), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 0), 'Passkey: a synced key with a zero counter passes');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), \Talea\Core\Passkey::challenge(), $pkOrigin, $pkRp, $pkSaved['key'], 5)), 'Passkey: a replayed response (another challenge) fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), 'Passkey: a counter that does not grow fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), $pkV2, $pkOrigin, $pkRp, $pkForeign['key'], 5)), 'Passkey: a signature by another key fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example.podvrh.cz'), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), 'Passkey: a response from a forged domain fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'jina.example'), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), 'Passkey: a key of another domain fails');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example', 0x00), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), 'Passkey: without user presence it fails');
        $this->assertSame(6, \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5, true), 'Passkey: a key alone signs in when the device checked its owner');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example', 0x01), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5, true)), 'Passkey: a key alone without user verification fails');
        $pkChanged = $pkSignIn($pkV2, 6); $pkChanged['authenticatorData'] = \Talea\Core\Passkey::b64(\Talea\Core\Passkey::fromB64($pkChanged['authenticatorData']) . 'x');
        $this->assertTrue($pkRejects(fn () => \Talea\Core\Passkey::verifySignIn($pkChanged, $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), 'Passkey: altered authenticator data fail');
        $this->assertSame(['challenge', 'rp', 'user', 'pubKeyCredParams', 'timeout', 'attestation', 'authenticatorSelection', 'excludeCredentials'], array_keys(\Talea\Core\Passkey::registrationOptions('c', 'web.cz', 'Web', 'u', 'login', '', [])), 'Passkey: registration options carry the WebAuthn keys navigator.credentials.create() reads');
        $this->assertSame(['https://www.web.cz', 'http://localhost:8080', 'www.web.cz'], [\Talea\Core\Passkey::origin('https://WWW.Web.cz/'), \Talea\Core\Passkey::origin('http://localhost:8080'), \Talea\Core\Passkey::rpId('https://www.web.cz:8443/x')], 'Passkey: origin and domain from the site address');
    }

    /** 3.3.3: authentication, sessions and page passwords */
    public function testSessionsAndPagePasswords(): void
    {
        $adminCs = require TALEA_SYSTEM . '/languages/admin-cs.php';
        $adminDe = require TALEA_SYSTEM . '/languages/admin-de.php';
        $authSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Core/Auth.php');
        preg_match('/public function login\(.*?\n    }\n/s', $authSource, $loginSource);
        $this->assertSame([true, 2, false, true, true], [
            // the account's own hash is used only when the account is neither locked nor blocked
            str_contains($loginSource[0] ?? '', '$closed = $user !== null && (!empty($user[\'blocked\']) || $this->lockedFor($user));')
                && str_contains($loginSource[0] ?? '', '$user !== null && !$closed ? (string) $user[\'password\'] : self::DUMMY_HASH'),
            substr_count($loginSource[0] ?? '', 'return t('), str_contains($loginSource[0] ?? '', "t('The account is"),
            isset($adminCs[\Talea\Core\Auth::SIGN_IN_FAILED], $adminDe[\Talea\Core\Auth::SIGN_IN_FAILED]), str_contains(\Talea\Core\Auth::SIGN_IN_FAILED, 'reset your password')], '3.3.3 (N51): sign-in checks the lock and the block before the password, verifies the dummy hash for them, and answers every failure with one text');
        $this->assertSame([' space-around-1 ', 'old-trimmed-12', null, null], [
            \Talea\Core\Auth::matchingPassword(' space-around-1 ', password_hash(' space-around-1 ', PASSWORD_DEFAULT)),
            \Talea\Core\Auth::matchingPassword(' old-trimmed-12 ', password_hash('old-trimmed-12', PASSWORD_DEFAULT)),
            \Talea\Core\Auth::matchingPassword('space-around-1', password_hash(' space-around-1 ', PASSWORD_DEFAULT)),
            \Talea\Core\Auth::matchingPassword('wrong-password', password_hash('right-password', PASSWORD_DEFAULT))], '3.3.3 (N61): the typed password is checked as it is; one saved trimmed before still opens');
        $now333 = 1_800_000_000;
        $this->assertSame([true, false, false, true, 28800, 86400, true], [
            \Talea\Core\Auth::sessionValid($now333 - 3600, $now333 - 60, $now333), \Talea\Core\Auth::sessionValid($now333 - 9 * 3600, $now333 - 8 * 3600, $now333),
            \Talea\Core\Auth::sessionValid($now333 - 24 * 3600, $now333 - 60, $now333), \Talea\Core\Auth::sessionValid($now333 - 23 * 3600, $now333 - 7 * 3600, $now333),
            \Talea\Core\Auth::IDLE_LIMIT, \Talea\Core\Auth::SESSION_LIMIT,
            // the tokens of Claude connections are not sessions: the MCP server signs the user in without one
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Server.php'), '$app->auth()->signInAs($user);')], '3.3.3 (N60): a sign-in ends after 8 idle hours or 24 hours in total, however often the admin keeps it alive');
        $cloudflareSettings = FakeSettings::site(['trusted_proxy' => 'cloudflare']);
        $this->assertSame(['2001:db8:1:2::/64', '203.0.113.9', '162.158.1.1', [true, true, true, true]], [
            \Talea\Core\Antispam::visitorKey(new \Talea\Core\Request([], [], ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_CF_CONNECTING_IP' => '2001:db8:1:2:3:4:5:6']), $cloudflareSettings),
            \Talea\Core\Antispam::visitorKey(new \Talea\Core\Request([], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']), $cloudflareSettings),
            \Talea\Core\Antispam::visitorKey(new \Talea\Core\Request([], [], ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']), FakeSettings::site(['trusted_proxy' => ''])),
            array_map(fn (string $file): bool => str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/' . $file . '.php'), 'Antispam::visitorKey(') && !str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/' . $file . '.php'), "hash('sha256', 'talea|' . \$this->app->request->ip())"),
                ['Admin/Kernel', 'Admin/PasswordReset', 'Mcp/Server', 'Core/PageLock'])], '3.3.3 (N54): the sign-in, reset, MCP and page-password limits count the visitor behind Cloudflare, an IPv6 address by its /64');
        $this->assertSame([true, true], [
            (bool) preg_match('/if \(\$token === null\) \{\s+if \(!\$limited\) \{\s+\$db->insert/', (string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Server.php')),
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Server.php'), 'it never refuses a request')], '3.3.3 (N62): the MCP wrong-token count only caps the rows it writes – it never refuses a request');
        $pageLockSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Core/PageLock.php');
        $reserve = strpos($pageLockSource, "\$tries = Antispam::tally(\$pageKey, 'page-lock-all', self::WINDOW);");
        $this->assertSame([true, true, true], [
            $reserve !== false, $reserve < strpos($pageLockSource, 'password_verify('),
            strpos($pageLockSource, "Antispam::untally(\$pageKey, 'page-lock-all', self::WINDOW);") > strpos($pageLockSource, 'password_verify(')],
            'every try takes its place in the page cap before the password is checked (simultaneous guesses cannot slip under it); the right one gives it back');
        $mailSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Core/Mail.php');
        $this->assertSame([true, true, true], [
            str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Admin/PasswordReset.php'), 'Mail::later('), str_contains((string) file_get_contents(TALEA_ROOT . '/admin.php'), 'Talea\Core\Mail::afterResponse($app);'),
            str_contains($mailSource, 'WHERE mail_id = ? AND attempts = ? AND sent_at IS NULL')], '3.3.3 (N59): the reset link is queued and sent after the response; the queue claims a message before sending it');
        $accountSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Admin/Account.php');
        $this->assertSame([7, true, true, []], [
            substr_count($accountSource, "password_verify((string) (\$_POST['current_password'] ?? ''), \$user['password'])"), str_contains($accountSource, 'noticeOfNewEmail($user, $email)'),
            str_contains((string) file_get_contents(TALEA_ROOT . '/image/passkeys.js'), "current_password: password ? password.value : ''"),
            array_values(array_filter(['Enter your current password to change the e-mail address. Nothing was saved.', 'Enter your current password to add a passkey.', 'The e-mail address of your account was changed'],
                fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k])))], '3.3.3 (N56): a new e-mail, adding or removing a passkey, a personal token and two-factor enrolment need the current password; the old address hears about the change; the texts are translated');
    }
}
