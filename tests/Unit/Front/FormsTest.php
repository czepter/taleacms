<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Elements\Form as FormElement;

final class FormsTest extends TestCase
{
    public function testCampaign(): void
    {
        $this->assertSame([], \Talea\Builder\Check::builds(['v' => 1, 'children' => [['id' => 'a', 'type' => 'heading', 'tag' => 'h3', 'content' => ['text' => 'Kontakt']]]], false), 'Build check: site parts do not check the heading outline'); // check-english: allow
        $this->assertSame('utm_source=google&utm_medium=cpc&utm_campaign=jaro', \Talea\Front\Forms::campaign('https://example.com/akce?utm_source=google&utm_medium=cpc&utm_campaign=jaro&gclid=x&utm_term[]=a', 'https://example.com'), 'Enquiry: campaign from the utm_* address of the page with the form');
        $this->assertSame('', \Talea\Front\Forms::campaign('https://jiny.cz/?utm_source=x', 'https://example.com'), 'Enquiry: campaign only from the own site');
    }

    /** Several visible recipients and a hidden copy (Bcc) for the notification (upstream 3.15.2, #92) */
    public function testRecipients(): void
    {
        $this->assertSame([['admin@example.com', 'info@example.com'], ['hidden@example.com']], \Talea\Front\Forms::recipients('admin@example.com, info@example.com;Info@example.com', 'hidden@example.com admin@example.com', 'web@example.com'), 'commas, semicolons and spaces; duplicates ignoring case; a visible recipient gets no second hidden copy');
        $this->assertSame([['web@example.com'], []], \Talea\Front\Forms::recipients('someone, @x', '', 'web@example.com'), 'invalid addresses are skipped; none valid = the site e-mail');
        $this->assertSame([['a@example.com'], []], \Talea\Front\Forms::recipients('', 'a@example.com', ''), 'without a recipient and a site e-mail the hidden recipients become the visible ones');
        $this->assertSame([[], []], \Talea\Front\Forms::recipients('', '', ''), 'nobody to notify');
        $six = implode(',', array_map(fn (int $i): string => "p{$i}@example.com", range(1, 6)));
        $this->assertSame([5, 5], array_map('count', \Talea\Front\Forms::recipients($six, str_replace('@', '@b.', $six), '')), 'at most five visible and five hidden recipients');
    }

    /** 2.12: multi-step forms, conditions and the price estimate (Builder\Elements\Form) */
    public function testMultiStepConditionsAndEstimate(): void
    {
        $calcFields = [
            ['label' => 'Typ', 'type' => 'radio', 'choices' => "Okna | 1200\nDveře | 9 900\nPoradenství"], // check-english: allow
            ['label' => 'Počet', 'type' => 'number', 'unit_price' => '1 500'], // check-english: allow
            ['label' => 'Doplňky', 'type' => 'checkboxes', 'checkbox_options' => "Montáž | 2000\nOdvoz | 500"], // check-english: allow
            ['label' => 'Barva dveří', 'type' => 'select', 'options' => "Bílá\nDub | 3000", 'show_when_field' => 'Typ', 'show_when_value' => 'Dveře'], // check-english: allow
            ['label' => 'Odstín', 'type' => 'text', 'show_when_field' => 'Barva dveří', 'show_when_value' => '*'], // check-english: allow
            ['label' => 'Krok 2', 'type' => 'step'],
            ['label' => 'Odhad', 'type' => 'estimate', 'base_price' => '500', 'currency' => 'Kč'], // check-english: allow
        ];
        $this->assertSame([['Okna' => 1200.0, 'Dveře' => 9900.0, 'Poradenství' => 0.0], ['Montáž', 'Odvoz']], [FormElement::optionPrices($calcFields[0]), FormElement::options($calcFields[2])], '2.12 Form::optionPrices – "Label | price" lines, the visitor sees and sends only the label'); // check-english: allow
        $calcAnswers = [0 => 'Okna', 1 => '4', 2 => ['Montáž'], 3 => 'Dub', 4 => 'tmavý']; // check-english: allow
        $calcVisible = FormElement::visible($calcFields, $calcAnswers);
        $this->assertSame(// check-english: allow
            [false, false, true, true, false], [$calcVisible[3], $calcVisible[4], $calcVisible[0],
            FormElement::visible($calcFields, [0 => 'Dveře', 3 => 'Dub'])[4], FormElement::visible([['label' => 'A', 'type' => 'text', 'show_when_field' => 'Nikde', 'show_when_value' => 'x']], [])[0]], '2.12 Form::visible – a condition on another answer, a chain of conditions, a missing field hides it'); // check-english: allow
        $this->assertSame([500.0 + 1200 + 4 * 1500 + 2000, 9900.0 + 3000], [FormElement::estimate($calcFields, $calcAnswers, $calcVisible, 500.0),
            FormElement::estimate($calcFields, [0 => 'Dveře', 1 => '', 2 => [], 3 => 'Dub'], FormElement::visible($calcFields, [0 => 'Dveře', 3 => 'Dub']), 0.0)], '2.12 Form::estimate – the base, chosen and ticked options and number × unit price, only of shown fields'); // check-english: allow
        $this->assertSame(// check-english: allow
            [format_count(9700) . "\u{a0}Kč", format_count(12.5, 2), 1200.5], [FormElement::money(9700.0, 'Kč'), FormElement::money(12.5, ''), FormElement::price('1 200,50')], '2.12 Form::money – whole amounts without decimals, the currency after a no-break space'); // check-english: allow
    }

