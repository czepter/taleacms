<?php

declare(strict_types=1);

namespace Talea\Connectors;

/**
 * Raynet CRM (2.13): an enquiry becomes a lead with the sender's contact and the message. The administrator enters the
 * user e-mail, the API key (HTTP Basic) and the instance name (header X-Instance-Name, added by Core\EnquiryCrm).
 */
final class Raynet extends Connector
{
    public const string KEY = 'raynet';
    public const string NAME = 'Raynet';
    public const string AUTH = 'basic';
    public const string HELP_URL = 'https://app.raynet.cz/';
    public const int PER_MINUTE = 60;
    public const string API = 'https://app.raynet.cz/api/v2';

    public static function settings(): array
    {
        return ['instance' => ['Instance name', 'The instance of your Raynet account (X-Instance-Name)']] + \Talea\Core\EnquiryDelivery::SETTINGS;
    }

    public static function authHeaders(string $credential, string $account): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($account . ':' . $credential)];
    }

    /**
     * @param array{name: string, email: string, phone: string, company: string} $lead
     * @return array<string, mixed>
     */
    public static function leadBody(string $topic, array $lead, string $notice): array
    {
        [$first, $last] = \Talea\Core\EnquiryDelivery::splitName($lead['name']);

        return array_filter(['topic' => $topic, 'firstName' => $first, 'lastName' => $last, 'companyName' => $lead['company'],
            'contactInfo' => array_filter(['email' => $lead['email'], 'tel1' => $lead['phone']], fn (string $v): bool => $v !== ''), 'notice' => $notice], fn (mixed $v): bool => $v !== '' && $v !== []);
    }
}
