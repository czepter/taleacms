<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Triage;

final class TriageTest extends TestCase
{
    /** 2.12: enquiry triage (Core\Triage) */
    public function testTriage(): void
    {
        $this->assertSame([['category' => 'sales', 'priority' => 3, 'suggested_reply' => "Dobrý den,\nděkujeme."], ['category' => null, 'priority' => null, 'suggested_reply' => null], 5000, 3], [ // check-english: allow
            Triage::clean('sales', 3, "Dobrý den,\r\n<b>děkujeme</b>."), Triage::clean('hack', 9, null), Triage::clean('spam', 'low', str_repeat('x', 6000))['suggested_reply'] !== null ? mb_strlen((string) Triage::clean('spam', 'low', str_repeat('x', 6000))['suggested_reply']) : 0, // check-english: allow
            Triage::clean(null, 'high', null)['priority']], '2.12 Triage::clean – a known kind and priority (number or word), a plain-text reply; anything else is left unchanged'); // check-english: allow
        $this->assertSame([['category' => 'job', 'priority' => 2, 'suggested_reply' => null], null], [Triage::rule(['source' => 'collection:7'], ['collection:7']), Triage::rule(['source' => 'page:3'], ['collection:7'])], '2.12 Triage::rule – an application from a job opening is a job; anything else is left to Claude or the assistant');
        $this->assertSame("Form: Kontakt\nPage: /kontakt\nAbout: Služby – Koupelny\nJméno: Eva\nŽivotopis: cv.pdf (20 kB)", Triage::text(['form' => 'Kontakt', 'page' => '/kontakt', 'topic' => 'Služby – Koupelny', // check-english: allow
            'data' => json_encode([['Jméno', 'Eva'], ['Životopis', 'cv.pdf (20 kB)', '2026/10/abc.pdf']])]), '2.12 Triage::text – the form, the page, what it was about and every field; never the attachment path'); // check-english: allow
        $this->assertSame([300, true, false], [\Talea\Core\Scheduler::JOBS['triage'][0], in_array('claude', Triage::MACHINES, true), in_array('Jana', Triage::MACHINES, true)], '2.12 Triage: the background job is known and a machine never overwrites a person');
    }
}
