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

    public function testForwardedForOnlyFromTrustedProxy(): void
    {
        putenv('TALEA_TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12');
        try {
            $ip = fn (string $remote, ?string $xff) => Antispam::visitorIp(['REMOTE_ADDR' => $remote] + ($xff === null ? [] : ['HTTP_X_FORWARDED_FOR' => $xff]), '');
            $this->assertSame(['203.0.113.5', '198.51.100.8'], [$ip('172.18.0.2', '203.0.113.5'), $ip('172.18.0.2', '198.51.100.8')], 'visitors behind one proxy get their own address');
            $this->assertSame('203.0.113.9', $ip('172.18.0.2', '1.2.3.4, 203.0.113.9'), 'a spoofed left-most entry is ignored');
            $this->assertSame('203.0.113.9', $ip('172.18.0.2', '1.2.3.4, 203.0.113.9, 10.0.0.7'), 'inner trusted proxies are skipped');
            $this->assertSame('203.0.113.50', $ip('203.0.113.50', '1.2.3.4'), 'an untrusted peer cannot choose its address');
            $this->assertSame('172.18.0.2', $ip('172.18.0.2', '203.0.113.9, junk'), 'an invalid entry falls back to the peer');
            $this->assertSame('172.18.0.2', $ip('172.18.0.2', null), 'no header: the peer');
            $this->assertSame('203.0.113.5', (new \Talea\Core\Request([], [], ['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']))->ip(), 'Request::ip()');
        } finally {
            putenv('TALEA_TRUSTED_PROXIES');
        }
        $this->assertSame('172.18.0.2', Antispam::visitorIp(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], ''), 'unset: the old behaviour');
    }

    public function testCloudflareBehindTrustedProxy(): void
    {
        putenv('TALEA_TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12');
        try {
            $ip = fn (string $xff) => Antispam::visitorIp(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => $xff, 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], 'cloudflare');
            $this->assertSame('198.51.100.7', $ip('172.70.1.2'), 'Cloudflare IPv4 behind the proxy: the client');
            $this->assertSame('198.51.100.7', $ip('2606:4700::1111'), 'Cloudflare IPv6 behind the proxy: the client');
            $this->assertSame('203.0.113.9', $ip('203.0.113.9'), 'not a Cloudflare address: its header is not believed');
            $this->assertSame('172.18.0.2', $ip('1.2.3.4:5678'), 'an entry with a port is invalid: the peer');
            $this->assertSame('172.18.0.2', $ip('[2001:db8::1]:443'), 'brackets and port: the peer');
        } finally {
            putenv('TALEA_TRUSTED_PROXIES');
        }
    }
}
