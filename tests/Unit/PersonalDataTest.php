<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\PersonalData;

/** Which enquiries belong to an address (#332): the exact address as sender or as the whole value of a field. */
final class PersonalDataTest extends TestCase
{
    public function testOnlyTheExactAddressBelongs(): void
    {
        $email = 'jane@example.com';
        $enquiry = static fn (string $sender, array $data): array => ['email' => $sender, 'data' => $data];

        $this->assertTrue(PersonalData::belongsTo($enquiry(' Jane@Example.com ', []), $email), 'the sender, ignoring case and spaces');
        $this->assertTrue(PersonalData::belongsTo($enquiry('', [['Colleague', 'JANE@example.com']]), $email), 'a field with the address as its whole value');
        $this->assertTrue(PersonalData::belongsTo($enquiry('', [['Guests', ['bob@example.com', ' jane@example.com']]]), $email), 'a list inside a field');
        $this->assertFalse(PersonalData::belongsTo($enquiry('', [['Colleague', 'xjane@example.com']]), $email), 'a longer address that contains it');
        $this->assertFalse(PersonalData::belongsTo($enquiry('', [['Message', 'write to jane@example.com']]), $email), 'a note that mentions it');
        $this->assertFalse(PersonalData::belongsTo($enquiry('joe@example.com', [['Name', 'Joe']]), $email));
        $this->assertFalse(PersonalData::belongsTo(['email' => null, 'data' => [['Number', 42]]], $email), 'values that are not text');
    }
}
