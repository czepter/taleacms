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

    /** The kinds an administrator sets in Settings → Enquiries: renamed, described, ordered, added and removed; job and spam stay */
    public function testKinds(): void
    {
        $this->assertSame(['sales', 'support', 'job', 'supplier', 'spam', 'other'], array_keys(Triage::kindsFrom('')), 'no setting: the built-in kinds');
        $this->assertSame(['Sales', '', 'Wants to buy, order or get a quote.', false], array_values(Triage::kindsFrom('')['sales']), 'a built-in kind: its name, no own description, the built-in one as a hint');

        $stored = Triage::storeKinds(['sales', 'job', 'support', '', ''], ['Bookings', 'Renamed', 'Support', 'Wedding photos', ''], ['Wants a date', '', '', 'Asks about a wedding', ''], '2:-1');
        $kinds = Triage::kindsFrom($stored);
        $this->assertSame(['sales', 'support', 'job', 'wedding-photos', 'spam'], array_keys($kinds), 'moved up, a new kind gets a key from its name, the empty row is left out, a removed built-in kind is gone, spam is always there');
        $this->assertSame(['Bookings', 'Wants a date', 'Job application', 'Wedding photos'], [$kinds['sales']['name'], $kinds['sales']['description'], $kinds['job']['name'], $kinds['wedding-photos']['name']], 'a built-in kind takes a new name, a fixed kind keeps its own');
        $this->assertSame('', json_decode($stored, true)[1]['name'], 'an unchanged built-in name is not stored, so it stays translated');

        $this->assertSame(['sales', 'support', 'job', 'spam'], array_keys(Triage::kindsFrom(Triage::storeKinds(['sales', 'support', 'job', 'wedding-photos'], ['Sales', 'Support', 'Job', 'Wedding photos'], [], '', 3))), 'a deleted kind is gone');
        $this->assertSame(['job', 'spam'], array_keys(Triage::kindsFrom(Triage::storeKinds(['job', 'spam'], ['x', 'y'], [], '', 1))), 'a fixed kind cannot be deleted');
        $this->assertSame([['category' => 'wedding-photos', 'priority' => null, 'suggested_reply' => null], null], [Triage::clean('wedding-photos', null, null, array_keys($kinds)), Triage::clean('other', null, null, array_keys($kinds))['category']], 'clean accepts the site\'s kinds only');
    }
}
