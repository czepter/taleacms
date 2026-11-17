<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Talea\Front\FormMail;

/** The owner's own text of a form's e-mails (docs/specs/form-builder.md): placeholders, fallbacks and what an e-mail to the visitor may repeat. */
final class FormMailTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private const array DATA = [['Your name', 'Jane'], ['Email', 'jane@example.org'], ['Wedding date', ''], ['Package', 'Full day'], ['Guests', '80'],
        ['Tell us about your day', 'Visit https://spam.example now'], ['Phone', '+44 20 7946 0958']];

    private const array TYPES = ['text', 'email', 'date', 'radio', 'number', 'textarea', 'tel'];

    public function testAnswersAndFallbacks(): void
    {
        $text = 'Hello {Your name}, {package} for {Guests} guests on {Wedding date|your day}. {site name}';
        $this->assertSame('Hello Jane, Full day for 80 guests on your day. Studio', FormMail::fill($text, self::DATA, self::TYPES, true, ['site name' => 'Studio']),
            'labels match regardless of case; an empty answer takes the text after the bar');
        $this->assertSame('{Colour} stays', FormMail::fill('{Colour} stays', self::DATA, self::TYPES, true), 'a placeholder that names no field stays, so the owner sees the mistake');
        $this->assertSame('Thanks Jane', FormMail::subject(FormMail::fill("Thanks\n {Your name}", self::DATA, self::TYPES, true)), 'a subject is one line');
    }

    public function testAnEmailToTheVisitorNeverRepeatsWhatTheyWroteFreely(): void
    {
        $text = '[{Tell us about your day|–}] [{Email}] [{Phone}]';
        $this->assertSame('[–] [] []', FormMail::fill($text, self::DATA, self::TYPES, true), 'free text, the e-mail and the phone are left out of an e-mail to the visitor');
        $this->assertSame('[Visit https://spam.example now] [jane@example.org] [+44 20 7946 0958]', FormMail::fill($text, self::DATA, self::TYPES, false), 'the owner’s notification takes every answer');
        $spam = [['Your name', 'Win at evil.example'], ['Name 2', 'x@y.z'], ['Name 3', str_repeat('a', 61)], ['Name 4', '<b>Jo</b>']];
        $this->assertSame('[] [] [] []', FormMail::fill('[{Your name}] [{Name 2}] [{Name 3}] [{Name 4}]', $spam, ['text', 'text', 'text', 'text'], true),
            'a short text counts as a name only without an address, @, angle brackets and at most 60 characters');
        $this->assertSame([true, true, false, false], [FormMail::isName('Zoë O’Brien-Müller'), FormMail::isName('J. Smith'), FormMail::isName('www.example.com'), FormMail::isName('')]);
    }

    public function testTechnicalNamesFillPlaceholdersToo(): void
    {
        $data = [['Your name', 'Jane', 'name' => 'vorname'], ['Email', 'jane@example.org', 'name' => 'email'], ['Package', 'Full day', 'name' => 'package_choice'], ['Tell us', 'Visit https://spam.example', 'name' => 'message']];
        $types = ['text', 'email', 'radio', 'textarea'];
        $this->assertSame('Hi Jane / Jane, Full day', FormMail::fill('Hi {vorname} / {Your name}, {Package_Choice}', $data, $types, true), 'a technical name works as well as a label, in any case');
        $this->assertSame('[jane@example.org] [{missing|x}]', FormMail::fill('[{email}] [{missing|x}]', $data, $types, false));
        $this->assertSame('[] [fallback]', FormMail::fill('[{email}] [{message|fallback}]', $data, $types, true), 'the safety rule for a visitor applies to a name as it does to a label');
        $this->assertSame('Studio 42', FormMail::fill('{site name} {result}', $data, $types, true, ['site name' => 'Studio', 'result' => '42']), 'the extra placeholders are unchanged');
    }

    public function testTheTechnicalNameWinsOverALabelThatClashes(): void
    {
        // the first field is named "email", the second one is labelled "Email": {email} is the first field
        $data = [['Your address', 'a@example.org', 'name' => 'email'], ['Email', 'b@example.org', 'name' => 'kaaaa']];
        $this->assertSame('a@example.org', FormMail::fill('{email}', $data, ['email', 'email'], false));
        $this->assertSame('b@example.org', FormMail::fill('{kaaaa}', $data, ['email', 'email'], false));
        // a label that repeats keeps the first, as before
        $this->assertSame('1', FormMail::fill('{Same}', [['Same', '1', 'name' => 'a'], ['Same', '2', 'name' => 'b']], ['number', 'number'], false));
    }
}
