<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use Talea\Core\Language;
use Talea\Core\Messages;
use Talea\Core\Settings;
use Talea\Tests\Support\DatabaseTestCase;

final class MessagesSettingsTest extends DatabaseTestCase
{
    public function testLanguageVersionFallsBackToTranslatedTemplate(): void
    {
        $s = new Settings($this->db());
        $s->set('message_course_confirmed_body', 'EN own');

        $german = Language::runWith('de', fn (): string => Messages::template('course_confirmed', 'body'));
        $this->assertSame($german, Messages::text($s, 'course_confirmed', 'body', 'de'));
        $this->assertNotSame('EN own', $german);

        $s->set('message_course_confirmed_body_de', 'DE own');
        $this->assertSame('DE own', Messages::text($s, 'course_confirmed', 'body', 'de'));
        $this->assertSame('EN own', Messages::text($s, 'course_confirmed', 'body', ''));
        $this->assertSame(Language::runWith('en', fn (): string => Messages::template('course_confirmed', 'subject')), Messages::text($s, 'course_confirmed', 'subject', ''));
    }

    public function testOldBookingTextsMoved(): void
    {
        require_once TALEA_SYSTEM . '/database/migrations/20261030110100_move_booking_texts_to_messages.php';
        $s = new Settings($this->db());
        $s->set('booking_pending_thanks', 'Got it!');
        $s->set('booking_pending_mail', 'Hi {name}, we got your message.');
        $s->set('booking_declined_mail', '  ');
        $s->set('message_booking_pending_thanks_body', 'Already new');

        \MoveBookingTextsToMessages::move($this->db()->pdo(), self::PREFIX);
        $s = new Settings($this->db());

        $this->assertSame('Already new', $s->get('message_booking_pending_thanks_body'), 'a new text that is set wins');
        $this->assertSame('Hi {name}, we got your message.', $s->get('message_booking_pending_body'));
        $this->assertSame('', $s->get('message_booking_declined_body'), 'a blank text moves nothing');
        $this->assertSame(0, (int) $this->db()->value("SELECT COUNT(*) FROM {settings} WHERE name IN ('booking_pending_thanks', 'booking_pending_mail', 'booking_declined_mail')"));
    }

    public function testKeysAreParsedAndChecked(): void
    {
        $this->assertSame(['id' => 'course_course_cancelled', 'part' => 'body', 'language' => 'zh-hant'], Messages::parseKey('message_course_course_cancelled_body_zh-hant'));
        $this->assertNull(Messages::parseKey('message_course_thanks_registered_subject'), 'a page has no subject');
        $this->assertNull(Messages::parseKey('message_nothing_body'));
        $this->assertSame('message:course_full', \Talea\Admin\Modules\Settings::fieldType('message_course_full_body_de'));
        $this->assertNull(\Talea\Admin\Modules\Settings::verifyValue('message_course_full_body', '{price}'));
        $this->assertSame('Full', \Talea\Admin\Modules\Settings::verifyValue('message_course_full_body', ' Full '));
        $this->assertNull(\Talea\Admin\Modules\Settings::verifyValue('message_course_full_body', str_repeat('x', 5001)));
    }
}
