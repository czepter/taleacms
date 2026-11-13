<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Talea\Mcp\Confirmation;

final class ConfirmationTest extends TestCase
{
    public function testWhenACallActs(): void
    {
        $this->assertTrue(Confirmation::needed('cancel_booking', ['confirm' => true]));
        $this->assertFalse(Confirmation::needed('cancel_booking', []));
        $this->assertFalse(Confirmation::needed('cancel_booking', ['confirm' => 'yes']), 'only a real true counts, as in the tool');
        $this->assertTrue(Confirmation::needed('update_registration', ['action' => 'confirm']));
        $this->assertFalse(Confirmation::needed('update_registration', ['action' => 'dance']));
        $this->assertTrue(Confirmation::needed('send_newsletter', []));
        $this->assertFalse(Confirmation::needed('send_newsletter', ['unschedule' => true]));
        $this->assertTrue(Confirmation::needed('create_form_link', ['send' => true]));
        $this->assertFalse(Confirmation::needed('create_form_link', []));
        $this->assertTrue(Confirmation::needed('delete_media', []));
        $this->assertFalse(Confirmation::needed('save_build', ['confirm' => true]));
    }

    public function testCanonicalIgnoresOrderReasonAndCode(): void
    {
        $a = Confirmation::canonical(['b' => 1, 'a' => ['y' => 1, 'x' => 2], 'reason' => 'r', 'confirmation' => 'c', 'site' => 's']);
        $this->assertSame($a, Confirmation::canonical(['a' => ['x' => 2, 'y' => 1], 'b' => 1]));
        $this->assertNotSame(Confirmation::canonical(['l' => [1, 2]]), Confirmation::canonical(['l' => [2, 1]]), 'the order of a list matters');
    }

    public function testACodeFitsOnlyItsCall(): void
    {
        $now = 1_800_000_000;
        $args = ['id' => 'abc', 'confirm' => true];
        $code = Confirmation::code('k', 'cancel_booking', 1, 'token:5', $args, $now + 600);
        $this->assertTrue(Confirmation::valid('k', $code, 'cancel_booking', 1, 'token:5', $args, $now));
        $this->assertTrue(Confirmation::valid('k', $code, 'cancel_booking', 1, 'token:5', ['confirm' => true, 'id' => 'abc', 'reason' => 'x'], $now), 'order and reason do not matter');
        $this->assertFalse(Confirmation::valid('k', $code, 'decline_booking', 1, 'token:5', $args, $now), 'another tool');
        $this->assertFalse(Confirmation::valid('k', $code, 'cancel_booking', 2, 'token:5', $args, $now), 'another user');
        $this->assertFalse(Confirmation::valid('k', $code, 'cancel_booking', 1, 'token:6', $args, $now), 'another connection');
        $this->assertFalse(Confirmation::valid('k', $code, 'cancel_booking', 1, 'token:5', ['id' => 'abd', 'confirm' => true], $now), 'changed arguments');
        $this->assertFalse(Confirmation::valid('other', $code, 'cancel_booking', 1, 'token:5', $args, $now), 'another site key');
        $this->assertFalse(Confirmation::valid('k', $code, 'cancel_booking', 1, 'token:5', $args, $now + 601), 'expired');
        $future = Confirmation::code('k', 'cancel_booking', 1, 'token:5', $args, $now + 5000);
        $this->assertFalse(Confirmation::valid('k', $future, 'cancel_booking', 1, 'token:5', $args, $now), 'a forged expiry far ahead');
        $this->assertFalse(Confirmation::valid('k', 'nonsense', 'cancel_booking', 1, 'token:5', $args, $now));
        $this->assertFalse(Confirmation::valid('k', substr($code, 0, -1) . ($code[-1] === 'a' ? 'b' : 'a'), 'cancel_booking', 1, 'token:5', $args, $now), 'tampered');
    }

    public function testTheAnswerNamesTheCodeAndWhatItDoes(): void
    {
        $answer = Confirmation::answer('delete_media', '1-aaaaaaaa-' . str_repeat('b', 32), true);
        $this->assertTrue($answer['confirmation_required']);
        $this->assertFalse($answer['done']);
        $this->assertArrayHasKey('confirmation_refused', $answer);
        $this->assertStringContainsString('erases something for good', $answer['next']);
        $this->assertArrayNotHasKey('confirmation_refused', Confirmation::answer('cancel_booking', 'c', false));
        $this->assertSame(7, Confirmation::withheld('cancel_booking', 7)['scheduled_run']);
    }
}
