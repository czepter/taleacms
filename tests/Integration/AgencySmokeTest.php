<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\App;
use Talea\Core\Migrator;
use Talea\Core\Owners;
use Talea\Core\Scheduler;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Multisite, agency tools: the size an agency reaches (docs/specs/multisite.md, stage D) – two owners with ten sites each. Everything that
 * makes tables (DDL ends a transaction on MySQL) is done once before the tests: the migration of all 20 sites, their set-up, a host name
 * for each and one scheduler pass on every site but owner 1's first (its owner jobs write the install's backup folder). The tests then read.
 */
#[CoversClass(Sites::class)]
#[CoversClass(Owners::class)]
final class AgencySmokeTest extends DatabaseTestCase
{
    private const int PER_OWNER = 10;

    /** Seconds the whole preparation may take on a CI machine. */
    private const int BUDGET = 240;

    private static bool $made = false;

    /** @var array<string, float> */
    private static array $seconds = [];

    /** @var list<string> the prefixes of all 20 sites */
    private static array $prefixes = [];

    /** @var array<string, array<string, string>> prefix => job => result of the scheduler pass */
    private static array $passes = [];

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
                $started = microtime(true);
                $db = $this->db();
                $b = Owners::add($db, 'Beta', 'organisation');
                Owners::provisionPending($db, $config);
                foreach ([$db, $db->forOwner($b)] as $owner) {
                    for ($i = 2; $i <= self::PER_OWNER; $i++) {
                        Sites::add($owner, 'Client ' . $i, 'client-' . $i);
                    }
                }
                self::$seconds['register'] = microtime(true) - $started;
                Migrator::migrate($config);
                self::$seconds['migrate'] = microtime(true) - $started;
                foreach ([$db, $db->forOwner($b)] as $owner) {
                    Sites::provisionPending($owner, $config);
                    foreach ($owner->all('SELECT site_id, prefix FROM {sites} ORDER BY site_id') as $s) {
                        $host = 'site' . $s['site_id'] . '.' . ($owner->ownerPrefix === $db->installPrefix ? 'one' : 'beta') . '.example';
                        if (Sites::addDomain($owner, (int) $s['site_id'], $host) !== null) {
                            throw new \RuntimeException('Host ' . $host . ' was refused.');
                        }
                        self::$prefixes[] = (string) $s['prefix'];
                    }
                }
                self::$seconds['provision'] = microtime(true) - $started;
                foreach (self::$prefixes as $prefix) {
                    if ($prefix === $db->installPrefix) {
                        continue;
                    }
                    $dbConfig = $config + [];
                    $isBeta = Owners::ownerOf($db->installPrefix, $prefix) === $b;
                    if ($isBeta) {
                        $dbConfig['owner_prefix'] = $b;
                    }
                    $dbConfig['site_prefix'] = $prefix;
                    $app = new App(['db' => $dbConfig]);
                    self::$passes[$prefix] = Scheduler::run($app, 'cron', 20.0);
                }
                self::$seconds['scheduler'] = microtime(true) - $started;
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
        self::$seconds = [];
        self::$prefixes = [];
        self::$passes = [];
        parent::tearDownAfterClass();
    }

    public function testTwentySitesAreMigratedAndSetUpWithinTheBudget(): void
    {
        $this->assertLessThan(self::BUDGET, self::$seconds['scheduler'], json_encode(self::$seconds, JSON_THROW_ON_ERROR));
        $this->assertCount(2 * self::PER_OWNER, self::$prefixes);
        $this->assertCount(2 * self::PER_OWNER, array_unique(self::$prefixes), 'no prefix is given out twice');
        $this->assertSame([], Migrator::pending($this->db()));
        foreach (Owners::connections($this->db()) as $c) {
            $this->assertSame([], Migrator::pending($c), $c->prefix);
        }
        $this->assertSame([], Sites::pending($this->db()));
        $this->assertSame([], Sites::pending($this->db()->forOwner('tlb_')));
        $this->assertSame(2 * self::PER_OWNER, Sites::installCount($this->db()));
    }

    public function testEveryHostRoutesToItsOwnSiteAndOwner(): void
    {
        foreach (['one' => 'tl_', 'beta' => 'tlb_'] as $name => $ownerPrefix) {
            for ($n = 1; $n <= self::PER_OWNER; $n++) {
                $route = Sites::resolve($this->db(), 'site' . $n . '.' . $name . '.example');
                $this->assertNotNull($route, "site{$n}.{$name}");
                $this->assertSame([$ownerPrefix, $n, true], [$route['owner_prefix'], (int) $route['site_id'], (bool) $route['ready']]);
                $this->assertSame($n === 1 ? $ownerPrefix : Sites::prefixFor($ownerPrefix, $n), $route['prefix']);
            }
        }
        $this->assertNull(Sites::resolve($this->db(), 'site11.one.example'));
    }

    public function testTheSchedulerRanOnEveryFurtherSite(): void
    {
        $this->assertCount(2 * self::PER_OWNER - 1, self::$passes, 'every site but owner 1\'s first');
        foreach (self::$passes as $prefix => $jobs) {
            $this->assertNotSame([], $jobs, $prefix . ' ran no job at all');
            foreach ($jobs as $job => $result) {
                $this->assertStringNotContainsString('error', strtolower((string) $result), $prefix . ' ' . $job . ': ' . $result);
            }
            $this->assertGreaterThan(0, (int) $this->db()->forOwner(Owners::ownerOf('tl_', $prefix) ?? 'tl_')->forSite($prefix)->value('SELECT COUNT(*) FROM {jobs}'), $prefix . ' recorded its jobs in its own table');
        }
    }
}
