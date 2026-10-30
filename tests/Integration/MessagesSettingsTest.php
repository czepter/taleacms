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
}
