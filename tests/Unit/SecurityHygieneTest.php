<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SecurityHygieneTest extends TestCase
{
    /** security hygiene (2.8): which accounts and connections count as unused, whom the automatic suspension never blocks */
    public function testUnusedAccounts(): void
    {
        $hygieneNow = new \DateTimeImmutable('2026-10-02 12:00:00');
        $account = fn (int $idu, int $admin, ?string $signIn, ?string $confirmed = null, ?string $claude = null, int $blocked = 0): array =>
            ['user_id' => $idu, 'username' => 'u' . $idu, 'name' => '', 'admin' => $admin, 'blocked' => $blocked, 'last_login_at' => $signIn, 'confirmed_at' => $confirmed, 'used_at' => $claude];
        $hygieneAccounts = [
            $account(1, 2, '2026-10-01 10:00:00'),                                 // the active administrator
            $account(2, 2, '2026-06-01 10:00:00'),                                 // an administrator unused for 123 days
            $account(3, 1, '2026-07-04 11:59:59'),                                 // one second over 90 days
            $account(4, 1, '2026-07-04 12:00:00'),                                 // exactly 90 days – still fine
            $account(5, 0, null, '2026-05-01 00:00:00'),                           // never signed in, created long ago
            $account(6, 0, null, '2026-09-20 00:00:00'),                           // invited recently, not signed in yet
            $account(7, 1, '2026-01-01 00:00:00', '2026-02-01 00:00:00', '2026-09-30 08:00:00'), // old sign-in, but Claude used the account
            $account(8, 1, '2026-01-01 00:00:00', null, null, 1),                  // already blocked
            $account(9, 1, null),                                                   // nothing is known – never treated as unused
        ];
        $hygieneUnused = \Talea\Core\SecurityHygiene::unusedAccounts($hygieneAccounts, $hygieneNow);
        $this->assertSame([2, 3, 5], array_column($hygieneUnused, 'user_id'), 'Hygiene: unused accounts by sign-in, confirmation and Claude use, over 90 days only');
        $this->assertSame('2026-09-30 08:00:00', \Talea\Core\SecurityHygiene::lastActivity($hygieneAccounts[6]), 'Hygiene: the last activity is the latest of the three moments');
        $this->assertNull(\Talea\Core\SecurityHygiene::lastActivity($hygieneAccounts[8]), 'Hygiene: an account with no record is unknown, not unused');
        $this->assertSame([2, 5], array_column(\Talea\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 3), 'user_id'), 'Hygiene: the signed-in user is never blocked');
        $this->assertSame([2, 3, 5], array_column(\Talea\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 0), 'user_id'), 'Hygiene: an unused administrator is blocked while another administrator stays active');
        $hygieneAllOld = $hygieneAccounts;
        $hygieneAllOld[0] = $account(1, 2, '2026-06-15 10:00:00'); // now every administrator is unused: the most recently active one stays
        $hygieneUnusedAll = \Talea\Core\SecurityHygiene::unusedAccounts($hygieneAllOld, $hygieneNow);
        $this->assertSame([[1, 2, 3, 5], [2, 3, 5]], [array_column($hygieneUnusedAll, 'user_id'), array_column(\Talea\Core\SecurityHygiene::blockable($hygieneUnusedAll, $hygieneAllOld, 0), 'user_id')], 'Hygiene: every administrator unused – the last active one is kept');
        $hygieneOnlyAdmin = [$account(1, 2, '2026-01-01 00:00:00'), $account(2, 0, '2026-01-01 00:00:00')];
        $this->assertSame([2], array_column(\Talea\Core\SecurityHygiene::blockable(\Talea\Core\SecurityHygiene::unusedAccounts($hygieneOnlyAdmin, $hygieneNow), $hygieneOnlyAdmin, 0), 'user_id'), 'Hygiene: the only administrator is never blocked');
        $hygieneConnections = [
            ['kind' => 'token', 'id' => 1, 'user_id' => 1, 'username' => 'a', 'name' => 'laptop', 'last' => '2026-09-30 00:00:00', 'expiry' => null],
            ['kind' => 'token', 'id' => 2, 'user_id' => 1, 'username' => 'a', 'name' => 'old', 'last' => '2026-08-03 11:59:59', 'expiry' => null],
            ['kind' => 'app', 'id' => 'abc', 'user_id' => 2, 'username' => 'b', 'name' => 'Claude', 'last' => '2026-08-01 00:00:00', 'expiry' => '2026-10-20 00:00:00'],
            ['kind' => 'app', 'id' => 'def', 'user_id' => 2, 'username' => 'b', 'name' => 'Claude', 'last' => '2026-08-03 12:00:00', 'expiry' => null],
        ];
        $this->assertSame(['token:2', 'app:abc'], array_map(fn (array $c): string => $c['kind'] . ':' . $c['id'], \Talea\Core\SecurityHygiene::unusedConnections($hygieneConnections, $hygieneNow)), 'Hygiene: connections unused for over 60 days');
        $this->assertSame(123, \Talea\Core\SecurityHygiene::daysAgo('2026-06-01 10:00:00', $hygieneNow), 'Hygiene: days ago for messages');
        $this->assertSame([90, 60], [\Talea\Core\SecurityHygiene::ACCOUNT_DAYS, \Talea\Core\SecurityHygiene::CONNECTION_DAYS], 'Hygiene: thresholds are constants');
    }
}
