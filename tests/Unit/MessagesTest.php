<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Language;
use Talea\Core\Messages;

final class MessagesTest extends TestCase
{
    public function testFillReplacesKnownPlaceholdersAndFallback(): void
    {
        $this->assertSame('Hi there', Messages::fill('Hi {name|there}', ['name' => ''], 'course_registered'));
        $this->assertSame('Hi Ann', Messages::fill('Hi {name|there}', ['name' => 'Ann'], 'course_registered'));
        $this->assertSame('Site: Studio', Messages::fill('Site: {site name}', ['site name' => 'Studio'], 'course_registered'));
    }

    public function testFillLeavesUnknownTextAlone(): void
    {
        $this->assertSame('Pay {price} for Salsa {', Messages::fill('Pay {price} for {course} {', ['course' => 'Salsa'], 'course_registered'));
    }

    public function testMissingLinkIsAppended(): void
    {
        $values = ['cancel link' => 'https://x.test/c', 'calendar link' => 'https://x.test/i'];
        $text = Messages::fill('Booked.', $values, 'booking_confirmation');
        $this->assertSame("Booked.\n\nhttps://x.test/c\n\nhttps://x.test/i", $text);
        $this->assertSame('Go {cancel link} / {calendar link}', Messages::fill('Go {cancel link} / {calendar link}', [], 'booking_confirmation'), 'no value, nothing to append, placeholder stays');
        $this->assertSame("Go https://x.test/c\n\nhttps://x.test/i", Messages::fill('Go {cancel link}', $values, 'booking_confirmation'));
    }

    public function testCheckRefusesUnknownPlaceholder(): void
    {
        $this->assertStringContainsString('price', (string) Messages::check('course_registered', 'Cost {price}'));
        $this->assertNull(Messages::check('course_registered', 'Hello {name|there}, {course} {Site Name}'));
        $this->assertNull(Messages::check('booking_confirmation', 'Cancel here: {cancel link}'));
        $this->assertNotNull(Messages::check('course_registered', '{cancel link}'));
    }

    public function testKey(): void
    {
        $this->assertSame('message_course_full_body', Messages::key('course_full', 'body'));
        $this->assertSame('message_booking_pending_subject_de', Messages::key('booking_pending', 'subject', 'de'));
    }

    public function testCatalogueIsComplete(): void
    {
        $this->assertCount(24, Messages::CATALOGUE);
        foreach (Messages::CATALOGUE as $id => $m) {
            $this->assertNotSame('', Messages::template($id, 'body'), $id);
            $this->assertSame($m['kind'] === 'mail', Messages::template($id, 'subject') !== '', $id);
            foreach (['subject', 'body'] as $part) {
                $this->assertNull(Messages::check($id, Messages::template($id, $part)), $id . ' ' . $part . ' uses only its own placeholders');
            }
        }
    }

    /** What Booking::sendConfirmation builds today (copied before Booking switches to Messages). */
    public function testBookingTemplateMatchesTodaysText(): void
    {
        $details = "Service: Haircut\nWith: Anna\nWhen: 2 Nov 2026 10:00–10:30\nWhere: Studio, Main St 1";
        $values = ['appointment' => $details, 'cancel deadline' => '1 Nov 2026 10:00', 'cancel link' => 'https://x.test/_booking/cancel/tok', 'calendar link' => 'https://x.test/_booking/ics/tok', 'date' => '2 Nov 2026 10:00', 'site name' => 'Studio'];
        $expected = "Thank you, your appointment is booked.\n\n" . $details
            . "\n\nAdd it to your calendar: https://x.test/_booking/ics/tok"
            . "\n\nIf you cannot come, cancel the appointment by 1 Nov 2026 10:00 with this link: https://x.test/_booking/cancel/tok";
        Language::runWith('en', function () use ($values, $expected): void {
            $this->assertSame($expected, Messages::fill(Messages::template('booking_confirmation', 'body'), $values, 'booking_confirmation'));
            $this->assertSame('Your appointment on 2 Nov 2026 10:00 – Studio', Messages::fill(Messages::template('booking_confirmation', 'subject'), $values, 'booking_confirmation', false));
        });
    }

    public function testDeclinedWithoutMessageHasNoGap(): void
    {
        Language::runWith('en', function (): void {
            $text = Messages::fill(Messages::template('booking_declined', 'body'), ['appointment' => 'A', 'message' => ''], 'booking_declined');
            $this->assertSame("Unfortunately we cannot offer you the requested time. We are sorry.\n\nA", $text);
        });
    }
}
