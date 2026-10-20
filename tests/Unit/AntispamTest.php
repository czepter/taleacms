<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Antispam;

final class AntispamTest extends TestCase
{
    public function testClientAddress(): void
    {
        // the client address helper (issue #29, was Core\Firewall): networks, the address behind Cloudflare only from Cloudflare
        $this->assertSame([true, false, true, true, false, true, false, false, false], [Antispam::inList('198.51.100.77', ['198.51.100.0/24']), Antispam::inList('198.51.101.1', ['198.51.100.0/24']), Antispam::inList('203.0.113.7', ['203.0.113.7']),
            Antispam::inList('10.1.2.3', ['10.0.0.0/9']), Antispam::inList('10.200.0.1', ['10.0.0.0/9']), Antispam::inList('2001:db8::1', ['2001:db8::/32']), Antispam::inList('2001:db9::1', ['2001:db8::/32']),
            Antispam::inList('not-an-ip', ['0.0.0.0/8']), Antispam::inList('198.51.100.7', ['2001:db8::/32'])], 'Antispam::inList');
        $this->assertSame(['203.0.113.9', '203.0.113.50', '172.70.1.2', '172.70.1.2'], [
            Antispam::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], 'cloudflare'),
            Antispam::visitorIp(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'], 'cloudflare'),
            Antispam::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], ''),
            Antispam::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'junk'], 'cloudflare')], 'Antispam::visitorIp – Cloudflare only from its addresses');
    }

    /** spam protection: IP hash */
    public function testIpHash(): void
    {
        $this->assertFalse(str_contains(\Talea\Core\Antispam::hash('203.0.113.7'), '203'), 'Antispam::hash: it is not the IP address');
        $this->assertSame(\Talea\Core\Antispam::hash('203.0.113.7'), \Talea\Core\Antispam::hash('203.0.113.7'), 'Antispam::hash: the same address = the same hash');
    }
}
