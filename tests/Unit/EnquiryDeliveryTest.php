<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EnquiryDeliveryTest extends TestCase
{
    /** 2.13: enquiries to a sheet and the CRM (Core\EnquiryDelivery, EnquirySheet, EnquiryCrm) */
    public function testSheetAndCrm(): void
    {
        $f13Fields = \Talea\Core\EnquiryDelivery::fields([['Firma', 'Acme s.r.o.'], ['Jméno a příjmení', 'Jan Novák'], ['E-mail', 'jan@example.cz'], ['Telefon', '+420 777 123 456'], ['Zpráva', "Chci kuchyň.\nDo léta."], ['Souhlas', 'ano'], ['CV', 'cv.pdf (12 kB)', '2026/09/abc.pdf']], // check-english: allow
            ['text', 'text', 'email', 'tel', 'textarea', 'checkbox', 'file']);
        $f13Lead = \Talea\Core\EnquiryDelivery::lead($f13Fields, 'jan@example.cz');
        $this->assertSame([['name' => 'Jan Novák', 'email' => 'jan@example.cz', 'phone' => '+420 777 123 456', 'company' => 'Acme s.r.o.', 'text' => "Zpráva: Chci kuchyň.\nDo léta.\nCV: cv.pdf (12 kB)"], ['CV', 'cv.pdf (12 kB)', 'file']], [$f13Lead, $f13Fields[6]], '2.13 EnquiryDelivery::lead – the e-mail, the phone, the name by its label, the company, the rest as text without the consent and never the attachment path'); // check-english: allow
        $this->assertSame(['name' => 'Eva', 'email' => 'eva@example.cz', 'phone' => '', 'company' => '', 'text' => 'Město: Brno'], \Talea\Core\EnquiryDelivery::lead([['Kdo', 'Eva', 'text'], ['Město', 'Brno', 'text'], ['Mail', '', 'email']], 'eva@example.cz'), '2.13 EnquiryDelivery::lead – without a name label the first text field is the name; an empty e-mail field keeps the enquiry e-mail'); // check-english: allow
        $this->assertSame(// check-english: allow
            [['Jan Maria', 'Novák'], ['', 'Novák'], ['', ''], true, true, false, 'Poptávka – Kuchyně', 'Poptávka'], [ // check-english: allow
            \Talea\Core\EnquiryDelivery::splitName('Jan Maria Novák'), \Talea\Core\EnquiryDelivery::splitName('Novák'), \Talea\Core\EnquiryDelivery::splitName(''), // check-english: allow
            \Talea\Core\EnquiryDelivery::formWanted('', 'Poptávka'), \Talea\Core\EnquiryDelivery::formWanted('Kontakt, poptávka ', 'Poptávka'), \Talea\Core\EnquiryDelivery::formWanted('Kontakt', 'Poptávka'), // check-english: allow
            \Talea\Core\EnquiryDelivery::title(['form' => 'Poptávka', 'topic' => 'Kuchyně']), \Talea\Core\EnquiryDelivery::title(['form' => 'Poptávka', 'topic' => ''])], '2.13 EnquiryDelivery: names split at the last space, the forms list is matched by name, case and spaces aside'); // check-english: allow
        $f13Payload = ['service' => 'google', 'enquiry' => 5, 'date' => '2026-10-03 10:00', 'form' => 'Poptávka', 'topic' => 'Kuchyně', 'email' => 'jan@example.cz', 'page' => 'https://example.cz/kontakt', 'fields' => $f13Fields]; // check-english: allow
        $this->assertSame([['title' => 'Acme – enquiries'], ['title' => 'Form', 'gridProperties' => ['frozenRowCount' => 1]], ['Date', 'Form'],
                ['values' => [['2026-10-03 10:00', 'Poptávka', 'Kuchyně', 'jan@example.cz', 'https://example.cz/kontakt', 'Jan Novák', '+420 777 123 456', 'Zpráva: Chci kuchyň.', 'Do léta.', 'CV: cv.pdf (12 kB)']]]], [ // check-english: allow
            \Talea\Core\EnquirySheet::createBody('Acme – enquiries', \Talea\Core\EnquirySheet::COLUMNS)['properties'], \Talea\Core\EnquirySheet::createBody('A', ['Date', 'Form'])['sheets'][0]['properties'],
            array_column(array_column(\Talea\Core\EnquirySheet::createBody('A', ['Date', 'Form'])['sheets'][0]['data'][0]['rowData'][0]['values'], 'userEnteredValue'), 'stringValue'),
            \Talea\Core\EnquirySheet::appendBody($f13Payload)], '2.13 EnquirySheet: the create body carries the title and a bold frozen header, a row has the fixed columns then every other field in its own cell'); // check-english: allow
        $this->assertSame([['propertyName' => 'email', 'operator' => 'EQ', 'value' => 'jan@example.cz'], ['properties' => ['email' => 'jan@example.cz', 'firstname' => 'Jan', 'lastname' => 'Novák', 'phone' => '+420 777 123 456', 'company' => 'Acme s.r.o.']], ['properties' => ['lastname' => 'Eva']], // check-english: allow
                ['properties' => ['hs_timestamp' => '1700000000000', 'hs_note_body' => 'Text'], 'associations' => [['to' => ['id' => '777'], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 202]]]]]], [
            \Talea\Connectors\HubSpot::searchBody('jan@example.cz')['filterGroups'][0]['filters'][0], \Talea\Connectors\HubSpot::contactBody($f13Lead), \Talea\Connectors\HubSpot::contactBody(['name' => 'Eva', 'email' => '', 'phone' => '', 'company' => '']),
            \Talea\Connectors\HubSpot::noteBody('777', 'Text', 1700000000)], '2.13 HubSpot bodies: the search by e-mail, the contact with only the filled properties, the note associated to the contact');
        $this->assertSame(// check-english: allow
            ['https://acme-1.pipedrive.com/api/v1', null, null, [], ['name' => 'Jan Novák', 'email' => [['value' => 'jan@example.cz', 'primary' => true]], 'phone' => [['value' => '+420 777 123 456', 'primary' => true]]], ['name' => 'Eva'], // check-english: allow
                ['title' => 'Poptávka – Kuchyně', 'person_id' => 42], ['content' => 'Text', 'lead_id' => 'lead-1', 'person_id' => 42]], [ // check-english: allow
            \Talea\Connectors\Pipedrive::api('Acme-1'), \Talea\Connectors\Pipedrive::api('evil.example.com'), \Talea\Connectors\Pipedrive::api(''), \Talea\Connectors\Pipedrive::authHeaders('x', ''),
            \Talea\Connectors\Pipedrive::personBody($f13Lead), \Talea\Connectors\Pipedrive::personBody(['name' => 'Eva', 'email' => '', 'phone' => '']), \Talea\Connectors\Pipedrive::leadBody('Poptávka – Kuchyně', 42), \Talea\Connectors\Pipedrive::noteBody('Text', 'lead-1', 42)], '2.13 Pipedrive: the API of the company domain (nothing else is an address), the person with primary e-mail and phone, the lead and its note'); // check-english: allow
        $this->assertSame(// check-english: allow
            [['Authorization' => 'Basic ' . base64_encode('user@example.cz:rn-key')], ['topic' => 'Poptávka – Kuchyně', 'firstName' => 'Jan', 'lastName' => 'Novák', 'companyName' => 'Acme s.r.o.', 'contactInfo' => ['email' => 'jan@example.cz', 'tel1' => '+420 777 123 456'], 'notice' => 'Text'], // check-english: allow
                ['topic' => 'Poptávka', 'lastName' => 'Eva']], [ // check-english: allow
            \Talea\Connectors\Raynet::authHeaders('rn-key', 'user@example.cz'), \Talea\Connectors\Raynet::leadBody('Poptávka – Kuchyně', $f13Lead, 'Text'), \Talea\Connectors\Raynet::leadBody('Poptávka', ['name' => 'Eva', 'email' => '', 'phone' => '', 'company' => ''], '')], '2.13 Raynet: HTTP Basic from the user and the key, the lead with the contact and the notice, empty parts left out'); // check-english: allow
        $this->assertSame([['google', 'bing', 'hubspot', 'pipedrive', 'raynet'], [true, false, true, true, true], \Talea\Core\EnquirySheet::class, \Talea\Core\EnquiryCrm::class, null], [
            array_map(fn (string $c): string => $c::KEY, \Talea\Core\Connectors::SERVICES), array_map(fn (string $c): bool => isset($c::settings()['enquiries']) && $c::settings()['enquiries'][2] === 'check', \Talea\Core\Connectors::SERVICES),
            \Talea\Core\Connectors::handler('sheets.append'), \Talea\Core\Connectors::handler('crm.lead'), \Talea\Core\Connectors::handler('other.x')], '2.13 Connectors: the three CRMs are in the curated list with the enquiry switch in their settings; the queue prefixes sheets and crm have their handlers');
    }
}
