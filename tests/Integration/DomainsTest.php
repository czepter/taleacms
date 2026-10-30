<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Domains;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Multisite, domains (docs/specs/multisite.md, "Domains and certificates"): verification states, the two checks, previews. Owner B with a site of its own is in the class's database. */
#[CoversClass(Domains::class)]
#[CoversClass(Sites::class)]
final class DomainsTest extends DatabaseTestCase
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
                $b = Owners::add($db, 'Beta', 'organisation');
                Owners::provisionPending($db, $config);
                Sites::add($db->forOwner($b), 'Beta shop', 'beta-shop');
                Migrator::migrate($config);
                self::$made = true;
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
    }

    protected function tearDown(): void
    {
        foreach (['TALEA_MULTISITE', 'TALEA_OWNERS', 'TALEA_PREVIEW_DOMAIN', 'TALEA_VERIFIED_HOSTS', 'TALEA_LINKS_LOCAL'] as $name) {
            putenv($name);
        }
        Domains::$fetch = Domains::$txt = null;
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$made = false;
        parent::tearDownAfterClass();
    }

    /** @return array<string, mixed> */
    private function domain(string $host): array
    {
        return (array) $this->db()->one('SELECT * FROM {site_domains} WHERE host = ?', [$host]);
    }

    public function testAHostAddedWithoutVerificationIsVerifiedAtOnce(): void
    {
        $this->assertNull(Sites::addDomain($this->db(), 1, 'one.example'));
        $d = $this->domain('one.example');
        $this->assertNotNull($d['verified_at']);
        $this->assertNull($d['token']);
        $this->assertSame('domain', $d['kind']);
        $this->assertTrue(Sites::resolve($this->db(), 'one.example')['verified']);
    }

    public function testAHostThatMustBeVerifiedStartsWithATokenAndDoesNotCount(): void
    {
        $this->assertNull(Sites::addDomain($this->db(), 1, 'client.example', true));
        $d = $this->domain('client.example');
        $this->assertNull($d['verified_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $d['token']);
        $this->assertFalse(Sites::resolve($this->db(), 'client.example')['verified']);
        $this->assertTrue((bool) $d['is_primary'], 'the first host is primary, verified or not');
    }

    public function testNeedsVerificationOnlyWithSeveralOwnersAndNotForOperators(): void
    {
        $client = ['admin' => 2, 'operator_user_id' => null, 'blocked' => 0];
        $this->assertFalse(Domains::needsVerification($this->db(), $client), 'one owner: hosts are added verified');
        putenv('TALEA_OWNERS=1');
        $this->assertFalse(Domains::needsVerification($this->db(), $client), 'owner 1 administrators are the operators');
        $this->assertTrue(Domains::needsVerification($this->db()->forOwner('tlb_'), $client), 'a client administrator');
        $this->assertTrue(Domains::needsVerification($this->db()->forOwner('tlb_'), null));
    }

    public function testTheEnvironmentVouchesForHosts(): void
    {
        $this->assertFalse(Domains::envVerified('vouched.example'));
        putenv('TALEA_VERIFIED_HOSTS=Other.example, vouched.example');
        $this->assertTrue(Domains::envVerified('vouched.example'));
        Sites::addDomain($this->db(), 1, 'vouched.example', true);
        $this->assertTrue(Sites::resolve($this->db(), 'vouched.example')['verified']);
    }

    public function testTheHttpCheckVerifiesWhenTheHostAnswersWithTheToken(): void
    {
        putenv('TALEA_LINKS_LOCAL=1');
        Sites::addDomain($this->db(), 1, '127.0.0.1', true); // the one name the tests may fetch
        $d = $this->domain('127.0.0.1');
        $asked = [];
        Domains::$fetch = function (array $target) use (&$asked, $d): ?string {
            $asked[] = $target['url'];

            return $d['token'] . "\n";
        };
        $this->assertNull(Domains::check($this->db(), $d));
        $this->assertSame(['http://127.0.0.1/.well-known/talea-domain/' . $d['token']], $asked);
        $after = $this->domain('127.0.0.1');
        $this->assertNotNull($after['verified_at']);
        $this->assertNull($after['token']);
    }

    public function testAWrongAnswerAndNoRecordLeaveTheHostUnverified(): void
    {
        putenv('TALEA_LINKS_LOCAL=1');
        Sites::addDomain($this->db(), 1, '127.0.0.1', true);
        Domains::$fetch = fn (array $target): ?string => 'someone else';
        Domains::$txt = fn (string $name): array => ['talea-verify=0000'];
        $this->assertNotNull(Domains::check($this->db(), $this->domain('127.0.0.1')));
        $this->assertNull($this->domain('127.0.0.1')['verified_at']);
    }

    public function testTheDnsRecordVerifiesWhenTheTokenFileDoesNot(): void
    {
        putenv('TALEA_LINKS_LOCAL=1');
        Sites::addDomain($this->db(), 1, '127.0.0.1', true);
        $d = $this->domain('127.0.0.1');
        Domains::$fetch = fn (array $target): ?string => null;
        $names = [];
        Domains::$txt = function (string $name) use (&$names, $d): array {
            $names[] = $name;

            return ['v=spf1 -all', 'talea-verify=' . $d['token']];
        };
        $this->assertNull(Domains::check($this->db(), $d));
        $this->assertSame(['_talea.127.0.0.1'], $names);
        $this->assertNotNull($this->domain('127.0.0.1')['verified_at']);
    }

    public function testTheHttpCheckRefusesInternalAddressesWithoutRequestingAnything(): void
    {
        putenv('TALEA_LINKS_LOCAL'); // off, as on a real site
        $called = false;
        Domains::$fetch = function (array $target) use (&$called): ?string {
            $called = true;

            return null;
        };
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '172.16.0.1', '100.64.0.1', '0.0.0.0', '[::1]', 'fd00::1', '::ffff:127.0.0.1', 'localhost', '2130706433', '0x7f.1'] as $host) {
            $this->assertNotNull(Domains::viaHttp($host, str_repeat('a', 32)), $host . ' must not be fetched');
        }
        $this->assertFalse($called, 'nothing left the server');
    }

    public function testAnOperatorCanMarkAHostVerified(): void
    {
        Sites::addDomain($this->db(), 1, 'vouch.example', true);
        $d = $this->domain('vouch.example');
        Domains::markVerified($this->db(), 1, (int) $d['domain_id']);
        $this->assertNotNull($this->domain('vouch.example')['verified_at']);
        $this->assertNull($this->domain('vouch.example')['token']);
    }

    public function testPreviewHostNamesFollowTheOwner(): void
    {
        $this->assertNull(Domains::previewHost($this->db(), 'tl_', 'shop'), 'no preview domain');
        putenv('TALEA_PREVIEW_DOMAIN=*.Preview.Example');
        $this->assertSame('shop.preview.example', Domains::previewHost($this->db(), 'tl_', 'shop'));
        $this->assertSame('shop.b.preview.example', Domains::previewHost($this->db(), 'tlb_', 'shop'));
        $this->assertSame('shop.ba.preview.example', Domains::previewHost($this->db(), 'tlba_', 'shop'));
    }

    public function testPreviewsAreAddedOnceForEverySiteOfEveryOwner(): void
    {
        putenv('TALEA_OWNERS=1');
        $this->assertSame(0, Domains::ensurePreviews($this->db()), 'no preview domain: nothing');
        putenv('TALEA_PREVIEW_DOMAIN=preview.example');
        $sites = (int) $this->db()->value('SELECT COUNT(*) FROM {sites} WHERE ready_at IS NOT NULL') + (int) $this->db()->forOwner('tlb_')->value('SELECT COUNT(*) FROM {sites} WHERE ready_at IS NOT NULL');
        $this->assertSame($sites, Domains::ensurePreviews($this->db()));
        $this->assertSame(0, Domains::ensurePreviews($this->db()), 'once');
        $one = $this->domain('main.preview.example');
        $this->assertSame(['preview', false], [$one['kind'], (bool) $one['is_primary']]);
        $this->assertNotNull($one['verified_at']);
        $this->assertNotNull(Sites::resolve($this->db(), 'main.b.preview.example'));
        $this->assertSame('tlb_', Sites::resolve($this->db(), 'main.b.preview.example')['owner_prefix']);
        $this->assertSame('preview', Sites::resolve($this->db(), 'main.preview.example')['kind']);
    }

    public function testThePreviewHostIsNotTheFirstDomainAndAnswersWhenThereIsNoOther(): void
    {
        putenv('TALEA_PREVIEW_DOMAIN=preview.example');
        Domains::ensurePreviews($this->db());
        $this->assertSame('main.preview.example', Sites::primaryHost($this->db(), 1), 'the only host that answers');
        Sites::addDomain($this->db(), 1, 'first.example', true);
        $this->assertTrue((bool) $this->domain('first.example')['is_primary'], 'a preview row does not count as the first domain');
        $this->assertSame('main.preview.example', Sites::primaryHost($this->db(), 1), 'the primary host is not verified: the preview one answers');
        Domains::markVerified($this->db(), 1, (int) $this->domain('first.example')['domain_id']);
        $this->assertSame('first.example', Sites::primaryHost($this->db(), 1));
        $preview = $this->domain('main.preview.example');
        $this->assertNotNull(Sites::removeDomain($this->db(), 1, (int) $preview['domain_id'], ''), 'the preview host cannot be removed');
    }

    public function testNamesInThePreviewZoneCannotBeAddedAndNotVerifiedOverHttp(): void
    {
        putenv('TALEA_PREVIEW_DOMAIN=preview.example');
        foreach ([true, false] as $verify) { // a client (must verify) and an operator (does not)
            foreach (['x.preview.example', 'a.b.preview.example', 'preview.example'] as $host) {
                $this->assertNotNull(Sites::addDomain($this->db(), 1, $host, $verify), $host);
                $this->assertSame([], $this->domain($host));
            }
        }
        $this->assertNull(Sites::addDomain($this->db(), 1, 'notpreview.example', true), 'a name that only ends alike is fine');
        // a row that exists anyway (older data): the HTTP method must not run, the token file would be answered by this install
        $this->db()->insert('site_domains', ['site_id' => 1, 'host' => 'old.preview.example', 'is_primary' => false, 'redirect_to_primary' => false, 'token' => Domains::newToken(), 'verified_at' => null]);
        $d = $this->domain('old.preview.example');
        $asked = 0;
        Domains::$fetch = function () use (&$asked, $d): ?string {
            $asked++;

            return $d['token'];
        };
        Domains::$txt = fn (): array => [];
        $this->assertNotNull(Domains::check($this->db(), $d));
        $this->assertSame(0, $asked);
        $this->assertNull($this->domain('old.preview.example')['verified_at']);
        Domains::$txt = fn (): array => [Domains::txtValue((string) $d['token'])];
        $this->assertNull(Domains::check($this->db(), $d), 'DNS stays allowed');
    }
}
