<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Multisite;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * Several owners, stage B (docs/specs/multisite.md, "Several owners"): the operators (administrators of owner 1, host 127.0.0.1) create
 * and pause owners, open a client's site (owner B, host localhost) through a one-time handoff that signs them in as a protected row of the
 * client's users, uploads sit in each owner's own media folder, and an operator copies a site across owners. A client administrator reaches
 * none of it. The tests run in order and build on each other.
 */
#[Group('site')]
final class OwnersOperatorsTest extends SiteTestCase
{
    private const string BETA = 'localhost';
    private const string ENV_SECRET = 'k3y-for-the-operators-test-0123456789abcdef0123';

    private static string $betaToken = '';
    private static string $betaOwner = '';
    private static string $opHandoff = '';

    protected static function siteOptions(): array
    {
        return ['env' => ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => self::ENV_SECRET, 'TALEA_TRUSTED_PROXIES' => '127.0.0.1']];
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return ['TALEA_MULTISITE' => '1', 'TALEA_OWNERS' => '1', 'TALEA_SECRET_KEY' => self::ENV_SECRET, 'TALEA_TRUSTED_PROXIES' => '127.0.0.1'];
    }

    /** @return list<string> */
    private function host(bool $beta): array
    {
        return $beta ? ['Host: ' . self::BETA . ':' . $this->site()->port] : [];
    }

    /** @param array<string, mixed> $arguments @return array{error: bool, text: string} */
    private function mcp(bool $beta, string $tool, array $arguments = []): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);
        $response = $this->site()->client('mcp')->post('/mcp', (string) $body, ['Authorization: Bearer ' . ($beta ? self::$betaToken : $this->site()->mcpToken), 'Content-Type: application/json', ...$this->host($beta)]);
        $result = (array) ($response->json()['result'] ?? []);

        return ['error' => $response->status >= 400 || ($result['isError'] ?? false) === true, 'text' => $response->status >= 400 ? 'HTTP ' . $response->status . ' ' . substr($response->body, 0, 300) : (string) ($result['content'][0]['text'] ?? '')];
    }

    /** The anti-forgery token of a signed-in browser, from a page that has a form. */
    private function csrf(Http $as, bool $beta, string $page = '/admin.php?module=users'): string
    {
        return $as->get($page, $this->host($beta))->csrf();
    }

    /** @param array<string, string> $fields */
    private function post(Http $as, bool $beta, string $path, array $fields = [], string $formPage = '/admin.php?module=users'): Response
    {
        return $as->post($path, ['_csrf' => $this->csrf($as, $beta, $formPage)] + $fields, $this->host($beta));
    }

    private function betaAdmin(): Http
    {
        static $client = null;
        if ($client === null) {
            $client = $this->site()->client('beta-admin');
            $csrf = $client->get('/admin.php', $this->host(true))->csrf();
            $client->post('/admin.php', ['_csrf' => $csrf, 'username' => 'client', 'password' => $this->site()->password], $this->host(true));
        }

        return $client;
    }

    /** The operator in owner B: a browser that went through the handoff of the Owners area. */
    private function operatorOnBeta(string $name = 'operator-b'): Http
    {
        $site = $this->site();
        $r = $this->post($site->admin(), false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $this->assertSame(303, $r->status, $r->body);
        $this->assertMatchesRegularExpression('#^http://localhost:' . $site->port . '/admin\?handoff=[a-f0-9]{64}$#', $r->headers['location']);
        self::$opHandoff = $r->headers['location'];
        $browser = $site->client($name);
        $go = $browser->get(substr(self::$opHandoff, strlen('http://localhost:' . $site->port)), $this->host(true));
        $this->assertSame(302, $go->status, 'the handoff signs in and leaves the address at once');

        return $browser;
    }

    public function testAnOperatorCreatesAnOwnerAndInvitesItsFirstAdministrator(): void
    {
        $site = $this->site();
        $this->assertStringContainsString('exit:0', (string) shell_exec('cd ' . escapeshellarg($site->root) . ' && TALEA_MULTISITE=1 TALEA_OWNERS=1 ' . escapeshellarg(PHP_BINARY) . ' bin/migrate 2>&1; echo "exit:$?"'));
        $site->signIn($site->admin());

        $list = $site->admin()->get('/admin.php?module=owners');
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('New owner', $list->body);
        $new = $site->admin()->get('/admin.php?module=owners&action=new');
        $r = $site->admin()->post('/admin.php?module=owners&action=create', ['_csrf' => $new->csrf(), 'name' => 'Beta GmbH', 'kind' => 'organisation', 'admin_email' => 'boss@beta.example']);
        $this->assertSame(302, $r->status);
        $row = $site->rows("SELECT public_id, prefix, status, ready_at, admin_email, kind FROM tl_owners WHERE prefix = 'tlb_'")[0];
        $this->assertSame(['live', null, 'boss@beta.example', 'organisation'], [$row['status'], $row['ready_at'], $row['admin_email'], $row['kind']]);
        self::$betaOwner = (string) $row['public_id'];
        $this->assertStringContainsString('Setting up', $site->admin()->get('/admin.php?module=owners')->body);

        // invalid input creates nothing
        $r = $site->admin()->post('/admin.php?module=owners&action=create', ['_csrf' => $new->csrf(), 'name' => 'No mail', 'kind' => 'organisation', 'admin_email' => 'not-an-address']);
        $this->assertSame(200, $r->status);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_owners WHERE prefix <> 'tl_'"));

        $this->assertStringContainsString('owner tlb_: ready', $site->cli(['system/docker.php', 'cron'], $this->env()));
        $edit = $site->admin()->get('/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $this->assertSame(200, $edit->status);
        $this->assertStringContainsString('Add a host name', $edit->body);

        $site->admin()->post('/admin.php?module=owners&action=domain_add', ['_csrf' => $edit->csrf(), 'id' => self::$betaOwner, 'host' => self::BETA]);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tlb_site_domains WHERE site_id = 1 AND host = 'localhost' AND is_primary = TRUE"));
        $site->admin()->post('/admin.php?module=owners&action=domain_add', ['_csrf' => $edit->csrf(), 'id' => self::$betaOwner, 'host' => '127.0.0.1']);
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_site_domains'), 'a host name another owner uses is refused');

        $edit = $site->admin()->get('/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $site->admin()->post('/admin.php?module=owners&action=invite', ['_csrf' => $edit->csrf(), 'id' => self::$betaOwner]);
        $user = $site->rows("SELECT user_id, username, admin, operator_user_id, reset_token_hash, reset_sent_at FROM tlb_users WHERE email = 'boss@beta.example'");
        $this->assertCount(1, $user, 'the invited administrator is a user of the new owner');
        $this->assertSame([2, null], [(int) $user[0]['admin'], $user[0]['operator_user_id']]);
        $this->assertSame(64, strlen((string) $user[0]['reset_token_hash']));
        $this->assertGreaterThan(date('Y-m-d H:i:s', time() + 48 * 3600), (string) $user[0]['reset_sent_at'], 'valid for 3 days like every invitation');
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_users WHERE email = 'boss@beta.example'"), 'nothing was written to the operator\'s own users');

        // the client administrator the tests sign in as: same password as the operator's, its own row
        $site->exec("INSERT INTO tlb_users (username, password, name, email, admin, language) SELECT 'client', password, 'Client admin', 'client@beta.example', 2, '' FROM tl_users WHERE username = 'admin'");
        self::$betaToken = 'talea_' . bin2hex(random_bytes(24));
        $site->exec("INSERT INTO tlb_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'test', ?, NOW() FROM tlb_users WHERE username = 'client'", [hash('sha256', self::$betaToken)]);
        foreach (['site_url' => 'http://' . self::BETA . ':' . $site->port, 'site_name' => 'Beta site'] as $name => $value) {
            $site->exec('INSERT INTO tlb_settings (name, value) VALUES (?, ?)', [$name, $value]);
        }
        $site->exec("INSERT INTO tlb_settings (name, value) SELECT name, value FROM tl_settings WHERE name IN ('extensions', 'tasks_token')");
        $this->assertFalse($this->mcp(true, 'create_page', ['title' => 'Beta home', 'slug' => 'beta-home', 'content' => '<p>BETA-TEXT</p>', 'visible' => true])['error']);
    }

    public function testAClientAdministratorCannotReachTheOwnersArea(): void
    {
        $site = $this->site();
        $client = $this->betaAdmin();
        $this->assertStringContainsString('Beta home', $client->get('/admin.php?module=pages', $this->host(true))->body, 'the client administrator is signed in');
        foreach (['', '&action=new', '&action=edit&id=' . self::$betaOwner] as $query) {
            $r = $client->get('/admin.php?module=owners' . $query, $this->host(true));
            $this->assertNotSame(200, $r->status, 'owners' . $query);
            $this->assertStringNotContainsString('Beta GmbH', $r->body);
        }
        $before = (int) $site->value('SELECT COUNT(*) FROM tl_owners');
        $csrf = $this->csrf($client, true);
        foreach (['create' => ['name' => 'Evil', 'kind' => 'person', 'admin_email' => 'e@evil.example'], 'status' => ['id' => self::$betaOwner, 'status' => 'archived'], 'handoff' => ['id' => self::$betaOwner, 'site' => 'x'], 'invite' => ['id' => self::$betaOwner], 'domain_add' => ['id' => self::$betaOwner, 'host' => 'evil.test']] as $action => $fields) {
            $r = $client->post('/admin.php?module=owners&action=' . $action, ['_csrf' => $csrf] + $fields, $this->host(true));
            $this->assertNotSame(200, $r->status, $action);
        }
        $this->assertSame([$before, 'live'], [(int) $site->value('SELECT COUNT(*) FROM tl_owners'), $site->value("SELECT status FROM tl_owners WHERE prefix = 'tlb_'")]);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tlb_site_domains WHERE host = 'evil.test'"));
        $this->assertStringNotContainsString('module=owners', $client->get('/admin.php?module=settings', $this->host(true))->body, 'not even a menu entry');
    }

    public function testTheOperatorOpensTheClientSiteWithAOneTimeHandoffAndCannotBeReplayed(): void
    {
        $site = $this->site();
        $operator = $this->operatorOnBeta();
        $pages = $operator->get('/admin.php?module=pages', $this->host(true));
        $this->assertSame(200, $pages->status);
        $this->assertStringContainsString('Beta home', $pages->body, 'signed in on the client\'s site');
        $this->assertStringNotContainsString('name="password"', $pages->body);

        $row = $site->rows('SELECT user_id, username, name, email, admin, operator_user_id FROM tlb_users WHERE operator_user_id IS NOT NULL');
        $this->assertCount(1, $row);
        $this->assertSame([2, 1, ''], [(int) $row[0]['admin'], (int) $row[0]['operator_user_id'], $row[0]['email']]);
        $this->assertStringStartsWith('Operator: ', $row[0]['name']);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_handoff_tokens'), 'the token lives in the target owner\'s table only');
        $this->assertGreaterThan(0, (int) $site->value("SELECT COUNT(*) FROM tlb_change_log WHERE action = 'operator_opened' AND user_name LIKE 'Operator: %'"), 'the client\'s change log says an operator came in');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_handoff_tokens WHERE used_at IS NOT NULL'));

        // the token is used up
        $token = substr((string) strstr(self::$opHandoff, 'handoff='), strlen('handoff='));
        $replay = $site->client('replay');
        $replay->get('/admin?handoff=' . $token, $this->host(true));
        $this->assertStringContainsString('name="password"', $replay->get('/admin.php?module=pages', $this->host(true))->body, 'single use');

        // a fresh token: bound to the owner (the other owner's host does not know it), to the site and to 60 seconds
        $site->exec('DELETE FROM tlb_handoff_tokens');
        $fresh = function () use ($site): string {
            $this->post($site->admin(), false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);

            return (string) $site->value('SELECT token_hash FROM tlb_handoff_tokens ORDER BY expires_at DESC LIMIT 1');
        };
        $hash = $fresh();
        $this->assertGreaterThanOrEqual(gmdate('Y-m-d H:i:s', time() + 50), (string) $site->value('SELECT expires_at FROM tlb_handoff_tokens WHERE token_hash = ?', [$hash]));
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s', time() + 61), (string) $site->value('SELECT expires_at FROM tlb_handoff_tokens WHERE token_hash = ?', [$hash]), 'sixty seconds in UTC');
        $site->exec('UPDATE tlb_handoff_tokens SET expires_at = ?', [gmdate('Y-m-d H:i:s', time() - 5)]);
        // we only know the hash: build the token claim through the real flow instead
        $site->exec('DELETE FROM tlb_handoff_tokens');
        $r = $this->post($site->admin(), false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $token = substr((string) strstr($r->headers['location'], 'handoff='), strlen('handoff='));
        $onAlpha = $site->client('wrong-owner');
        $onAlpha->get('/admin?handoff=' . $token);
        $this->assertStringContainsString('name="password"', $onAlpha->get('/admin.php?module=pages')->body, 'the other owner\'s host does not know the token');
        $site->exec("INSERT INTO tlb_sites (site_id, public_id, name, slug, prefix, status, created_at) VALUES (2, '3c2b6a52-0d42-4a43-9a0e-5f5f0c1f7a11', 'Other', 'other', 'tlb2_', 'paused', NOW())");
        $site->exec('UPDATE tlb_handoff_tokens SET site_id = 2');
        $wrongSite = $site->client('wrong-site');
        $wrongSite->get('/admin?handoff=' . $token, $this->host(true));
        $this->assertStringContainsString('name="password"', $wrongSite->get('/admin.php?module=pages', $this->host(true))->body, 'bound to its site');
        $site->exec('UPDATE tlb_handoff_tokens SET site_id = 1, expires_at = ?', [gmdate('Y-m-d H:i:s', time() - 5)]);
        $site->exec('DELETE FROM tlb_sites WHERE site_id = 2');
        $late = $site->client('late');
        $late->get('/admin?handoff=' . $token, $this->host(true));
        $this->assertStringContainsString('name="password"', $late->get('/admin.php?module=pages', $this->host(true))->body, 'expired');
        $this->assertNull($site->value('SELECT used_at FROM tlb_handoff_tokens'), 'a refused claim does not use the token up');
    }

    public function testOnlyAnOperatorCanCreateAHandoffAndOnlyForALiveSetUpOwner(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tlb_handoff_tokens');
        // a client administrator posting the very same request on its own host gets nothing
        $r = $this->post($this->betaAdmin(), true, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')]);
        $this->assertNotSame(303, $r->status);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tlb_handoff_tokens'));
        // an editor of owner 1 is no operator either
        $site->exec("INSERT INTO tl_users (username, password, name, email, admin, language) SELECT 'editor', password, 'Editor', 'ed@alpha.example', 1, '' FROM tl_users WHERE username = 'admin'");
        $editor = $site->client('editor');
        $csrf = $editor->get('/admin.php')->csrf();
        $editor->post('/admin.php', ['_csrf' => $csrf, 'username' => 'editor', 'password' => $site->password]);
        $this->assertNotSame(200, $editor->get('/admin.php?module=owners')->status);
        $r = $editor->post('/admin.php?module=owners&action=handoff', ['_csrf' => $editor->get('/admin.php')->csrf(), 'id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')]);
        $this->assertNotSame(303, $r->status);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tlb_handoff_tokens'));
        // a paused owner cannot be opened
        $site->exec("UPDATE tl_owners SET status = 'paused' WHERE prefix = 'tlb_'");
        $r = $this->post($site->admin(), false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $this->assertNotSame(303, $r->status);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tlb_handoff_tokens'));
        $site->exec("UPDATE tl_owners SET status = 'live' WHERE prefix = 'tlb_'");
    }

    public function testTheOperatorRowIsProtectedFromTheClient(): void
    {
        $site = $this->site();
        $client = $this->betaAdmin();
        $row = $site->rows('SELECT user_id, public_id, username, password, name, admin, blocked FROM tlb_users WHERE operator_user_id IS NOT NULL')[0];
        $id = (string) $row['public_id'];

        $list = $client->get('/admin.php?module=users', $this->host(true));
        $this->assertStringContainsString('Operator: ', $list->body, 'shown as Operator in the user list');
        $csrf = $list->csrf();
        $same = fn (): array => $site->rows('SELECT username, password, name, admin, blocked, email FROM tlb_users WHERE user_id = ?', [$row['user_id']])[0];
        $before = $same();

        $this->assertStringNotContainsString('name="username"', $client->get('/admin.php?module=users&action=edit&id=' . $id, $this->host(true))->body, 'no edit form');
        foreach ([
            'save' => ['user_id' => $id, 'username' => 'hacked', 'name' => 'Hacked', 'email' => 'me@evil.example', 'admin' => '0', 'password' => 'a-brand-new-password', 'blocked' => '1'],
            'delete' => ['user_id' => $id],
            'password_link' => ['user_id' => $id],
            'reactivate' => ['user_id' => $id],
        ] as $action => $fields) {
            $client->post('/admin.php?module=users&action=' . $action, ['_csrf' => $csrf] + $fields, $this->host(true));
            $this->assertSame($before, $same(), $action);
        }
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_users WHERE user_id = ?', [$row['user_id']]));

        // no password, no reset: signing in as the operator row by name fails whatever is typed, and a reset sends nothing
        foreach (['operator-1', $row['username']] as $name) {
            $anon = $site->client('anon-' . $name);
            $r = $anon->post('/admin.php', ['_csrf' => $anon->get('/admin.php', $this->host(true))->csrf(), 'username' => $name, 'password' => $site->password], $this->host(true));
            $this->assertStringContainsString('name="password"', $r->body, 'the operator\'s own password is no key to the row');
            $this->assertStringNotContainsString('Operator', $anon->get('/admin.php?module=pages', $this->host(true))->body);
        }
        $anon = $site->client('reset');
        $anon->post('/admin.php?action=password', ['_csrf' => $anon->get('/admin.php?action=password', $this->host(true))->csrf(), 'user_id' => $row['username']], $this->host(true));
        $this->assertSame('', (string) $site->value('SELECT reset_token_hash FROM tlb_users WHERE user_id = ?', [$row['user_id']]));
        $site->exec("UPDATE tlb_users SET email = 'op@example.test' WHERE user_id = ?", [$row['user_id']]);
        $anon->post('/admin.php?action=password', ['_csrf' => $anon->get('/admin.php?action=password', $this->host(true))->csrf(), 'user_id' => 'op@example.test'], $this->host(true));
        $this->assertSame('', (string) $site->value('SELECT reset_token_hash FROM tlb_users WHERE user_id = ?', [$row['user_id']]), 'even with an address the row cannot be reset');
        $site->exec("UPDATE tlb_users SET email = '' WHERE user_id = ?", [$row['user_id']]);

        // the operator in the client's administration cannot give the row a password, an address or a token either
        $operator = $this->operatorOnBeta('operator-b2');
        $account = $operator->get('/admin.php?action=account', $this->host(true));
        $this->assertSame(200, $account->status);
        $operator->post('/admin.php?action=account&tab=password', ['_csrf' => $account->csrf(), 'op' => 'password', 'current_password' => 'x', 'new_password' => 'a-brand-new-password-1', 'new_password2' => 'a-brand-new-password-1'], $this->host(true));
        $this->assertSame($before['password'], $same()['password']);
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tlb_api_tokens WHERE user_id = ?', [$row['user_id']]));
        $this->post($operator, true, '/admin.php?action=account&tab=claude', ['op' => 'token_new', 'token_name' => 'persist'], '/admin.php?action=account');
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tlb_api_tokens WHERE user_id = ?', [$row['user_id']]));
        // a token that somehow exists for the row opens nothing over MCP
        $site->exec('INSERT INTO tlb_api_tokens (user_id, name, token_hash, created_at) VALUES (?, ?, ?, NOW())', [$row['user_id'], 'stray', hash('sha256', 'talea_' . str_repeat('a', 48))]);
        $stray = $site->client('stray')->post('/mcp', (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_pages', 'arguments' => new \stdClass()]]), ['Authorization: Bearer talea_' . str_repeat('a', 48), 'Content-Type: application/json', ...$this->host(true)]);
        $this->assertSame(401, $stray->status);
        $site->exec('DELETE FROM tlb_api_tokens WHERE user_id = ?', [$row['user_id']]);
    }

    public function testTheOperatorRowGoesWhenTheOperatorDoes(): void
    {
        $site = $this->site();
        // a second operator, who works in the client's administration for a moment
        $site->exec("INSERT INTO tl_users (username, password, name, email, admin, language) SELECT 'op2', password, 'Second operator', 'op2@alpha.example', 2, '' FROM tl_users WHERE username = 'admin'");
        $op2 = $site->client('op2');
        $op2->post('/admin.php', ['_csrf' => $op2->get('/admin.php')->csrf(), 'username' => 'op2', 'password' => $site->password]);
        $r = $this->post($op2, false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $this->assertSame(303, $r->status);
        $browser = $site->client('op2-on-beta');
        $browser->get(substr($r->headers['location'], strlen('http://localhost:' . $site->port)), $this->host(true));
        $this->assertStringNotContainsString('name="password"', $browser->get('/admin.php?module=pages', $this->host(true))->body);
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_users u JOIN tl_users o ON o.user_id = u.operator_user_id WHERE o.username = ?', ['op2']));

        // blocked in owner 1: the open session ends at once and no new handoff is made (the row itself is cleaned up with the next change)
        $site->exec("UPDATE tl_users SET blocked = TRUE WHERE username = 'op2'");
        $this->assertStringContainsString('name="password"', $browser->get('/admin.php?module=pages', $this->host(true))->body, 'blocked operator: the session on the client\'s site is void');
        $site->exec("UPDATE tl_users SET blocked = FALSE WHERE username = 'op2'");
        $this->assertStringNotContainsString('name="password"', $browser->get('/admin.php?module=pages', $this->host(true))->body, 'and alive again with the operator');

        // deleted by another operator: the row, its tokens and its session are gone
        $opId = (string) $site->value("SELECT public_id FROM tl_users WHERE username = 'op2'");
        $this->post($site->admin(), false, '/admin.php?module=users&action=delete', ['user_id' => $opId]);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_users WHERE username = 'op2'"));
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tlb_users WHERE operator_user_id IS NOT NULL AND operator_user_id NOT IN (SELECT user_id FROM tl_users)'));
        $this->assertStringContainsString('name="password"', $browser->get('/admin.php?module=pages', $this->host(true))->body);

        // demoted to an editor in owner 1: the session on the client's site is void too
        $site->exec("INSERT INTO tl_users (username, password, name, email, admin, language) SELECT 'op3', password, 'Third operator', 'op3@alpha.example', 2, '' FROM tl_users WHERE username = 'admin'");
        $op3 = $site->client('op3');
        $op3->post('/admin.php', ['_csrf' => $op3->get('/admin.php')->csrf(), 'username' => 'op3', 'password' => $site->password]);
        $r = $this->post($op3, false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $on = $site->client('op3-on-beta');
        $on->get(substr($r->headers['location'], strlen('http://localhost:' . $site->port)), $this->host(true));
        $this->assertStringNotContainsString('name="password"', $on->get('/admin.php?module=pages', $this->host(true))->body);
        $site->exec("UPDATE tl_users SET admin = 1 WHERE username = 'op3'");
        $this->assertStringContainsString('name="password"', $on->get('/admin.php?module=pages', $this->host(true))->body, 'no longer an administrator: no longer an operator');
        $this->assertNotSame(303, $on->post('/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner], $this->host(true))->status);
    }

    public function testEachOwnerKeepsItsUploadsInItsOwnMediaFolder(): void
    {
        $site = $this->site();
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        $png = base64_encode((string) ob_get_clean());
        $ym = date('Y/m');
        $a = json_decode($this->mcp(false, 'upload_file', ['filename' => 'logo.png', 'data' => $png])['text'], true);
        $b = json_decode($this->mcp(true, 'upload_file', ['filename' => 'logo.png', 'data' => $png])['text'], true);
        $this->assertIsArray($a);
        $this->assertIsArray($b);
        $pathA = (string) ($a['path'] ?? $a['image_path'] ?? '');
        $pathB = (string) ($b['path'] ?? $b['image_path'] ?? '');
        $this->assertMatchesRegularExpression('#^media/' . $ym . '/logo-[0-9a-f]{6}\.png$#', $pathA, 'owner 1 keeps media/Y/m');
        $betaId = (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tlb_'");
        $this->assertMatchesRegularExpression('#^media/' . $betaId . '/' . $ym . '/logo-[0-9a-f]{6}\.png$#', $pathB, 'a further owner: media/<owner>/Y/m');
        $this->assertFileExists($site->path($pathA));
        $this->assertFileExists($site->path($pathB));
        $this->assertFileDoesNotExist($site->path('media/' . $betaId . '/' . $ym . '/' . basename($pathA)));
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_media WHERE image_path = ?', [$pathB]));
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_media WHERE image_path = ?', [$pathB]));
        // served from the owner's own folder; a page's picture gets its variants from there
        $this->assertSame(200, $site->client('img')->get('/' . $pathB, $this->host(true))->status);
        // owner 1's media size and archive leave the folders of the others out
        $files = json_decode($site->php('echo json_encode(array_keys(\\Talea\\Core\\SiteExport::mediaFiles()));'), true);
        $this->assertContains($pathA, $files);
        $this->assertNotContains($pathB, $files, 'owner 1\'s archive and off-site copy hold only owner 1\'s files');
    }

    public function testAnOperatorCopiesASiteAcrossOwnersWithItsMediaAndNothingLinked(): void
    {
        $site = $this->site();
        $ym = date('Y/m');
        $betaId = (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tlb_'");
        $pathB = (string) $site->value("SELECT image_path FROM tlb_media WHERE image_path LIKE 'media/%/logo-%' LIMIT 1");
        $this->assertNotSame('', $pathB);
        // B's site: a page with the picture (also as a variant and in JSON), a local component and a fact
        $site->exec("UPDATE tlb_pages SET text = ?, build = ? WHERE slug = 'beta-home'", ['<p><img src="/' . $pathB . '" alt="logo"></p>', json_encode(['v' => 1, 'children' => [['id' => 'a1', 'type' => 'image', 'tag' => 'img', 'content' => ['src' => $pathB], 'style' => [], 'classes' => [], 'children' => []]]], JSON_UNESCAPED_SLASHES)]);
        $component = json_decode($this->mcp(true, 'save_component', ['name' => 'Beta card'])['text'], true);
        $this->assertIsArray($component);
        $sourceSite = (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1');

        // owner A's operator copies it with create_site (MCP): the row says where it comes from
        $r = $this->mcp(false, 'create_site', ['name' => 'From Beta', 'clone_of' => $sourceSite]);
        $this->assertFalse($r['error'], $r['text']);
        $this->assertSame(['tlb_', 1], [$site->value("SELECT source_owner FROM tl_sites WHERE name = 'From Beta'"), (int) $site->value("SELECT source_site_id FROM tl_sites WHERE name = 'From Beta'")]);
        $site->cli(['system/docker.php', 'cron'], $this->env());
        $this->assertNotNull($site->value("SELECT ready_at FROM tl_sites WHERE name = 'From Beta'"));

        $newId = (int) $site->value("SELECT site_id FROM tl_sites WHERE name = 'From Beta'");
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl2_pages WHERE slug = 'beta-home'"), 'the pages came along');
        $text = (string) $site->value("SELECT text FROM tl2_pages WHERE slug = 'beta-home'");
        $build = (string) $site->value("SELECT build FROM tl2_pages WHERE slug = 'beta-home'");
        $newPath = 'media/' . $ym . '/' . basename($pathB);
        $this->assertStringContainsString($newPath, $text, 'rewritten into owner 1\'s folder');
        $this->assertStringNotContainsString($betaId, $text . $build);
        $this->assertStringContainsString(str_replace('/', '\\/', $newPath), json_encode(json_decode($build, true)), 'and in the build');
        $this->assertFileExists($site->path($newPath), 'the file was copied');
        $this->assertFileExists($site->path($pathB), 'the source keeps its own');
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_media WHERE image_path = ?', [$newPath]), 'with a media row of the new owner');
        // components are the new site's LOCAL rows, with their own numbers
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_components WHERE site_id = ? AND name = ?', [$newId, 'Beta card']));
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_components WHERE name = ?', ['Beta card']));
        $this->assertNotSame($site->value('SELECT public_id FROM tl_components WHERE site_id = ? AND name = ?', [$newId, 'Beta card']), $site->value('SELECT public_id FROM tlb_components WHERE name = ?', ['Beta card']));
        // both sides have a line in the change log; nothing refers to the other owner
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl2_change_log WHERE action = 'clone_in'"));
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tlb_change_log WHERE action = 'clone_out'"));
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl2_news WHERE author_id IS NOT NULL'));
        // changing the copy's source afterwards does not touch the copy
        $site->exec("UPDATE tlb_pages SET text = '<p>CHANGED-IN-BETA</p>' WHERE slug = 'beta-home'");
        $this->assertStringNotContainsString('CHANGED-IN-BETA', (string) $site->value("SELECT text FROM tl2_pages WHERE slug = 'beta-home'"));

        // a client administrator cannot copy another owner's site: the id is unknown to it
        $r = $this->mcp(true, 'create_site', ['name' => 'Steal', 'clone_of' => (string) $site->value('SELECT public_id FROM tl_sites WHERE site_id = 1')]);
        $this->assertTrue($r['error']);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tlb_sites WHERE name = 'Steal'"));
    }

    public function testACopyNeverOverwritesOrReusesADifferentFileWithTheSameName(): void
    {
        $site = $this->site();
        $ym = date('Y/m');
        $im = imagecreatetruecolor(5, 5);
        ob_start();
        imagepng($im);
        $b = json_decode($this->mcp(true, 'upload_file', ['filename' => 'pic.png', 'data' => base64_encode((string) ob_get_clean())])['text'], true);
        $pathB = (string) ($b['path'] ?? $b['image_path'] ?? '');
        $this->assertNotSame('', $pathB);
        // owner 1's folder already holds another file under the very name the copy would take
        $taken = $site->path('media/' . $ym . '/' . basename($pathB));
        @mkdir(dirname($taken), 0775, true);
        file_put_contents($taken, 'not the same file');
        $site->exec("UPDATE tlb_pages SET text = ?, build = NULL WHERE slug = 'beta-home'", ['<p><img src="/' . $pathB . '" alt="p"></p>']);

        $r = $this->mcp(false, 'create_site', ['name' => 'From Beta 2', 'clone_of' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')]);
        $this->assertFalse($r['error'], $r['text']);
        $site->cli(['system/docker.php', 'cron'], $this->env());
        $prefix = (string) $site->value("SELECT prefix FROM tl_sites WHERE name = 'From Beta 2'");
        $text = (string) $site->value('SELECT text FROM ' . $prefix . 'pages WHERE slug = ?', ['beta-home']);

        $this->assertSame('not the same file', file_get_contents($taken), 'the other file is untouched');
        $this->assertStringNotContainsString('media/' . $ym . '/' . basename($pathB), $text, 'the copy does not point at the file that was already there');
        $this->assertSame(1, preg_match('#media/' . preg_quote($ym, '#') . '/(pic-[0-9a-f]{6}\.png)#', $text, $m), $text);
        $this->assertFileExists($site->path('media/' . $ym . '/' . $m[1]), 'it has a name of its own');
        $this->assertSame(md5_file($site->path($pathB)), md5_file($site->path('media/' . $ym . '/' . $m[1])));
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tl_media WHERE image_path = ?', ['media/' . $ym . '/' . $m[1]]));
        $this->assertSame(0, (int) $site->value('SELECT COUNT(*) FROM tl_media WHERE image_path = ?', ['media/' . $ym . '/' . basename($pathB)]));
    }

    public function testTheOperatorCopiesASiteIntoTheClientsOwnerFromThereAndItsFilesLandInTheClientsFolder(): void
    {
        $site = $this->site();
        $betaId = (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tlb_'");
        $ym = date('Y/m');
        // owner A's site 1: a page with a picture of A's folder
        $im = imagecreatetruecolor(9, 9);
        ob_start();
        imagepng($im);
        $a = json_decode($this->mcp(false, 'upload_file', ['filename' => 'alpha.png', 'data' => base64_encode((string) ob_get_clean())])['text'], true);
        $pathA = (string) ($a['path'] ?? $a['image_path'] ?? '');
        $this->assertNotSame('', $pathA);
        $this->assertFalse($this->mcp(false, 'create_page', ['title' => 'Alpha picture', 'slug' => 'alpha-picture', 'content' => '<p>x</p>', 'visible' => true])['error']);
        $site->exec("UPDATE tl_pages SET text = ? WHERE slug = 'alpha-picture'", ['<p><img src="/' . $pathA . '" alt="a"></p>']);
        $home = 'alpha-picture';

        // the copy is made in owner 1's administration (the Owners area), never from inside the client
        $alphaSite = (string) $site->value('SELECT public_id FROM tl_sites WHERE site_id = 1');
        $admin = $site->admin();
        $form = $admin->get('/admin.php?module=owners&action=clone');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString($alphaSite, $form->body, 'the operator picks the source among every owner\'s sites');
        $r = $admin->post('/admin.php?module=owners&action=clone', ['_csrf' => $form->csrf(), 'name' => 'From Alpha', 'from' => $alphaSite, 'to' => self::$betaOwner]);
        $this->assertSame(302, $r->status);
        $this->assertSame('tl_', $site->value("SELECT source_owner FROM tlb_sites WHERE name = 'From Alpha'"));
        $this->assertStringContainsString('ready', $site->cli(['system/docker.php', 'cron'], $this->env()));
        $this->assertNotNull($site->value("SELECT ready_at FROM tlb_sites WHERE name = 'From Alpha'"));

        $text = (string) $site->value('SELECT text FROM tlb2_pages WHERE slug = ?', [$home]);
        $this->assertStringContainsString('media/' . $betaId . '/' . $ym . '/' . basename($pathA), $text, 'the copy points into the client\'s folder');
        $this->assertFileExists($site->path('media/' . $betaId . '/' . $ym . '/' . basename($pathA)));
        $this->assertSame(1, (int) $site->value('SELECT COUNT(*) FROM tlb_media WHERE image_path = ?', ['media/' . $betaId . '/' . $ym . '/' . basename($pathA)]));
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tlb2_change_log WHERE action = 'clone_in'"));

        // neither a client administrator nor the operator's row INSIDE the client is offered other owners' sites or can name one: the operator row
        // lives in the client's origin, where the client's own scripts run
        foreach (['client administrator' => $this->betaAdmin(), 'operator row' => $this->operatorOnBeta('operator-b4')] as $who => $browser) {
            $form = $browser->get('/admin.php?module=sites&action=new', $this->host(true));
            $this->assertStringNotContainsString($alphaSite, $form->body, $who);
            $before = (int) $site->value('SELECT COUNT(*) FROM tlb_sites');
            $browser->post('/admin.php?module=sites&action=create', ['_csrf' => $form->csrf(), 'name' => 'Sneaky ' . $who, 'from' => $alphaSite], $this->host(true));
            $this->assertSame($before, (int) $site->value('SELECT COUNT(*) FROM tlb_sites'), $who);
            $this->assertNotSame(200, $browser->get('/admin.php?module=owners&action=clone', $this->host(true))->status, $who . ': the Owners area is not there');
            $before = (int) $site->value('SELECT COUNT(*) FROM tlb_sites');
            $browser->post('/admin.php?module=owners&action=clone', ['_csrf' => $form->csrf(), 'name' => 'Sneaky 2', 'from' => $alphaSite, 'to' => self::$betaOwner], $this->host(true));
            $this->assertSame($before, (int) $site->value('SELECT COUNT(*) FROM tlb_sites'), $who);
        }
    }

    public function testAnOperatorCopiesIntoAnotherOwnerOverMcpOnTheirOwnConnectionOnly(): void
    {
        $site = $this->site();
        $alphaSite = (string) $site->value('SELECT public_id FROM tl_sites WHERE site_id = 1');
        $r = $this->mcp(false, 'create_site', ['name' => 'Over MCP', 'clone_of' => $alphaSite, 'owner' => self::$betaOwner]);
        $this->assertFalse($r['error'], $r['text']);
        $this->assertSame(['tl_', 1], [$site->value("SELECT source_owner FROM tlb_sites WHERE name = 'Over MCP'"), (int) $site->value("SELECT source_site_id FROM tlb_sites WHERE name = 'Over MCP'")]);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_sites WHERE name = 'Over MCP'"));
        // a connection of the client names neither another owner nor a site of one
        $r = $this->mcp(true, 'create_site', ['name' => 'Not allowed', 'owner' => (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tl_'")]);
        $this->assertTrue($r['error']);
        $this->assertSame(0, (int) $site->value("SELECT COUNT(*) FROM tl_sites WHERE name = 'Not allowed'"));
    }

    public function testPausingAndArchivingAnOwnerFromTheOwnersArea(): void
    {
        $site = $this->site();
        foreach (['paused' => 503, 'archived' => 404, 'live' => 200] as $status => $expected) {
            $edit = $site->admin()->get('/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
            $site->admin()->post('/admin.php?module=owners&action=status', ['_csrf' => $edit->csrf(), 'id' => self::$betaOwner, 'status' => $status]);
            $this->assertSame($expected, $site->client('visitor')->get('/beta-home', $this->host(true))->status, $status);
        }
        $this->assertSame(200, $site->client('visitor')->get('/', [])->status, 'owner 1 is unaffected');
        // owner 1 cannot be paused
        $first = (string) $site->value("SELECT public_id FROM tl_owners WHERE prefix = 'tl_'");
        $edit = $site->admin()->get('/admin.php?module=owners');
        $site->admin()->post('/admin.php?module=owners&action=status', ['_csrf' => $edit->csrf(), 'id' => $first, 'status' => 'paused']);
        $this->assertSame('live', $site->value("SELECT status FROM tl_owners WHERE prefix = 'tl_'"));
    }
    public function testAClientsOwnHostStartsUnverifiedAndAnOperatorsDoesNot(): void
    {
        $site = $this->site();
        $this->assertNotNull($site->value("SELECT verified_at FROM tlb_site_domains WHERE host = 'localhost'"), 'the operator gave the owner its first host: verified');
        $client = $this->betaAdmin();
        $form = $client->get('/admin.php?module=sites', $this->host(true));
        $betaSite = (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1');
        $client->post('/admin.php?module=sites&action=domain_add', ['_csrf' => $form->csrf(), 'id' => $betaSite, 'host' => 'client-own.test'], $this->host(true));
        $row = $site->rows("SELECT verified_at, token FROM tlb_site_domains WHERE host = 'client-own.test'")[0] ?? null;
        $this->assertNotNull($row, 'added');
        $this->assertNull($row['verified_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $row['token']);
        $this->assertSame(404, $site->client('visitor')->get('/', ['Host: client-own.test:' . $site->port])->status, 'it does not route until verified');

        // the client cannot vouch for it, nor can the operator's row inside the client; the operator in owner 1's administration can
        $client->post('/admin.php?module=sites&action=domain_verify', ['_csrf' => $form->csrf(), 'id' => $betaSite, 'host' => 'client-own.test'], $this->host(true));
        $this->assertNull($site->value("SELECT verified_at FROM tlb_site_domains WHERE host = 'client-own.test'"));
        $operator = $this->operatorOnBeta('operator-verify');
        $edit = $operator->get('/admin.php?module=sites&action=edit&id=' . $betaSite, $this->host(true));
        $this->assertStringNotContainsString('Mark as verified', $edit->body, 'the row in the client\'s origin has no such power');
        foreach (['/admin.php?module=sites&action=domain_verify', '/admin.php?module=owners&action=domain_verify'] as $path) {
            $operator->post($path, ['_csrf' => $edit->csrf(), 'id' => $betaSite, 'host' => 'client-own.test'], $this->host(true));
        }
        $this->assertNull($site->value("SELECT verified_at FROM tlb_site_domains WHERE host = 'client-own.test'"));
        // adding a host from there is a client's add: unverified
        $operator->post('/admin.php?module=sites&action=domain_add', ['_csrf' => $edit->csrf(), 'id' => $betaSite, 'host' => 'operator-row.test'], $this->host(true));
        $this->assertNull($site->value("SELECT verified_at FROM tlb_site_domains WHERE host = 'operator-row.test'"));
        $this->assertNotNull($site->value("SELECT token FROM tlb_site_domains WHERE host = 'operator-row.test'"));

        $admin = $site->admin();
        $page = $admin->get('/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $this->assertStringContainsString('Mark as verified', $page->body);
        $admin->post('/admin.php?module=owners&action=domain_verify', ['_csrf' => $page->csrf(), 'id' => self::$betaOwner, 'host' => 'client-own.test']);
        $this->assertNotNull($site->value("SELECT verified_at FROM tlb_site_domains WHERE host = 'client-own.test'"));
        $this->assertSame(200, $site->client('visitor')->get('/', ['Host: client-own.test:' . $site->port])->status);
    }

    public function testTheOperatorsSessionInAClientEndsWhenTheOperatorsPasswordChanges(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_users (username, password, name, email, admin, language) SELECT 'op2', password, 'Second operator', 'op2@alpha.example', 2, '' FROM tl_users WHERE username = 'admin'");
        $mine = $site->client('op2-owner1');
        $mine->post('/admin.php', ['_csrf' => $mine->get('/admin.php')->csrf(), 'username' => 'op2', 'password' => $site->password]);
        $r = $this->post($mine, false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $this->assertSame(303, $r->status);
        $inClient = $site->client('op2-client');
        $inClient->get(substr($r->headers['location'], strlen('http://localhost:' . $site->port)), $this->host(true));
        $this->assertStringNotContainsString('name="password"', $inClient->get('/admin.php?module=pages', $this->host(true))->body, 'signed in as the operator');
        $site->exec("UPDATE tl_users SET password = ? WHERE username = 'op2'", [password_hash('a-brand-new-password-1', PASSWORD_DEFAULT)]);
        $this->assertStringContainsString('name="password"', $inClient->get('/admin.php?module=pages', $this->host(true))->body, 'the operator changed their password: the session in the client ends');
    }

    public function testAHandoffLinkClaimedFromAnotherNetworkSignsNobodyIn(): void
    {
        $site = $this->site();
        $site->exec('DELETE FROM tlb_handoff_tokens');
        $r = $this->post($site->admin(), false, '/admin.php?module=owners&action=handoff', ['id' => self::$betaOwner, 'site' => (string) $site->value('SELECT public_id FROM tlb_sites WHERE site_id = 1')], '/admin.php?module=owners&action=edit&id=' . self::$betaOwner);
        $path = substr($r->headers['location'], strlen('http://localhost:' . $site->port));
        $elsewhere = $site->client('elsewhere');
        $elsewhere->get($path, [...$this->host(true), 'X-Forwarded-For: 198.51.100.7']);
        $this->assertStringContainsString('name="password"', $elsewhere->get('/admin.php?module=pages', $this->host(true))->body, 'another network');
        $this->assertNull($site->value('SELECT used_at FROM tlb_handoff_tokens'));
        $home = $site->client('same-network');
        $home->get($path, $this->host(true));
        $this->assertStringNotContainsString('name="password"', $home->get('/admin.php?module=pages', $this->host(true))->body, 'the network that asked for it');
    }

}
