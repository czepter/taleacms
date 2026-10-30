<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Auth;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Several owners, stage B: operators (administrators of owner 1), the protected row that stands for one in another owner, and the handoff across owners. */
#[CoversClass(Owners::class)]
final class OwnerOperatorsTest extends DatabaseTestCase
{
    private static bool $made = false;

    protected function setUp(): void
    {
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                putenv('TALEA_MULTISITE=1');
                putenv('TALEA_OWNERS=1');
                $db = $this->db();
                Owners::add($db, 'Beta', 'organisation', 'boss@beta.example');
                $lines = Owners::provisionPending($db, $config);
                if ($lines !== ['owner tlb_: ready']) {
                    throw new \RuntimeException(implode("\n", $lines));
                }
                Sites::add($db, 'Copy', 'copy', 1, true);
                Migrator::migrate($config);
                self::$made = true;
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
    }

    protected function tearDown(): void
    {
        putenv('TALEA_OWNERS');
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$made = false;
        parent::tearDownAfterClass();
    }

    /** @return array<string, mixed> an administrator of owner 1 */
    private function operator(string $name = 'op', int $admin = Auth::ADMIN): array
    {
        $id = $this->db()->insert('users', ['username' => $name, 'password' => 'x', 'name' => 'Ola Operator', 'email' => $name . '@a.example', 'admin' => $admin, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);

        return (array) $this->db()->one('SELECT * FROM {users} WHERE user_id = ?', [$id]);
    }

    private function beta(): \Talea\Core\Db
    {
        $beta = $this->db()->forOwner('tlb_');
        if ($beta->value('SELECT 1 FROM {site_domains} WHERE site_id = 1') === null) {
            $beta->insert('site_domains', ['site_id' => 1, 'host' => 'beta.example', 'is_primary' => true, 'redirect_to_primary' => false]);
        }

        return $beta;
    }

    public function testTheOwnerRowKeepsTheEmailOfItsFirstAdministrator(): void
    {
        $this->assertSame('boss@beta.example', $this->db()->value("SELECT admin_email FROM {owners} WHERE prefix = 'tlb_'"));
        $this->assertSame('', $this->db()->value("SELECT admin_email FROM {owners} WHERE prefix = 'tl_'"));
        $this->assertSame('', Owners::publicIdOf($this->db(), 'tl_'), 'owner 1 keeps media/Y/m');
        $this->assertSame(36, strlen(Owners::publicIdOf($this->db(), 'tlb_')));
        $this->assertSame('Beta', Owners::nameOf($this->db(), 'tlb_'));
    }

    public function testTheOperatorRowIsFlaggedMadeOnceAndCannotSignInWithAPassword(): void
    {
        $op = $this->operator();
        $beta = $this->beta();
        $id = Owners::operatorRow($beta, $op);
        $row = (array) $beta->one('SELECT * FROM {users} WHERE user_id = ?', [$id]);
        $this->assertSame([(int) $op['user_id'], Auth::ADMIN, '', 0], [(int) $row['operator_user_id'], (int) $row['admin'], $row['email'], (int) $row['blocked']]);
        $this->assertStringStartsWith('Operator: Ola', (string) $row['name']);
        $this->assertSame($id, Owners::operatorRow($beta, $op), 'one row per operator');
        $this->assertSame(1, (int) $beta->value('SELECT COUNT(*) FROM {users} WHERE operator_user_id = ?', [$op['user_id']]));

        // a blocked row is released again; a client user that took the name does not stop it
        $beta->update('users', ['blocked' => 1], ['user_id' => $id]);
        $this->assertSame($id, Owners::operatorRow($beta, $op));
        $this->assertSame(0, (int) $beta->value('SELECT blocked FROM {users} WHERE user_id = ?', [$id]));
        $other = $this->operator('op-two');
        $beta->insert('users', ['username' => 'operator-' . $other['user_id'], 'password' => 'x', 'name' => 'Client', 'email' => 'c@b.example', 'admin' => 0, 'language' => '']);
        $second = Owners::operatorRow($beta, $other);
        $this->assertNotSame('operator-' . $other['user_id'], $beta->value('SELECT username FROM {users} WHERE user_id = ?', [$second]));

        // the password sign-in does not see the row (Auth::account), whatever name or e-mail is typed
        $session = new \Talea\Core\Session(false, '/', 'tlb_', 'tl_');
        $auth = new Auth($beta, $session);
        $this->assertNotNull($auth->login((string) $row['username'], 'anything at all', '203.0.113.9'));
        $this->assertNull($beta->value('SELECT last_login_at FROM {users} WHERE user_id = ?', [$id]));
    }

    public function testOperatorsAreTheUnblockedAdministratorsOfOwnerOne(): void
    {
        $this->assertTrue(Owners::operatorValid($this->db(), (int) $this->operator('v1')['user_id']));
        $editor = $this->operator('v2', Auth::EDITOR);
        $this->assertFalse(Owners::operatorValid($this->db(), (int) $editor['user_id']));
        $blocked = $this->operator('v3');
        $this->db()->update('users', ['blocked' => 1], ['user_id' => (int) $blocked['user_id']]);
        $this->assertFalse(Owners::operatorValid($this->db(), (int) $blocked['user_id']));
        $this->assertFalse(Owners::operatorValid($this->db(), 987654));
        $this->assertTrue(Owners::operatorValid($this->beta(), (int) $this->operator('v4')['user_id']), 'asked from any owner: owner 1 decides');

        $op = $this->operator('v5');
        $this->assertTrue(Owners::isOperator($this->db(), $op));
        $this->assertFalse(Owners::isOperator($this->db(), $editor));
        $this->assertFalse(Owners::isOperator($this->db(), null));
        $this->assertFalse(Owners::isOperator($this->beta(), ['user_id' => 1, 'admin' => Auth::ADMIN, 'blocked' => 0]), 'a client administrator is no operator');
        $row = (array) $this->beta()->one('SELECT * FROM {users} WHERE user_id = ?', [Owners::operatorRow($this->beta(), $op)]);
        $this->assertTrue(Owners::isOperator($this->beta(), $row), 'the row that stands for an operator is one while the operator is valid');
        $this->db()->update('users', ['admin' => Auth::EDITOR], ['user_id' => (int) $op['user_id']]);
        $this->assertFalse(Owners::isOperator($this->beta(), $row));
        putenv('TALEA_OWNERS');
        $this->assertFalse(Owners::isOperator($this->db(), $this->operator('v6')), 'nothing without the flag');
    }

    public function testTheHandoffIsWrittenInTheTargetOwnerBoundToItsSiteAndUsedOnce(): void
    {
        $op = $this->operator('h1');
        $beta = $this->beta();
        $ownerId = (string) $this->db()->value("SELECT public_id FROM {owners} WHERE prefix = 'tlb_'");
        $siteId = (string) $beta->value('SELECT public_id FROM {sites} WHERE site_id = 1');

        $made = Owners::handoff($this->db(), $op, $ownerId, $siteId);
        $this->assertIsArray($made);
        [$token, $host] = $made;
        $this->assertSame('beta.example', $host);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {handoff_tokens}'), 'owner 1\'s table is not touched');
        $row = (array) $beta->one('SELECT * FROM {handoff_tokens} WHERE token_hash = ?', [hash('sha256', $token)]);
        $this->assertSame(1, (int) $row['site_id']);
        $this->assertLessThanOrEqual(time() + Sites::HANDOFF_SECONDS, strtotime($row['expires_at'] . ' UTC'), 'at most sixty seconds, in UTC');
        $this->assertGreaterThan(time() + 50, strtotime($row['expires_at'] . ' UTC'));
        $userRow = (array) $beta->one('SELECT operator_user_id, admin FROM {users} WHERE user_id = ?', [$row['user_id']]);
        $this->assertSame([(int) $op['user_id'], Auth::ADMIN], [(int) $userRow['operator_user_id'], (int) $userRow['admin']]);

        // bound to the owner, the site and one use
        $this->assertNull(Sites::claimHandoff($this->db(), $token, 1), 'owner 1 does not know it');
        $this->assertNull(Sites::claimHandoff($beta, $token, 2), 'another site of the owner does not');
        $this->assertSame((int) $row['user_id'], Sites::claimHandoff($beta, $token, 1));
        $this->assertNull(Sites::claimHandoff($beta, $token, 1), 'used up');
        [$late] = Owners::handoff($this->db(), $op, $ownerId, $siteId);
        $beta->update('handoff_tokens', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['token_hash' => hash('sha256', $late)]);
        $this->assertNull(Sites::claimHandoff($beta, $late, 1), 'expired');
    }

