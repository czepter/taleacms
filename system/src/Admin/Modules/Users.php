<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Kernel;
use Talea\Admin\Module;
use Talea\Core\Auth;
use Talea\Core\Response;

/**
 * Admin users: accounts, roles (administrator, editor, news author) and optionally manual access to sections.
 */
final class Users extends Module
{
    public const string IDENT = 'users';
    public const string NAME = 'Users';
    public const string GROUP = 'Administration';
    public const string ICON = 'users';
    public const bool ADMIN_ONLY = true;
    public const string TABLE = 'users';

    protected function actionList(): Response
    {
        $authors = $this->db->all('SELECT u.*, r.name AS role_name, (SELECT COUNT(*) FROM {news} c WHERE c.author_id = u.user_id AND c.deleted_at IS NULL) AS news_count FROM {users} u LEFT JOIN {role} r ON r.role_id = u.role ORDER BY u.username');
        $modules = [];
        foreach ($this->db->all('SELECT user_id, module FROM {user_permissions}') as $r) {
            $modules[(int) $r['user_id']][] = (string) $r['module'];
        }
        foreach ($authors as &$a) {
            $a['summary'] = self::summary((int) $a['admin'], $modules[(int) $a['user_id']] ?? [], (bool) $a['blocked'], $a['auto_blocked_at']);
        }
        unset($a);

        return $this->view('list', 'Users', ['authors' => $authors]);
    }

    protected function actionNew(): Response
    {
        return $this->form(['user_id' => 0, 'public_id' => '', 'username' => '', 'name' => '', 'email' => '', 'url' => '', 'admin' => Auth::AUTHOR, 'role' => null, 'blocked' => 0]);
    }

