<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\SiteMoveSecurity;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Was: section 42 of tools/test.sh – 2.8 security hygiene: unused accounts and Claude connections, automatic suspension. */
#[Group('site')]
final class SecurityHygieneTest extends SiteTestCase
{
    private function runHygiene(): string
    {
        return $this->site()->php('$app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo json_encode(Kaleta\Core\SecurityHygiene::run($app));');
    }

    public function testUnusedAccountsAndConnectionsAreReportedThenSuspended(): void
    {
        $site = $this->site();
        $hash = '$2y$12$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu';
        // an administrator and an editor nobody has used for 100 days, an old personal token of the editor, an unused token of the admin created 70 days ago and a token used today
        $site->exec("INSERT INTO ka_users (username, password, name, admin, last_login_at, confirmed_at) VALUES ('stary-spravce', ?, 'Stary Spravce', 2, NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 100 DAY), ('stary-editor', ?, '', 1, NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 100 DAY)", [$hash, $hash]);
        $site->exec("INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at, used_at) SELECT user_id, 'stary token', SHA2('hygiene-old', 256), NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 100 DAY FROM ka_users WHERE username = 'stary-editor'");
        $site->exec("INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at, expires_at) SELECT user_id, 'nepouzity token', SHA2('hygiene-unused', 256), NOW() - INTERVAL 70 DAY, NOW() + INTERVAL 1 YEAR FROM ka_users WHERE username = 'admin'");
        $site->exec("INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at, used_at, expires_at) SELECT user_id, 'zivy token', SHA2('hygiene-live', 256), NOW() - INTERVAL 70 DAY, NOW(), NOW() + INTERVAL 1 YEAR FROM ka_users WHERE username = 'admin'");

        $status = $this->assertPage('/admin.php?module=status', 200, 'Nepoužívané účty', message: 'System status lists the unused accounts and connections with links');
        foreach (['Stary Spravce (poslední aktivita', 'stary-editor (poslední aktivita', 'stary token (stary-editor)', 'nepouzity token (Tester)', 'vypnuto – nepoužívané účty a napojení se jen hlásí'] as $text) {
            $this->assertStringContainsString($text, $status->body, "System status: $text");
        }
        $this->assertStringNotContainsString('zivy token (Tester)', $status->body, 'System status: the live token is fine');
        $this->assertPage('/admin.php?module=settings&tab=general', 200, 'name="auto_suspend[]"', message: 'the settings form offers the automatic suspension');

        $audit = (string) ($site->mcp('site_audit', ['kind' => 'handover'])['result']['content'][0]['text'] ?? '');
        foreach (['unused_account', 'unused_connection', 'auto_suspend'] as $kind) {
            $this->assertMatchesRegularExpression('/"handover": ?"' . $kind . '"/', $audit, "site audit: $kind before handing over");
        }

        $this->assertSame('{"blocked":[],"revoked":[]}', $this->runHygiene(), 'run() does nothing while the automatic suspension is off');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_users WHERE blocked = 1 AND username LIKE 'stary-%'"), 'nobody is blocked while the suspension is off');

        $site->setting('auto_suspend', 'ucty,napojeni');
        $this->assertSame('{"blocked":["stary-editor","Stary Spravce"],"revoked":["stary token (stary-editor)","nepouzity token (Tester)"]}', $this->runHygiene(), 'run() blocks the unused accounts and revokes the unused connections');
        $this->assertSame('01|stary-editor:1:1,stary-spravce:1:1|zivy token', (string) $site->value("SELECT CONCAT((SELECT CONCAT(blocked, auto_blocked_at IS NULL) FROM ka_users WHERE username = 'admin'), '|', (SELECT GROUP_CONCAT(CONCAT(username, ':', blocked, ':', auto_blocked_at IS NOT NULL) ORDER BY username) FROM ka_users WHERE username LIKE 'stary-%'), '|', (SELECT GROUP_CONCAT(name ORDER BY name) FROM ka_api_tokens WHERE name LIKE '%token'))"),
            'the admin in use stays, the old accounts are blocked with the reason, the live token stays');
        $this->assertSame('2/2', (string) $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_change_log WHERE module = 'users' AND action = 'auto_block'), '/', (SELECT COUNT(*) FROM ka_change_log WHERE module = 'claude' AND action = 'auto_revoke'))"), 'every automatic action is in the change log');
        $this->assertSame('2/2', (string) $site->value("SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'security.account_suspended'), '/', (SELECT COUNT(*) FROM ka_events WHERE type = 'security.connection_revoked'))"), 'every automatic action is an event without names (alerts, list_events)');
        $this->assertSame(401, $site->client()->post('/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', ['Authorization: Bearer hygiene-old', 'Content-Type: application/json'])->status, 'a blocked account cannot use its token');

        $users = $this->assertPage('/admin.php?module=users', 200, 'Zablokován automaticky', message: 'Users shows why the account is blocked');
        $this->assertStringContainsString('(zablokován automaticky)', $users->body, 'Users: the automatic block is labelled');
        $this->assertStringContainsString('action=reactivate', $users->body, 'Users: the automatic block can be reactivated');

        $old = (int) $site->value("SELECT user_id FROM ka_users WHERE username = 'stary-editor'");
        $this->assertPage("/admin.php?module=users&action=edit&id=$old", 200, 'Odškrtněte políčko a uložte', message: 'the user form explains the automatic block');
        $this->adminPost('/admin.php?module=users&action=reactivate', ['user_id' => $old, 'username' => 'stary-editor'], '/admin.php?module=users');
        $this->assertSame('011', (string) $site->value("SELECT CONCAT(blocked, auto_blocked_at IS NULL, confirmed_at > NOW() - INTERVAL 1 MINUTE) FROM ka_users WHERE username = 'stary-editor'"), 'reactivation unblocks the account and confirms it');
        $this->assertSame('{"blocked":[],"revoked":[]}', $this->runHygiene(), 'a reactivated account is not blocked again by the next run');

        $admin = (int) $site->value("SELECT user_id FROM ka_users WHERE username = 'admin'");
        $live = (int) $site->value("SELECT token_id FROM ka_api_tokens WHERE name = 'zivy token'");
        $this->assertPage("/admin.php?module=users&action=edit&id=$admin", 200, 'id="napojeni"', message: 'the administrator sees the connections of an account');
        $this->adminPost('/admin.php?module=users&action=revoke_connection', ['user_id' => $admin, 'idt' => $live, 'username' => 'admin'], '/admin.php?module=users');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM ka_api_tokens WHERE name = 'zivy token'"), 'the administrator revokes a connection from the user form');
        $site->exec("UPDATE ka_settings SET value = '' WHERE name = 'auto_suspend'");
    }
}
