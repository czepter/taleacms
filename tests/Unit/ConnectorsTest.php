<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConnectorsTest extends TestCase
{
    /** 2.13: outbound connectors (Core\Connectors) */
    public function testOutbound(): void
    {
        $this->assertSame([\Talea\Connectors\Google::class, null], [\Talea\Core\Connectors::service('google'), \Talea\Core\Connectors::service('evil')], '2.13 Connectors: the curated list, an unknown service is none');
        putenv('TALEA_CONNECTORS_FAKE=http://127.0.0.1:9');
        $fakeUrls = [\Talea\Core\Connectors::url('https://oauth2.googleapis.com/token'), \Talea\Core\Connectors::url('https://sheets.googleapis.com/v4/spreadsheets?x=1'), \Talea\Core\Connectors::url('http://example.com/a')];
        putenv('TALEA_CONNECTORS_FAKE');
        $this->assertSame([['http://127.0.0.1:9/token', 'http://127.0.0.1:9/v4/spreadsheets?x=1', 'http://example.com/a'], 'https://oauth2.googleapis.com/token'], [$fakeUrls, \Talea\Core\Connectors::url('https://oauth2.googleapis.com/token')], '2.13 Connectors::url – tests send every https call to the fake (path and query kept); without it the real address');
        $this->assertSame([5, 'oauth', 5, 0], [count(\Talea\Connectors\Google::SCOPES), \Talea\Connectors\Google::AUTH, count(\Talea\Core\Connectors::RETRY_DELAYS), \Talea\Core\Scheduler::JOBS['connectors'][0]], '2.13 Connectors: Google asks only for its listed scopes, offline, and the delivery queue retries five times');
    }
}
