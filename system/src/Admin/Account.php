<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Passkey;
use Kaleta\Core\Response;
use Kaleta\Core\Extensions;
use Kaleta\Core\Totp;

/**
 * My account: own name and e-mail, password change, two-factor sign-in. Available to every signed-in user.
 */
final class Account
{
    /** How long a new personal token is valid, in days (0 = no expiry); the default is a year (2.8). */
    public const array TOKEN_LIFETIMES = [30, 90, 365, 0];

    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(): Response
    {
        $app = $this->kernel->app;
        $r = $app->request;
        $db = $app->db();
        $user = $app->auth()->user();
        $data = ['backupCodes' => [], 'newSecret' => '', 'newToken' => ''];

        if ($r->isPost()) {
            $message = null;
            switch ($r->postInt('smaz_token') > 0 ? 'token_smaz' : ($r->post('odpojit_klient') !== '' ? 'aplikace_odpojit' : $r->post('co'))) {
                case 'profil':
                    if ($r->post('email') !== '' && filter_var($r->post('email'), FILTER_VALIDATE_EMAIL) === false) {
                        $message = ['error', 'The e-mail address is not valid.'];
                        break;
                    }
                    $email = mb_substr($r->post('email'), 0, 190);
                    $emailChanged = $email !== (string) $user['email'];
                    // the e-mail is where a password reset goes: changing it needs the current password, like a new password
                    // does – a stolen session alone must not take the account over (3.3.3, N56)
                    if ($emailChanged && !password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                        $message = ['error', 'Enter your current password to change the e-mail address. Nothing was saved.'];
                        break;
                    }
                    $db->update('users', ['name' => mb_substr($r->post('jmeno'), 0, 100), 'email' => $email, 'url' => mb_substr($r->post('url'), 0, 255), 'position' => mb_substr($r->post('position'), 0, 100), 'photo' => mb_substr($r->post('photo'), 0, 255), 'bio' => mb_substr($r->post('bio'), 0, 1200),
                        // admin language, Czech explicitly too – an empty value would mean the site language
                        'language' => isset(\Kaleta\Core\Language::ADMIN_LANGUAGES[$r->post('language')]) ? $r->post('language') : '',
                        // form of address in the German administration: '' = formal
                        'register' => $r->post('register') === 'informal' ? 'informal' : ''], ['user_id' => $user['user_id']]);
                    if ($emailChanged) {
                        ChangeLog::write($app, 'ucet', 'změna e-mailu');
                        $this->noticeOfNewEmail($user, $email);
                    }
                    $message = ['ok', 'Details saved.'];
                    break;
                case 'heslo':
                    $newItems = (string) ($_POST['nove'] ?? '');
                    $message = match (true) {
                        !password_verify((string) ($_POST['soucasne'] ?? ''), $user['password']) => ['error', 'The current password is not correct.'],
                        mb_strlen($newItems) < 10 => ['error', 'The new password must be at least 10 characters long.'],
                        $newItems !== (string) ($_POST['nove2'] ?? '') => ['error', 'The new passwords do not match.'],
                        default => null,
                    };
                    if ($message === null) {
                        $newHash = password_hash($newItems, PASSWORD_DEFAULT);
                        $db->update('users', ['password' => $newHash], ['user_id' => $user['user_id']]);
                        $app->auth()->refreshAfterPasswordChange($newHash); // this ends the other sign-ins of this account
                        $revoked = $r->postBool('zrusit_tokeny') ? $db->delete('api_tokens', ['user_id' => $user['user_id']]) : 0;
                        ChangeLog::write($app, 'ucet', 'změna hesla' . ($revoked > 0 ? ', zrušeny tokeny napojení (' . $revoked . ')' : ''));
                        $message = ['ok', $revoked > 0 ? 'The password has been changed, other sign-ins ended and connection tokens revoked.' : 'The password has been changed and other sign-ins of this account have been ended.'];
                    }
                    break;
                case 'token_novy':
                    if (!Extensions::isEnabled($app->settings(), 'claude')) {
                        break;
                    }
                    if ($app->auth()->isMissingRequired2fa($app->settings())) {
                        $message = ['error', 'The website requires two-step sign-in – you can create a token once you turn it on.'];
                        break;
                    }
                    $token = 'kaleta_' . bin2hex(random_bytes(24));
                    $access = \Kaleta\Front\OAuth::access($r->post('access') ?: 'full');
                    // a token with an expiry ends by itself (2.8); one without works until it is revoked and System status reports it
                    $days = in_array($r->postInt('platnost', 365), self::TOKEN_LIFETIMES, true) ? $r->postInt('platnost', 365) : 365;
                    $db->insert('api_tokens', ['user_id' => $user['user_id'], 'name' => mb_substr($r->post('nazev') ?: 'Claude', 0, 100), 'access' => $access, 'token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s'),
                        'expires_at' => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null]);
                    ChangeLog::write($app, 'ucet', 'claude_token', $access . ($days > 0 ? ', ' . $days . ' days' : ', no expiry'));
                    // the token is shown only now - hence no redirect
                    return $this->page(['newToken' => $token] + $data);
                case 'token_smaz':
                    $db->delete('api_tokens', ['token_id' => $r->postInt('smaz_token'), 'user_id' => $user['user_id']]);
                    $message = ['ok', 'Token revoked.'];
                    break;
                case 'aplikace_odpojit':
                    $db->delete('api_tokens', ['client_id' => $r->post('odpojit_klient'), 'user_id' => $user['user_id']]);
                    ChangeLog::write($app, 'ucet', 'odpojena aplikace');
                    $message = ['ok', 'The application is disconnected – it will not get into the website until you allow it again.'];
                    break;
                case 'totp_start':
                    $app->session->set('totp_nove', Totp::newSecret());
                    break;
                case 'totp_potvrd':
                    $secret = (string) $app->session->get('totp_nove', '');
                    if ($secret === '' || !Totp::verify($secret, $r->post('kod'))) {
                        $message = ['error', 'The code does not match. Check the time on your phone and try again.'];
                        break;
                    }
                    [$codes, $json] = Totp::backupCodes();
                    $db->update('users', ['totp_secret' => $secret, 'totp_backup_codes' => $json], ['user_id' => $user['user_id']]);
                    $app->session->remove('totp_nove');
                    ChangeLog::write($app, 'ucet', 'zapnuto dvoufázové přihlášení');
                    // the backup codes are shown only now - hence no redirect
                    return $this->page(['backupCodes' => $codes] + $data);
                case 'klic_moznosti':
                case 'klic_uloz':
                    return $this->key($r->post('co') === 'klic_uloz', $user);
                case 'klic_smaz':
                    $db->run('DELETE FROM {user_passkeys} WHERE passkey_id = ? AND user_id = ?', [$r->postInt('idk'), $user['user_id']]);
                    ChangeLog::write($app, 'ucet', 'odebrán přihlašovací klíč');
                    $message = ['ok', 'The passkey has been removed.'];
                    break;
                case 'totp_vypni':
                    if (!password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                        $message = ['error', 'Enter the correct password to turn it off.'];
                        break;
                    }
                    $db->update('users', ['totp_secret' => '', 'totp_backup_codes' => null], ['user_id' => $user['user_id']]);
                    $db->run('DELETE FROM {user_passkeys} WHERE user_id = ?', [$user['user_id']]); // keys replace the code from the app - without it they make no sense
                    ChangeLog::write($app, 'ucet', 'vypnuto dvoufázové přihlášení');
                    $message = ['ok', 'Two-factor sign-in is turned off.'];
                    break;
            }
            if ($message !== null) {
                $app->session->flash(...$message);

                return Response::redirect($app->url('admin.php?action=account'));
            }
        }