    protected function actionEdit(): Response
    {
        $author = $this->db->one('SELECT * FROM {users} WHERE user_id = ?', [$this->idParam()]);

        return $author === null ? $this->error('User does not exist.', 404) : $this->form($author);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        if (($refusal = $this->refuseUnknownId('user_id', 'User does not exist.')) !== null) {
            return $refusal;
        }
        $id = $this->idParam('user_id');
        $isSelf = $id === $this->app->auth()->id();
        $data = [
            'username' => $r->post('username'),
            'name' => $r->post('name'),
            'email' => $r->post('email'),
            'url' => $r->post('url'),
            'admin' => array_key_exists($r->postInt('admin'), Auth::TYPES) ? $r->postInt('admin') : Auth::AUTHOR,
            'role' => null,
            'blocked' => (int) $r->postBool('blocked'),
        ];
        // custom role (value "r<id>"): the role determines both the level and the sections
        $custom = preg_match('/^r(\d+)$/', $r->post('admin'), $m) ? $this->db->one('SELECT * FROM {role} WHERE role_id = ?', [(int) $m[1]]) : null;
        if ($custom !== null) {
            $data['admin'] = (int) $custom['level'];
            $data['role'] = (int) $custom['role_id'];
        }
        if ($isSelf) {
            // an administrator must not take away their own permissions or block themselves - they would lock themselves out of the admin
            $data['admin'] = Auth::ADMIN;
            $data['role'] = null;
            $data['blocked'] = 0;
        }
        if (!$data['blocked']) {
            $data['failed_logins'] = 0;
            $data['auto_blocked_at'] = null; // unblocking by hand ends an automatic block too
        }
        // an administrator saving the account confirms it is wanted: the check of unused accounts (Core\SecurityHygiene) counts from now
        $data['confirmed_at'] = date('Y-m-d H:i:s');
        if ($r->postBool('totp_reset')) {
            // the user lost both the phone and the backup codes: the administrator disables their two-factor sign-in
            $data['totp_secret'] = '';
            $data['totp_backup_codes'] = null;
            $this->app->db()->run('DELETE FROM {user_passkeys} WHERE user_id = ?', [$id]); // passkeys depend on two-factor sign-in
        }

        $errors = [];
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $data['username'])) {
            $errors['username'] = 'Username: 2-40 characters, only letters without diacritics, digits, period, hyphen and underscore.';
        } elseif ($this->db->value('SELECT user_id FROM {users} WHERE username = ? AND user_id <> ?', [$data['username'], $id]) !== null) {
            $errors['username'] = 'Another user already has this username.';
        }
        if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'The e-mail address is not valid.';
        }
        // the password as typed, like My account, the reset and the installer (3.3.3, N61); only blanks mean "no change"
        $password = is_string($_POST['password'] ?? null) && trim($_POST['password']) !== '' ? $_POST['password'] : '';
        $invite = $id === 0 && $r->postBool('invite');
        if ($invite && $data['email'] === '') {
            $errors['email'] = 'An invitation needs an e-mail.';
        }
        if ($invite && $password === '') {
            // the invited user sets the password themselves from the link in the e-mail; until then they cannot sign in (nobody knows the random password)
            $data['password'] = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        } elseif ($password !== '' || $id === 0) {
            if (mb_strlen($password) < 10) {
                $errors['password'] = 'The password must be at least 10 characters long.';
            } else {
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
        }
        if ($errors !== []) {
            return $this->form(['user_id' => $id] + $data, $errors);
        }

        // access to sections follows from the role; a manual choice only when the administrator explicitly wants it
        $modules = match (true) {
            $data['role'] !== null => array_filter(explode(',', (string) $custom['modules'])),
            $r->postBool('manual') => array_intersect($r->postList('modules'), array_map(fn (string $c): string => $c::IDENT, Kernel::MODULES)),
            default => self::defaultModules((int) $data['admin']),
        };

        $this->db->transaction(function () use (&$id, $data, $modules): void {
            if ($id > 0) {
                $this->db->update('users', $data, ['user_id' => $id]);
                if (isset($data['password'])) {
                    \Talea\Front\OAuth::revokeConnections($this->db, $id); // a new password ends the user's Claude connections
                }
            } else {
                $id = $this->db->insert('users', $data);
            }
            $this->db->delete('user_permissions', ['user_id' => $id]);
            foreach ($modules as $ident) {
                $this->db->insert('user_permissions', ['user_id' => $id, 'module' => $ident]);
            }
        });

        if ($invite) {
            (new \Talea\Admin\PasswordReset($this->app))->sendLink(['user_id' => $id] + $data, 'invitation');

            return $this->back(t('The user has been created and the invitation sent to %s.', $data['email']));
        }

        return $this->back('User saved.');
    }

    /** The administrator sends the user a link to set a new password (valid for 3 days). */
    protected function actionPasswordLink(): Response
    {
        $user = $this->request->isPost() ? $this->db->one("SELECT * FROM {users} WHERE user_id = ? AND email <> '' AND blocked = FALSE", [$this->idParam('user_id')]) : null;
        if ($user === null) {
            return $this->back('The user has no e-mail or is blocked.', '', [], 'error');
        }
        (new \Talea\Admin\PasswordReset($this->app))->sendLink($user, 'administrator');

        return $this->back(t('The new password link has been sent to %s.', $user['email']));
    }

    /**
     * The automatic suspension blocked the account (Core\SecurityHygiene): back to normal, with a fresh confirmation so that
     * the next daily run does not block it again right away.
     */
    protected function actionReactivate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->idParam('user_id');
        $user = $this->db->one('SELECT user_id, blocked FROM {users} WHERE user_id = ?', [$id]);
        if ($user === null || !$user['blocked']) {
            return $this->back('The account is not blocked.', type: 'error');
        }
        $this->db->update('users', ['blocked' => 0, 'auto_blocked_at' => null, 'failed_logins' => 0, 'locked_until' => null, 'confirmed_at' => date('Y-m-d H:i:s')], ['user_id' => $id]);

        return $this->back('The account has been reactivated – the user can sign in again.');
    }

    /** Revokes one Claude connection of the user: a personal token (token_id) or a connected application (klient). */
    protected function actionRevokeConnection(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->idParam('user_id');
        $token = $this->idParam('token_id', 'api_tokens');
        $client = $this->request->post('client_id');
        $removed = $token > 0 ? $this->db->delete('api_tokens', ['token_id' => $token, 'user_id' => $id]) : ($client !== '' ? $this->db->delete('api_tokens', ['user_id' => $id, 'client_id' => $client]) : 0);

        return $this->back($removed > 0 ? 'The connection has been revoked – Claude can no longer sign in with it.' : 'The connection no longer exists.', 'edit', ['id' => $this->publicId($id)], $removed > 0 ? 'ok' : 'error');
    }

    /**
     * The user's permissions in one sentence - after saving, the administrator needs to see what came out of the role and
     * sections together.
     *
     * @param list<string> $modules identifiers of the modules they have access to
     * @param string|null $autoBlocked when the automatic suspension blocked the account (tl_users.blokovano_automaticky)
     */
    public static function summary(int $role, array $modules, bool $blocked = false, ?string $autoBlocked = null): string
    {
        if ($blocked && $autoBlocked !== null) {
            return t('Blocked automatically on %s – nobody had used the account for %d days. An administrator can reactivate it.', format_date($autoBlocked), \Talea\Core\SecurityHygiene::ACCOUNT_DAYS);
        }
        if ($blocked) {
            return t('The account is blocked – it cannot sign in to the administration.');
        }
        if ($role >= Auth::ADMIN) {
            return t('May do everything, including site settings and user management.');
        }
        $parts = [];
        if (!in_array('news', $modules, true)) {
            $parts[] = t('Does not write news');
        } elseif ($role >= Auth::EDITOR) {
            $parts[] = t('Writes, edits and publishes news by all authors');
        } else {
            $parts[] = t('Writes and edits their own news; an editor publishes it');
        }
        $names = [];
        foreach (Kernel::MODULES as $class) {
            if ($class::IDENT !== 'news' && !$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && $class::SHARES_PERMISSION_OF === '' && in_array($class::IDENT, $modules, true)) {
                $names[] = t($class::NAME);
            }
        }
        $sentence = implode(', ', $parts) . '.';
        if ($names !== []) {
            $sentence .= ' ' . t('Also has access to: %s.', implode(', ', $names));
        }

        return $sentence;
    }

    /**
     * Sections the role has access to when the administrator does not set them manually: author only News, editor all content.
     *
     * @return list<string>
     */
    public static function defaultModules(int $role): array
    {
        $modules = [];
        foreach (Kernel::MODULES as $class) {
            if ($class::ADMIN_ONLY || $class::FOR_ALL_USERS || $class::SHARES_PERMISSION_OF !== '') {
                continue;
            }
            if ($role >= Auth::EDITOR || $class::IDENT === 'news') {
                $modules[] = $class::IDENT;
            }
        }

        return $modules;
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $id = $this->idParam('user_id');
        if ($id === $this->app->auth()->id()) {
            return $this->back('You cannot delete yourself.', type: 'error');
        }
        $this->db->delete('users', ['user_id' => $id]);

        return $this->back('The user has been deleted. Their news items remain, without an author.');
    }

    /**
     * @param array<string, mixed> $author
     * @param array<string, string> $errors
     */
    private function form(array $author, array $errors = []): Response
    {
        $id = (int) $author['user_id'];
        $author['public_id'] ??= $this->publicId($id);
        $configurable = [];
        foreach (Kernel::MODULES as $class) {
            if (!$class::ADMIN_ONLY && !$class::FOR_ALL_USERS && $class::SHARES_PERMISSION_OF === '') {
                $configurable[$class::IDENT] = $class::NAME;
            }
        }

        // the summary applies to the saved state - above the form it says what the user can do NOW (for a new user there is nothing to summarize)
        $summary = $id > 0 && !$this->request->isPost() ? self::summary(
            (int) $author['admin'],
            array_column($this->db->all('SELECT module FROM {user_permissions} WHERE user_id = ?', [$id]), 'module'),
            (bool) $author['blocked'],
            $author['auto_blocked_at'] ?? null,
        ) : '';

        return $this->view('form', $id ? 'Edit user' : 'New user', [
            'author' => $author,
            'customRoles' => $this->db->all('SELECT role_id, name, description FROM {role} ORDER BY name'),
            'summary' => $summary,
            'errors' => $errors,
            // the user's Claude connections (Core\SecurityHygiene): the administrator revokes what is not needed any more
            'connections' => $id > 0 ? array_values(array_filter(\Talea\Core\SecurityHygiene::connections($this->db), fn (array $c): bool => (int) $c['user_id'] === $id)) : [],
            'isSelf' => $id === $this->app->auth()->id(),
            'modules' => $configurable,
            'hasModules' => $this->request->isPost()
                ? $this->request->postList('modules')
                : array_column($this->db->all('SELECT module FROM {user_permissions} WHERE user_id = ?', [$id]), 'module'),
            'manual' => $this->request->isPost() ? $this->request->postBool('manual') : ($id > 0 && (int) $author['admin'] !== Auth::ADMIN && (function () use ($id, $author): bool {
                $hasNow = array_column($this->db->all('SELECT module FROM {user_permissions} WHERE user_id = ?', [$id]), 'module');
                $defaults = self::defaultModules((int) $author['admin']);
                sort($hasNow);
                sort($defaults);

                return $hasNow !== $defaults;
            })()),
        ]);
    }
}
