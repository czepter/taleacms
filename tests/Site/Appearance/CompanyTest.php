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
}
