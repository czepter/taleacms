<?php

declare(strict_types=1);

namespace Kaleta\Connectors;

/**
 * HubSpot (2.13): enquiries become a contact (found by e-mail or created) with a note that carries the message. The
 * administrator pastes the access token of a private app with the crm.objects.contacts.write scope; it goes out as a
 * Bearer header. The request bodies are built here (pure, unit-tested); Core\EnquiryCrm makes the calls.
 */
final class HubSpot extends Connector
{
    public const string KEY = 'hubspot';
    public const string NAME = 'HubSpot';
    public const string AUTH = 'token';
    public const string HELP_URL = 'https://developers.hubspot.com/docs/api/private-apps';
    public const int PER_MINUTE = 100;
    public const string API = 'https://api.hubapi.com/crm/v3/objects';
    /** The HubSpot-defined association "note to contact". */
    public const int NOTE_TO_CONTACT = 202;

    public static function settings(): array
    {
        return \Kaleta\Core\EnquiryDelivery::SETTINGS;
    }

    /** The search for the contact with this e-mail. @return array<string, mixed> */
    public static function searchBody(string $email): array
    {
        return ['filterGroups' => [['filters' => [['propertyName' => 'email', 'operator' => 'EQ', 'value' => $email]]]], 'properties' => ['email'], 'limit' => 1];
    }

    /**
     * The contact's properties from the mapped lead; only what the form gave (an update never blanks what the CRM has).
     *
     * @param array{name: string, email: string, phone: string, company: string} $lead
     * @return array{properties: array<string, string>}
     */
    public static function contactBody(array $lead): array
    {
        [$first, $last] = \Kaleta\Core\EnquiryDelivery::splitName($lead['name']);

        return ['properties' => array_filter(['email' => $lead['email'], 'firstname' => $first, 'lastname' => $last, 'phone' => $lead['phone'], 'company' => $lead['company']], fn (string $v): bool => $v !== '')];
    }

    /** A note with the enquiry, associated to the contact. @return array<string, mixed> */
    public static function noteBody(string $contactId, string $text, int $timestamp): array
    {
        return ['properties' => ['hs_timestamp' => (string) ($timestamp * 1000), 'hs_note_body' => $text],
            'associations' => [['to' => ['id' => $contactId], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::NOTE_TO_CONTACT]]]]];
    }
}
