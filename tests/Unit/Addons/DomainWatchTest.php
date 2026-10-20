<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;

final class DomainWatchTest extends TestCase
{
    /** the domain watch add-on (issue #28) – no network, the lookups are fixtures */
    public function testRecordsCertificatesAndHandover(): void
    {
        require_once TALEA_ROOT . '/extensions/domain_watch/DomainWatch.php';

        $watchClass = \TaleaAddon\DomainWatch\DomainWatch::class;
        $this->assertSame(['example.cz', 'example.co.uk', 'example.com', 'example.com.au', 'example.cz'], array_map($watchClass::registrableDomain(...), ['www.example.cz', 'shop.firma.example.co.uk', 'Example.COM', 'www.example.com.au', 'example.cz.']), 'DomainWatch: registrable domain – www., subdomains and two-level suffixes');
        $this->assertSame([true, false, false, false, false, false, false], array_map($watchClass::isPublicHost(...), ['www.example.cz', '127.0.0.1', 'localhost', 'web.test', 'talea.localhost', '::1', '']), 'DomainWatch: a public host is not an IP, localhost or a development suffix');
        $this->assertSame([true, false, true, false, true, null, null], [
            $watchClass::spfCovers('v=spf1 include:_spf.google.com ~all', 'example.cz', 'smtp.gmail.com'),
            $watchClass::spfCovers('v=spf1 include:_spf.google.com ~all', 'example.cz', 'smtp.seznam.cz'),
            $watchClass::spfCovers('v=spf1 a mx -all', 'example.cz', 'mail.example.cz'),
            $watchClass::spfCovers('v=spf1 a mx -all', 'example.cz', 'smtp.seznam.cz'),
            $watchClass::spfCovers('v=spf1 redirect=_spf.seznam.cz', 'example.cz', 'smtp.seznam.cz'),
            $watchClass::spfCovers('v=spf1 ip4:93.184.216.34 -all', 'example.cz', 'smtp.seznam.cz'),
            $watchClass::spfCovers('google-site-verification=abc', 'example.cz', 'smtp.seznam.cz'),
        ], 'DomainWatch: SPF covers the SMTP server – include of a known provider, a/mx in the own domain, redirect');
        $this->assertSame(['v=spf1 mx include:_spf.google.com ~all', 'v=spf1 a mx ~all', 'v=spf1 mx include:relay-xyz.io ~all', 'v=spf1 a mx ~all', 'v=spf1 mx a:hosting.net ~all'], [
            $watchClass::suggestedSpf('example.cz', 'smtp.gmail.com', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', 'mail.example.cz', 'www.example.cz'),
            $watchClass::suggestedSpf('example.cz', 'smtp.relay-xyz.io', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', '', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', '', 'web12.hosting.net'),
        ], 'DomainWatch: suggested SPF – provider include, own server, mail() from the web server');
        $this->assertSame(['v=DMARC1; p=none; rua=mailto:info@example.cz', 'v=DMARC1; p=none', ['p' => 'quarantine', 'rua' => 'mailto:dmarc@example.cz']], [$watchClass::suggestedDmarc('info@example.cz'), $watchClass::suggestedDmarc(''), $watchClass::parseDmarc('v=DMARC1; p=quarantine; rua=mailto:dmarc@example.cz; pct=100')], 'DomainWatch: suggested DMARC and parsing');
        $rdap = '{"objectClassName":"domain","ldhName":"example.cz","events":[{"eventAction":"registration","eventDate":"2010-05-01T10:00:00Z"},{"eventAction":"expiration","eventDate":"2027-03-14T10:00:00Z"}]}';
        $this->assertSame([gmmktime(10, 0, 0, 3, 14, 2027), null, null], [$watchClass::rdapExpiry($rdap), $watchClass::rdapExpiry('{"events":[{"eventAction":"registration","eventDate":"2010-05-01T10:00:00Z"}]}'), $watchClass::rdapExpiry('nonsense')], 'DomainWatch: RDAP expiry – the expiration event, none, invalid JSON');
        $this->assertSame(['ok', 'ok', 'warning', 'warning', 'error', 'error', 'error', 'ok'], array_map($watchClass::level(...), [60, 21, 20, 7, 6, 0, -3, null]), 'DomainWatch: thresholds – 21 days warning, 7 days error, unknown ok');
        $watchNow = 1_800_000_000;
        $watchDns = fn (string $name, int $type): array => match ($name) {
            'example.cz' => [['type' => 'TXT', 'txt' => 'google-site-verification=abc'], ['type' => 'TXT', 'txt' => 'v=spf1 include:_spf.google.com ~all']],
            '_dmarc.example.cz' => [['type' => 'TXT', 'txt' => 'v=DMARC1; p=none; rua=mailto:dmarc@example.cz']],
            'google._domainkey.example.cz' => [['type' => 'TXT', 'txt' => 'v=DKIM1; k=rsa; p=MIGfMA0G']],
            default => [],
        };
        $watchHttp = fn (string $url): array => $url === 'https://rdap.org/domain/example.cz' ? [200, json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => gmdate('c', $watchNow + 100 * 86400)]]])] : [404, ''];
        $watchSite = ['site_host' => 'www.example.cz', 'https' => true, 'mail_domain' => 'example.cz', 'smtp_host' => 'smtp.gmail.com', 'report_email' => 'info@example.cz'];
        $watchResult = (new $watchClass($watchDns, $watchHttp, fn (string $host): int => $watchNow + 15 * 86400))->collect($watchSite, $watchNow);
        $this->assertSame(['v=spf1 include:_spf.google.com ~all', true, true, 'google', 15, 100, 'example.cz'], [$watchResult['mail']['spf'], $watchResult['mail']['spf_covers_smtp'], $watchResult['mail']['dmarc'] !== null, $watchResult['mail']['dkim'], $watchResult['tls']['days'], $watchResult['domain']['days'], $watchResult['domain']['name']], 'DomainWatch: SPF, DMARC and DKIM found, SPF includes the SMTP server, days left');
        $this->assertSame([t('SPF record') => 'ok', t('DMARC record') => 'ok', t('DKIM signature') => 'ok', t('Certificate') => 'warning', t('Domain registration') => 'ok'], array_column($watchClass::rows($watchResult, false, $watchNow), 'status', 'name'), 'DomainWatch: rows – a certificate under 21 days is a warning, the rest ok');
        $this->assertSame(['certificate'], array_column($watchClass::handoverFindings($watchResult), 'key'), 'DomainWatch: handover lists only the certificate here');
        $watchBare = (new $watchClass(fn (): array => [], fn (): array => [404, ''], fn (): int => throw new \RuntimeException('refused')))->collect(['site_host' => 'www.example.cz', 'https' => true, 'mail_domain' => 'example.cz', 'smtp_host' => ''], $watchNow);
        $this->assertSame([null, null, null, null, null, 'refused'], [$watchBare['mail']['spf'], $watchBare['mail']['dmarc'], $watchBare['mail']['dkim'], $watchBare['domain']['expires'], $watchBare['domain']['error'], $watchBare['tls']['error']], 'DomainWatch: nothing in DNS – SPF and DMARC missing, DKIM not found, registry without RDAP is unknown, certificate error');
        $this->assertSame([true, true, ['warning', 'warning', 'ok', 'warning', 'ok']], [
            str_contains($watchClass::rows($watchBare, false, $watchNow)[0]['info'], 'v=spf1 a mx ~all'), str_contains($watchClass::rows($watchBare, false, $watchNow)[1]['info'], '_dmarc.example.cz'),
            array_column($watchClass::rows($watchBare, false, $watchNow), 'status'),
        ], 'DomainWatch: rows suggest the records to add; DKIM not found is a note, not a warning');
        $this->assertSame(['spf', 'dmarc'], array_column($watchClass::handoverFindings($watchBare), 'key'), 'DomainWatch: handover lists the certain problems only (not DKIM, not an unknown registry)');
        $watchExpired = (new $watchClass($watchDns, fn (string $url): array => [200, json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => gmdate('c', $watchNow - 86400)]]])], fn (string $host): int => $watchNow - 3 * 86400))->collect($watchSite, $watchNow);
        $this->assertSame(['error', 'error', ['certificate', 'domain']], [array_column($watchClass::rows($watchExpired, false, $watchNow), 'status', 'name')[t('Certificate')], array_column($watchClass::rows($watchExpired, false, $watchNow), 'status', 'name')[t('Domain registration')], array_column($watchClass::handoverFindings($watchExpired), 'key')], 'DomainWatch: an expired certificate and domain are errors and handover findings');
        $watchFailed = (new $watchClass(fn (): false => false, $watchHttp, fn (string $host): int => $watchNow + 90 * 86400))->collect($watchSite, $watchNow);
        $this->assertSame(['dns', 'warning', []], [$watchFailed['mail']['error'], array_column($watchClass::rows($watchFailed, false, $watchNow), 'status')[0], $watchClass::handoverFindings($watchFailed)], 'DomainWatch: a failed DNS lookup is reported, never judged');
        $watchLocal = (new $watchClass(fn (): array => throw new \RuntimeException('no network'), fn (): array => throw new \RuntimeException('no network'), fn (): int => throw new \RuntimeException('no network')))->collect(['site_host' => '127.0.0.1', 'https' => false, 'mail_domain' => 'example.cz', 'smtp_host' => ''], $watchNow);
        $this->assertSame([true, null, ['ok'], []], [$watchLocal['local'] ?? false, $watchLocal['mail'], array_column($watchClass::rows($watchLocal, false, $watchNow), 'status'), $watchClass::handoverFindings($watchLocal)], 'DomainWatch: a site on a local address makes no request and is one ok row');
        $this->assertSame([['ok'], ['ok'], []], [array_column($watchClass::rows(null, false, $watchNow), 'status'), array_column($watchClass::rows(null, true, $watchNow), 'status'), $watchClass::handoverFindings(null)], 'DomainWatch: no result yet and the public demo are one ok row each');
        $this->assertSame([false, 'result', false, false], [isset(\Talea\Core\Settings::DEFAULTS['domain_watch']), $watchClass::RESULT, in_array('domain_watch', \Talea\Core\SiteExport::SETTINGS, true), in_array('ext.domain_watch.result', \Talea\Core\SiteExport::SETTINGS, true)], 'DomainWatch: the cache lives in the add-on\'s own setting – no core setting, not exported');
    }
}
