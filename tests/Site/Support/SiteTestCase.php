<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class of the site tests (the former sections of tools/test.sh). One class = one installed site (see Site) that lives for the
 * class; the tests of a class run in the order they are written and may build on what earlier ones did, which is how a "cluster" of
 * old sections that shared state stays together. Classes share nothing, so they run in parallel (ParaTest).
 *
 * Skipped, not failed, when no MySQL is reachable.
 */
abstract class SiteTestCase extends TestCase
{
    private static ?Site $site = null;
    private static bool $unavailable = false;

    /** Installer options of the class: web, extensions, prefix, siteName. @return array<string, mixed> */
    protected static function siteOptions(): array
    {
        return [];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        try {
            new \PDO(sprintf('mysql:host=%s;port=%d', getenv('TALEA_TEST_DB_HOST'), (int) getenv('TALEA_TEST_DB_PORT')), (string) getenv('TALEA_TEST_DB_USER'), (string) getenv('TALEA_TEST_DB_PASSWORD'));
        } catch (\PDOException) {
            self::$unavailable = true;

            return;
        }
        self::$unavailable = false;
        self::$site = Site::boot(static::siteOptions());
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->close();
        self::$site = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$unavailable || self::$site === null) {
            $this->markTestSkipped('No MySQL reachable (start the db-test service: docker compose -f docker-compose-dev.yaml up -d db-test).');
        }
    }

    protected function site(): Site
    {
        return self::$site ?? throw new \LogicException('No site.');
    }

    // ---- assertions that read like the old shell helpers

    /**
     * The old check(): the page answers with the status, shows no PHP error and contains the text. Fetched as the signed-in administrator
     * (the old tests used one session for everything) unless another browser is given.
     *
     * @param string|list<string> $contains
     */
    protected function assertPage(string $path, int $status = 200, string|array $contains = [], ?Http $as = null, string $message = ''): Response
    {
        $response = ($as ?? $this->site()->admin())->get($path);
        $label = ($message !== '' ? $message . ' ' : '') . "($path)";
        $this->assertSame($status, $response->status, "$label: status");
        $this->assertFalse($response->hasPhpError(), "$label: shows a PHP error");
        foreach ((array) $contains as $needle) {
            $this->assertStringContainsString($needle, $response->body, "$label: text");
        }

        return $response;
    }

    /** @param string|list<string> $absent */
    protected function assertPageLacks(string $path, string|array $absent, ?Http $as = null, string $message = ''): Response
    {
        $response = ($as ?? $this->site()->admin())->get($path);
        foreach ((array) $absent as $needle) {
            $this->assertStringNotContainsString($needle, $response->body, ($message !== '' ? $message . ' ' : '') . "($path): must not contain");
        }

        return $response;
    }

    /** POST as the administrator with a fresh anti-forgery token taken from $formPage. @param array<string, mixed> $fields */
    protected function adminPost(string $path, array $fields = [], string $formPage = '/admin.php'): Response
    {
        $csrf = $this->site()->admin()->get($formPage)->csrf();

        return $this->site()->admin()->post($path, ['_csrf' => $csrf] + $fields);
    }
}
