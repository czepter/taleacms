<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ValidityTest extends TestCase
{
    /** 2.10: true until and review by (Core\Validity) */
    public function testValidity(): void
    {
        $validityRows = [
            ['id' => 1, 'title' => 'Spring offer', 'visible' => 1, 'valid_until' => '2026-09-30', 'review_by' => null],   // expired yesterday, still visible
            ['id' => 2, 'title' => 'Today', 'visible' => 1, 'valid_until' => '2026-10-01', 'review_by' => '2026-10-01'],  // true until today: still true, review due today
            ['id' => 3, 'title' => 'Hidden', 'visible' => 0, 'valid_until' => '2026-01-01', 'review_by' => '2026-01-01'], // already hidden: not hidden again, review still due
            ['id' => 4, 'title' => 'Future', 'visible' => 1, 'valid_until' => '2026-12-31', 'review_by' => '2026-10-02'],
            ['id' => 5, 'title' => 'Asked', 'visible' => 1, 'valid_until' => null, 'review_by' => '2026-09-20'],           // review already recorded for this date
            ['id' => 6, 'title' => 'Re-asked', 'visible' => 1, 'valid_until' => null, 'review_by' => '2026-09-25'],        // recorded for an older date – a changed date asks again
        ];
        $validityAsked = ['5|2026-09-20' => true, '6|2026-09-01' => true];
        $this->assertSame([1], array_column(\Talea\Core\Validity::expired($validityRows, '2026-10-01'), 'id'), '2.10 Validity::expired – visible rows whose day has passed, today still counts as true');
        $this->assertSame([1, 2], array_column(\Talea\Core\Validity::expired($validityRows, '2026-10-02'), 'id'), '2.10 Validity::expired – the day after, today\'s row expires too');
        $this->assertSame([2, 3, 6], array_column(\Talea\Core\Validity::dueForReview($validityRows, '2026-10-01', $validityAsked), 'id'), '2.10 Validity::dueForReview – today or earlier, once per content and date');
        $this->assertSame([2, 3, 4, 5, 6], array_column(\Talea\Core\Validity::dueForReview($validityRows, '2026-10-02', []), 'id'), '2.10 Validity::dueForReview – nothing asked yet');
        $this->assertSame(['2026-10-01', '2026-10-01', null, null, null, null], [\Talea\Core\Validity::date('2026-10-01'), \Talea\Core\Validity::date(' 2026-10-01T12:00 '),
            \Talea\Core\Validity::date('2026-02-30'), \Talea\Core\Validity::date(''), \Talea\Core\Validity::date(null), \Talea\Core\Validity::date('tomorrow')], '2.10 Validity::date – a form or Claude date, a datetime cut to its day, nonsense and empty = none');
        $this->assertSame([true, false, false, false], [\Talea\Core\Validity::isDate('2026-10-01'), \Talea\Core\Validity::isDate('2026-13-01'), \Talea\Core\Validity::isDate('1.10.2026'), \Talea\Core\Validity::isDate(20261001)], '2.10 Validity::isDate');
        $this->assertSame([3600, 'any', true, 'Review by', true], [\Talea\Core\Scheduler::JOBS['validity'][0], \Talea\Core\Scheduler::JOBS['validity'][1], isset(\Talea\Core\Scheduler::jobs()['validity']),
            \Talea\Core\Audit::KINDS['review'], isset(\Talea\Core\Events::TYPES['content.expired'], \Talea\Core\Events::TYPES['content.review'])], '2.10: the validity job, the audit kind and the events are known');
    }
}