    public function testNobodyButAnOperatorGetsAHandoffAndNeverToAnUnfitTarget(): void
    {
        $beta = $this->beta();
        $ownerId = (string) $this->db()->value("SELECT public_id FROM {owners} WHERE prefix = 'tlb_'");
        $siteId = (string) $beta->value('SELECT public_id FROM {sites} WHERE site_id = 1');
        $count = fn (): int => (int) $beta->value('SELECT COUNT(*) FROM {handoff_tokens}');
        $before = $count();

        $this->assertIsString(Owners::handoff($this->db(), $this->operator('n1', Auth::EDITOR), $ownerId, $siteId), 'an editor');
        $blocked = $this->operator('n2');
        $this->db()->update('users', ['blocked' => 1], ['user_id' => (int) $blocked['user_id']]);
        $this->assertIsString(Owners::handoff($this->db(), $blocked, $ownerId, $siteId), 'a blocked administrator');
        $this->assertIsString(Owners::handoff($beta, $this->operator('n3'), $ownerId, $siteId), 'asked from inside a client owner');
        $op = $this->operator('n4');
        $this->assertIsString(Owners::handoff($this->db(), $op, 'no-such-owner', $siteId));
        $this->assertIsString(Owners::handoff($this->db(), $op, (string) $this->db()->value("SELECT public_id FROM {owners} WHERE prefix = 'tl_'"), $siteId), 'owner 1 itself');
        $this->assertIsString(Owners::handoff($this->db(), $op, $ownerId, '3c2b6a52-0d42-4a43-9a0e-5f5f0c1f7a11'), 'a site of nobody');
        $this->assertIsString(Owners::handoff($this->db(), $op, $ownerId, (string) $this->db()->value('SELECT public_id FROM {sites} WHERE site_id = 1')), 'owner 1\'s own site id');
        Owners::setStatus($this->db(), 'tlb_', 'paused');
        $this->assertIsString(Owners::handoff($this->db(), $op, $ownerId, $siteId), 'a paused owner');
        Owners::setStatus($this->db(), 'tlb_', 'live');
        putenv('TALEA_OWNERS');
        $this->assertIsString(Owners::handoff($this->db(), $op, $ownerId, $siteId), 'without the flag');
        $this->assertSame($before, $count(), 'not one token was written');
    }