    /** 2.12: forms that know where they are, thank-you with next steps */
    public function testFormsKnowWhereTheyAre(): void
    {
        $this->assertSame(['koupelna', 'koupelna', null, null, null, null], [
            \Talea\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby/koupelna'), \Talea\Front\EnquiryTopic::itemSlug('sluzby', '/en/sluzby/koupelna/?form=x'), \Talea\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby'),
            \Talea\Front\EnquiryTopic::itemSlug('sluzby', '/jine/koupelna'), \Talea\Front\EnquiryTopic::itemSlug('', '/a/b'), \Talea\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby/Koupelna%20X')], '2.12 EnquiryTopic::itemSlug – the item from the address of its page, with a language prefix or a query; a list page or another collection is none');
        $this->assertSame(// check-english: allow
            ['Služby – Rekonstrukce koupelny', 'Kontakt', 'Okno', 255], [ // check-english: allow
            \Talea\Front\EnquiryTopic::compose('Služby', 'Rekonstrukce koupelny'), \Talea\Front\EnquiryTopic::compose(' Kontakt '), \Talea\Front\EnquiryTopic::compose('', 'Okno'), mb_strlen(\Talea\Front\EnquiryTopic::compose(str_repeat('a', 200), str_repeat('b', 200)))], '2.12 EnquiryTopic::compose – "collection – item", a page alone, trimmed and cut to the column'); // check-english: allow
        $nsWeek = \Talea\Front\NextSteps::DEFAULT_WEEK;
        $nsLunch = array_fill_keys(\Talea\Core\Hours::DAYS, []);
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $nsDay) { $nsLunch[$nsDay] = [['08:00', '12:00'], ['13:00', '17:00']]; }
        $nsHoliday = ['id' => 1, 'from' => '2026-10-12', 'to' => '2026-10-12', 'closed' => true, 'hours' => '', 'note' => 'Holiday', 'notice_days' => 0];
        $nsShort = ['id' => 2, 'from' => '2026-10-13', 'to' => '2026-10-13', 'closed' => false, 'hours' => '9-10', 'note' => '', 'notice_days' => 0];
        $nsDeadline = fn (array $week, array $ex, string $from, int $hours): string => \Talea\Front\NextSteps::deadline($week, $ex, new \DateTimeImmutable($from), $hours)->format('Y-m-d H:i');
        $this->assertSame(['2026-10-12 11:00', '2026-10-13 11:00'], [
            $nsDeadline($nsWeek, [], '2026-10-09 16:00', 4), $nsDeadline($nsWeek, [$nsHoliday], '2026-10-09 16:00', 4)], '2.12 NextSteps::deadline – Friday 16:00 + 4 working hours with Mo–Fr 8–17 is Monday 11:00; a holiday on Monday moves it to Tuesday');
        $this->assertSame(['2026-10-05 11:00', '2026-10-05 14:30', '2026-10-05 09:00', '2026-10-12 09:00', '2026-10-14 08:30', '2026-10-07 10:00'], [
            $nsDeadline($nsWeek, [], '2026-10-05 09:00', 2), $nsDeadline($nsLunch, [], '2026-10-05 11:30', 2), $nsDeadline($nsWeek, [], '2026-10-05 06:00', 1), $nsDeadline($nsWeek, [], '2026-10-10 10:00', 1),
            $nsDeadline($nsWeek, [$nsHoliday, $nsShort], '2026-10-09 16:30', 2), $nsDeadline($nsWeek, [], '2026-10-05 10:00', 18)], '2.12 NextSteps::deadline – within the day, over the lunch break, sent before opening or on a weekend, shorter hours of an exception, two working days');
        $this->assertSame('2026-10-09 20:00', $nsDeadline(array_fill_keys(\Talea\Core\Hours::DAYS, []), [], '2026-10-09 16:00', 4), '2.12 NextSteps::deadline – no open day at all: plain hours');
        $nsNow = new \DateTimeImmutable('2026-10-05 09:00');
        $this->assertSame([t('We will reply today by %s.', '16:00'), t('We will reply tomorrow by %s.', '8:30'), t('We will reply %s by %s.', t('on Thursday'), '11:00'), t('We will reply by %s.', format_date('2026-10-19 11:00', true))], [
            \Talea\Front\NextSteps::deadlineText(new \DateTimeImmutable('2026-10-05 16:00'), $nsNow), \Talea\Front\NextSteps::deadlineText(new \DateTimeImmutable('2026-10-06 08:30'), $nsNow),
            \Talea\Front\NextSteps::deadlineText(new \DateTimeImmutable('2026-10-08 11:00'), $nsNow), \Talea\Front\NextSteps::deadlineText(new \DateTimeImmutable('2026-10-19 11:00'), $nsNow)], '2.12 NextSteps::deadlineText – today, tomorrow, a weekday within the week, further away with the date');
        $this->assertSame([['Zavoláme vám', 'Přijedeme na zaměření'], [], 'next_steps', 'reply_within_hours', 'who_replies'], [\Talea\Front\NextSteps::steps(['next_steps' => "Zavoláme vám\r\n\n  Přijedeme na zaměření \n"]), \Talea\Front\NextSteps::steps([]), // check-english: allow
            'next_steps', 'reply_within_hours', 'who_replies'], '2.12 NextSteps::steps – one step per line, empty lines dropped; the content keys have English names'); // check-english: allow
    }
}
