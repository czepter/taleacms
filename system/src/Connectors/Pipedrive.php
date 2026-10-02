<?php

declare(strict_types=1);

namespace Kaleta\Connectors;

/**
 * Pipedrive (2.13): an enquiry becomes a lead "<form> – <topic>" of the person found by e-mail (or created), with a
 * note that carries the message. The administrator pastes the personal API token and the company domain (the <x> of
 * <x>.pipedrive.com); Pipedrive takes the token as the query parameter api_token, so authHeaders() adds nothing and
 * Core\EnquiryCrm puts it in the address (never in the log – Core\Connectors logs the action name only).
 */
final class Pipedrive extends Connector
{
    public const string KEY = 'pipedrive';
    public const string NAME = 'Pipedrive';
    public const string AUTH = 'token';
    public const string HELP_URL = 'https://support.pipedrive.com/en/article/how-can-i-find-my-personal-api-key';
    public const int PER_MINUTE = 80;

    public static function settings(): array
    {
        return ['domain' => ['Company domain', 'The <x> of <x>.pipedrive.com']] + \Kaleta\Core\EnquiryDelivery::SETTINGS;
    }

    public static function authHeaders(string $credential, string $account): array
    {
        return [];
    }

    /** The API of the company's account; null when the domain is not one. */
    public static function api(string $domain): ?string
    {
        return preg_match('/^[a-z0-9-]{1,63}$/i', $domain) === 1 ? 'https://' . strtolower($domain) . '.pipedrive.com/api/v1' : null;
    }

    /**
     * @param array{name: string, email: string, phone: string} $lead
     * @return array<string, mixed>
     */
    public static function personBody(array $lead): array
    {
        return ['name' => $lead['name']] + ($lead['email'] !== '' ? ['email' => [['value' => $lead['email'], 'primary' => true]]] : [])
            + ($lead['phone'] !== '' ? ['phone' => [['value' => $lead['phone'], 'primary' => true]]] : []);
    }

    /** @return array<string, mixed> */
    public static function leadBody(string $title, int $personId): array
    {
        return ['title' => $title, 'person_id' => $personId];
    }

    /** @return array<string, mixed> */
    public static function noteBody(string $content, string $leadId, int $personId): array
    {
        return ['content' => $content, 'lead_id' => $leadId, 'person_id' => $personId];
    }
}
