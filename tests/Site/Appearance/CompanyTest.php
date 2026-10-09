<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Appearance;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** Company details in the settings, in structured data and on the contact page (was: section "firma" of tools/test.sh). */
#[Group('site')]
final class CompanyTest extends SiteTestCase
{
    /** @return array<string, string> */
    private function company(string $hours): array
    {
        return [
            'tab' => 'company', 'company_name' => 'Testovací firma s.r.o.', 'company_type' => 'HomeAndConstructionBusiness', 'company_id' => '12345678',
            'company_vat_id' => 'CZ12345678', 'company_street' => 'Dlouhá 12', 'company_city' => 'Praha', 'company_postcode' => '110 00', 'company_country' => 'CZ',
            'company_phone' => '+420 123 456 789', 'company_hours' => $hours, 'company_map' => 'https://mapy.cz/s/abc', 'company_gps' => '50.0875, 14.4213',
        ];
    }

    public function testTheSettingsScreenOffersTheOpeningHours(): void
    {
        $this->assertPage('/admin.php?module=business', 200, 'name="company_hours"', message: 'nastavení/firma');
    }

    public function testCompanyDetailsAreSaved(): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', $this->company("Po–Pá 8:00–17:00\nSo 9–12"), '/admin.php?module=business');

        $this->assertSame('12345678', $this->site()->settingValue('company_id'), 'údaje firmy uloženy');
    }

    #[Depends('testCompanyDetailsAreSaved')]
    public function testAnIncomprehensibleOpeningHoursTextIsRejected(): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', ['tab' => 'company', 'company_type' => 'LocalBusiness', 'company_country' => 'CZ', 'company_hours' => 'kdykoli'], '/admin.php?module=business');

        $this->assertSame('1', (string) $this->site()->value("SELECT value LIKE '%8:00%' AND value NOT LIKE '%kdykoli%' FROM ka_settings WHERE name = 'company_hours'"), 'nesrozumitelná otevírací doba odmítnuta');
    }

    #[Depends('testAnIncomprehensibleOpeningHoursTextIsRejected')]
    public function testTheCompanyIsInTheStructuredDataOfTheHomePage(): void
    {
        $this->adminPost('/admin.php?module=settings&action=save', $this->company('Po–Pá 8:00–17:00'), '/admin.php?module=business');
        $this->site()->clearPageCache();

        $home = $this->site()->client()->get('/');

        foreach (['"@type":"HomeAndConstructionBusiness"', '"openingHoursSpecification"', '"latitude":50.0875', '"vatID":"CZ12345678"'] as $needle) {
            $this->assertStringContainsString($needle, $home->body, 'firma ve strukturovaných datech (LocalBusiness, otevírací doba, souřadnice)');
        }
    }

    #[Depends('testTheCompanyIsInTheStructuredDataOfTheHomePage')]
    public function testTheContactPageListsTheCompanyDetails(): void
    {
        $contact = $this->site()->client()->get('/kontakt');

        foreach (['Dlouhá 12<br>110 00 Praha', 'href="tel:+420123456789"', '<li>Po–Pá 8:00–17:00</li>', 'IČO 12345678, DIČ CZ12345678'] as $needle) {
            $this->assertStringContainsString($needle, $contact->body, 'kontakt vypisuje údaje firmy z Nastavení');
        }
    }
}
