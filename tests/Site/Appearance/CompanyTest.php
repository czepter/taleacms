<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Appearance;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Company details in the settings, in structured data and on the contact page (was: the company section of tools/test.sh). */
#[Group('site')]
final class CompanyTest extends SiteTestCase
{
    /** @return array<string, string> */
    private function company(string $hours): array
    {
        return [
            'tab' => 'company', 'company_name' => 'Test Company Ltd.', 'company_type' => 'HomeAndConstructionBusiness', 'company_id' => '12345678',
            'company_vat_id' => 'GB123456789', 'company_street' => '12 Long Street', 'company_city' => 'London', 'company_postcode' => 'SW1A 1AA', 'company_country' => 'GB',
            'company_phone' => '+44 20 7946 0958', 'company_hours' => $hours, 'company_map' => 'https://maps.example.com/abc', 'company_gps' => '50.0875, 14.4213',
        ];
    }

    public function testTheSettingsScreenOffersTheOpeningHours(): void
    {
        $this->assertPage('/admin.php?module=business', 200, 'name="company_hours"', message: 'settings/company');
    }

    public function testCompanyDetailsAreSaved(): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', $this->company("Mon–Fri 8:00–17:00\nSat 9–12"), '/admin.php?module=business');

        $this->assertSame('12345678', $this->site()->settingValue('company_id'), 'company details saved');
    }

    #[Depends('testCompanyDetailsAreSaved')]
    public function testAnIncomprehensibleOpeningHoursTextIsRejected(): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'company', 'company_type' => 'LocalBusiness', 'company_country' => 'GB', 'company_hours' => 'anytime'], '/admin.php?module=business');

        $this->assertSame('1', (string) $this->site()->value("SELECT value LIKE '%8:00%' AND value NOT LIKE '%anytime%' FROM tl_settings WHERE name = 'company_hours'"), 'an incomprehensible opening hours text is rejected');
    }

    #[Depends('testAnIncomprehensibleOpeningHoursTextIsRejected')]
    public function testTheCompanyIsInTheStructuredDataOfTheHomePage(): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', $this->company('Mon–Fri 8:00–17:00'), '/admin.php?module=business');
        $this->site()->clearPageCache();

        $home = $this->site()->client()->get('/');

        foreach (['"@type":"HomeAndConstructionBusiness"', '"openingHoursSpecification"', '"latitude":50.0875', '"vatID":"GB123456789"'] as $needle) {
            $this->assertStringContainsString($needle, $home->body, 'the company in structured data (LocalBusiness, opening hours, coordinates)');
        }
    }

    #[Depends('testTheCompanyIsInTheStructuredDataOfTheHomePage')]
    public function testTheContactPageListsTheCompanyDetails(): void
    {
        $contact = $this->site()->client()->get('/contact');

        foreach (['12 Long Street<br>SW1A 1AA London', 'href="tel:+442079460958"', '<li>Mon–Fri 8:00–17:00</li>', 'Company ID 12345678, VAT ID GB123456789'] as $needle) {
            $this->assertStringContainsString($needle, $contact->body, 'the contact page lists the company details from the settings');
        }
    }

    #[Depends('testTheContactPageListsTheCompanyDetails')]
    public function testAnInvalidTypeOverMcpNamesTheAllowedValues(): void
    {
        $result = $this->site()->mcpResult('update_settings', ['settings' => ['company_type' => 'Robot']]);

        $this->assertStringContainsString('Allowed: Organization, LocalBusiness,', (string) ($result['errors']['company_type'] ?? ''), 'an invalid choice lists the allowed values');
        $this->assertStringContainsString(', Person', (string) $result['errors']['company_type']);
    }

    #[Depends('testAnInvalidTypeOverMcpNamesTheAllowedValues')]
    public function testAPersonalSiteIsAPersonForSearchEngines(): void
    {
        $site = $this->site();
        $this->assertSame('Person', $site->mcpResult('update_settings', ['settings' => ['company_type' => 'Person', 'company_name' => 'Jane Doe']])['saved']['company_type'] ?? null, 'Person over MCP');
        $this->assertPage('/admin.php?module=business', 200, '<span class="company-person">', message: 'the admin names the field for a person');
        $site->clearPageCache();

        $home = $site->client()->get('/');

        foreach (['"@type":"Person","@id":"' . $site->base . '/#owner","name":"Jane Doe"', '"publisher":{"@id":"' . $site->base . '/#owner"}', '"telephone":"+44 20 7946 0958"'] as $needle) {
            $this->assertStringContainsString($needle, $home->body, 'the person in structured data, the publisher of the site');
        }
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $home->body, $m);
        $person = array_values(array_filter(json_decode($m[1] ?? '{}', true)['@graph'] ?? [], fn (array $n): bool => ($n['@type'] ?? '') === 'Person'))[0] ?? [];
        $this->assertSame('Jane Doe', $person['name'] ?? null, 'the Person node in the graph');
        $this->assertSame([], array_intersect(['legalName', 'logo', 'vatID', 'identifier', 'openingHoursSpecification', 'geo', 'hasMap'], array_keys($person)), 'no organisation properties on a person');
    }
}
