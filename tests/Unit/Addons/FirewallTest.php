<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;

final class FirewallTest extends TestCase
{
    /** the firewall add-on (issue #29) */
    public function testListsCountriesProbesAndLocalAddresses(): void
    {
        require_once TALEA_ROOT . '/extensions/firewall/Firewall.php';

        $firewall = \TaleaAddon\Firewall\Firewall::class;
        $this->assertSame([[['203.0.113.7', '198.51.100.0/24', '2001:db8::/32'], ['nonsense', '10.0.0.0/4']], false], [$firewall::parseList("203.0.113.7 # bot\n\n198.51.100.0/24\nnonsense\n10.0.0.0/4\n2001:db8::/32"), $firewall::isValidEntry('300.1.1.1')], 'Firewall: parseList and isValidEntry');
        $this->assertSame(['RU', '', 'CN', '', ['RU', 'CN', 'DE'], true, false], [$firewall::country(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_IPCOUNTRY' => 'ru'], 'cloudflare'), $firewall::country(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_IPCOUNTRY' => 'RU'], 'cloudflare'),
            $firewall::country(['GEOIP_COUNTRY_CODE' => 'CN'], ''), $firewall::country(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_IPCOUNTRY' => 'XX'], 'cloudflare'), $firewall::countries("ru, CN;\nde x"),
            $firewall::countryBlocked('RU', 'RU, CN'), $firewall::countryBlocked('', 'RU')], 'Firewall: country and countries');
        $this->assertSame([true, true, true, true, true, true, false, false, false, false, false, false], array_map(fn (string $p): bool => preg_match($firewall::PROBE_PATHS, $p) === 1,
            ['wp-login.php', 'xmlrpc.php', '.env', 'app/.git/config', 'phpmyadmin/index.php', 'backup.sql', 'wp-content/uploads/2020/05/foto.jpg', 'image/logo.png', 'robots.txt', 'sitemap.xml', 'o-nas', '.well-known/security.txt']), 'Firewall: PROBE_PATHS – probes count, missing images and old WordPress uploads never');
        $this->assertSame([true, true, true, true, false, false], array_map($firewall::isLocal(...), ['127.0.0.1', '10.0.0.5', '192.168.1.1', '::1', '203.0.113.7', '2a00:1450::1']), 'Firewall: isLocal');
    }
}