    public function testRevokingAnOperatorRemovesTheirRowsFromTheOtherOwnersOnly(): void
    {
        $beta = $this->beta();
        $a = $this->operator('r1');
        $b = $this->operator('r2');
        $rowA = Owners::operatorRow($beta, $a);
        $rowB = Owners::operatorRow($beta, $b);
        $this->assertSame(1, Owners::revokeOperator($this->db(), (int) $a['user_id']));
        $this->assertNull($beta->value('SELECT 1 FROM {users} WHERE user_id = ?', [$rowA]));
        $this->assertNotNull($beta->value('SELECT 1 FROM {users} WHERE user_id = ?', [$rowB]), 'the other operator keeps theirs');
        $this->assertNotNull($this->db()->value('SELECT 1 FROM {users} WHERE user_id = ?', [(int) $a['user_id']]), 'owner 1\'s own user is not touched here');
        // a client owner calling it with a number that happens to be an operator's: nothing
        $client = $beta->insert('users', ['username' => 'client-r', 'password' => 'x', 'name' => 'C', 'email' => 'c@b.example', 'admin' => 2, 'language' => '']);
        $this->assertSame(0, Owners::revokeOperator($beta, (int) $b['user_id']));
        $this->assertNotNull($beta->value('SELECT 1 FROM {users} WHERE user_id = ?', [$rowB]));
        $this->assertNotNull($beta->value('SELECT 1 FROM {users} WHERE user_id = ?', [$client]));
    }

    public function testCopyingASiteLeavesTheInstallTablesAlone(): void
    {
        // a clone INSERT … SELECTs every table of the source prefix except owner tables: the install's owners table must not be one of them
        $before = $this->db()->value('SELECT COUNT(*) FROM {owners}');
        $tables = $this->db()->dialect()->dumpTables($this->db()->pdo(), 'tl_');
        $this->assertContains('tl_owners', $tables, 'the install table sits under the install prefix, like site 1\'s tables');
        Sites::copyTables($this->db()->forSite('tl_'), $this->db()->forSite('tl2_'));
        $this->assertEquals($before, $this->db()->value('SELECT COUNT(*) FROM {owners}'));
    }
}
