<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;

final class CompanyTest extends TestCase
{
    public function testOpeningHours(): void
    {
        $this->assertSame([
            ['days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'from' => '08:00', 'to' => '17:00'],
            ['days' => ['Tuesday'], 'from' => '08:00', 'to' => '12:00'], ['days' => ['Tuesday'], 'from' => '13:00', 'to' => '17:00'],
        ], \Talea\Front\Company::parseOpeningHours("Mo–Fr 8:00–17:00\nTu 8-12, 13-17\nSu closed"), 'Company::hours: day range, several periods, closed');
        $this->assertSame([null, null], [\Talea\Front\Company::parseOpeningHours('every day 8-17'), \Talea\Front\Company::parseOpeningHours('Mo 8-25')], 'Company::hours: an unintelligible line is rejected');
        $this->assertSame(implode('|', array_keys(\Talea\Front\Company::TYPES)), (new \ReflectionClassConstant(\Talea\Admin\Modules\Settings::class, 'COMPANY_TYPES'))->getValue(), 'Company: the types in Settings match Company::TYPES');
    }

    public function testAPersonIssuerIsNoOrganisation(): void
    {
        $person = ['@type' => 'Person', '@id' => 'https://example.com/#owner', 'name' => 'Jane Doe'];
        $item = ['name' => 'X', 'slug' => 'x', 'created_at' => '2026-10-02 10:00:00', 'valid_until' => null, 'data' => ['a' => 'Leeds']];
        $node = fn (string $type, string $property, array $issuer): ?array => \Talea\Builder\CollectionSchema::forItem(['slug' => 'c', 'detail' => 1,
            'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text']], 'schema_org' => json_encode(['type' => $type, 'fields' => [$property => 'a']])], $item, 'https://example.com/c/x', '', '', $person['@id'], $issuer);
        $links = fn (array $issuer): array => [$node('JobPosting', 'jobLocation', $issuer) !== null, isset($node('Person', 'jobTitle', $issuer)['worksFor']), isset($node('LocalBusiness', 'address', $issuer)['parentOrganization']),
            isset($node('Service', 'serviceType', $issuer)['provider'])];

        $this->assertSame([true, true, true, true], $links(['@type' => 'Organization'] + $person), 'CollectionSchema: an organisation hires, employs, has branches and provides');
        $this->assertSame([false, false, false, true], $links($person), 'CollectionSchema: a person has no job postings, worksFor or parentOrganization – but still provides a service');
    }
}
