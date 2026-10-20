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
}