        return $this->page(['newSecret' => (string) $app->session->get('totp_nove', '')] + $data);
    }

    /**
     * Registration of a passkey (fingerprint, Face ID, security key) - called by the script image/klice.js.
     * A key can be added only to an account with two-factor sign-in enabled: it is a more convenient replacement of the
     * code from the app, the code and the backup codes remain as a fallback in case the device is lost.
     * The challenge is issued only to whoever types the current password (3.3.3, N56): a stolen session alone must not add
     * the attacker's own key. Saving needs that challenge, so it needs the password too.
     *
     * @param array<string, mixed> $user
     */
    private function key(bool $save, array $user): Response
    {
        $app = $this->kernel->app;
        if ((string) $user['totp_secret'] === '') {
            return Response::json(['error' => t('Turn on two-factor sign-in first.')], 400);
        }
        $url = $app->settings()->get('site_url') ?: $app->request->origin();
        if (!$save) {
            if (!password_verify((string) ($_POST['soucasne'] ?? ''), $user['password'])) {
                return Response::json(['error' => t('Enter your current password to add a passkey.')], 403);
            }
            $challenge = Passkey::challenge();
            $app->session->set('klic_registrace', $challenge);

            return Response::json(Passkey::registrationOptions(
                $challenge, Passkey::rpId($url), $app->settings()->get('site_name'),
                Passkey::b64(substr(hash('sha256', 'kaleta-klic|' . $url . '|' . $user['user_id'], true), 0, 16)),
                (string) $user['username'], (string) $user['jmeno'],
                array_map(static fn (array $k): string => (string) $k['credential_id'], $app->auth()->accountKeys((int) $user['user_id'])),
            ));
        }
        $challenge = (string) $app->session->get('klic_registrace', '');
        $app->session->remove('klic_registrace');
        try {
            $new = Passkey::verifyRegistration((array) json_decode((string) ($_POST['odpoved'] ?? ''), true), $challenge, Passkey::origin($url), Passkey::rpId($url));
        } catch (\RuntimeException $e) {
            return Response::json(['error' => t($e->getMessage())], 400);
        }
        $hash = hash('sha256', Passkey::fromB64($new['id']));
        if ($app->db()->value('SELECT passkey_id FROM {user_passkeys} WHERE credential_hash = ?', [$hash]) !== null) {
            return Response::json(['error' => t('This key is already registered.')], 400);
        }
        $name = mb_substr(trim($app->request->post('nazev')), 0, 80);
        $app->db()->insert('user_passkeys', [
            'user_id' => $user['user_id'], 'name' => $name !== '' ? $name : t('Passkey'), 'credential_hash' => $hash, 'credential_id' => $new['id'],
            'public_key' => $new['klic'], 'alg' => $new['alg'], 'sign_count' => $new['pocitadlo'], 'created_at' => date('Y-m-d H:i:s'),
        ]);
        ChangeLog::write($app, 'ucet', 'přidán přihlašovací klíč', $name);
        $app->session->flash('ok', 'The passkey has been added. Next time you sign in you can use it instead of the code from the app.');

        return Response::json(['ok' => true]);
    }

    /**
     * The old address learns that the account's e-mail changed (3.3.3, N56) – whoever took over a session cannot quietly
     * move the password reset to their own mailbox. In the account's admin language; nothing to send when it had none.
     *
     * @param array<string, mixed> $user the account before the change
     */
    private function noticeOfNewEmail(array $user, string $newEmail): void
    {
        $app = $this->kernel->app;
        $old = (string) $user['email'];
        if ($old === '') {
            return;
        }
        $site = $app->settings()->get('site_name');
        $language = (string) ($user['language'] ?? '') !== '' ? (string) $user['language'] : \Kaleta\Core\Language::defaults($app->settings());
        [$subject, $text] = \Kaleta\Core\Language::runWith($language, fn (): array => [
            t('The e-mail address of your account was changed') . ' – ' . $site,
            t('Hello,') . "\n\n" . t('the e-mail address of the account %s in the administration of %s was changed to %s.', (string) $user['username'], $site, $newEmail !== '' ? $newEmail : '–')
                . "\n\n" . t('If you did not change it, tell the administrator of the site at once – someone else may be using your account.'),
        ], 'admin-');
        \Kaleta\Core\Mail::send($app->settings(), $old, $subject, $text);
    }

    /** @param array<string, mixed> $data */
    private function page(array $data): Response
    {
        $app = $this->kernel->app;
        $user = $app->db()->one('SELECT * FROM {users} WHERE user_id = ?', [$app->auth()->id()]);

        return $this->kernel->page('My account', $app->view->render('admin/account', $data + [
            'app' => $app, 'username' => $user, 'csrf' => $app->session->csrfField(),
            'uri' => $data['newSecret'] !== '' ? Totp::uri($data['newSecret'], $user['username'], $app->settings()->get('site_name')) : '',
            'codesLeft' => count((array) json_decode((string) $user['totp_backup_codes'], true)),
            'claude' => Extensions::isEnabled($app->settings(), 'claude'),
            'keys' => $app->auth()->accountKeys((int) $user['user_id']),
            'tokens' => $app->db()->all("SELECT * FROM {api_tokens} WHERE user_id = ? AND kind = 'token' ORDER BY token_id DESC", [$user['user_id']]),
            // apps connected via OAuth (the Claude connector): one item per client, valid while it has a refresh token
            'apps' => $app->db()->all("SELECT client_id, MAX(name) AS name, MAX(access) AS access, MIN(created_at) AS created_at, MAX(used_at) AS used_at FROM {api_tokens} WHERE user_id = ? AND client_id IS NOT NULL AND expires_at > ? GROUP BY client_id ORDER BY MIN(created_at) DESC",
                [$user['user_id'], date('Y-m-d H:i:s')]),
            'mcpUrl' => $app->request->origin() . $app->url('mcp'),
        ]));
    }
}
