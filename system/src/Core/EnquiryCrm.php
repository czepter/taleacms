<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Connectors\HubSpot;
use Kaleta\Connectors\Pipedrive;
use Kaleta\Connectors\Raynet;

/**
 * An enquiry as a lead in the CRM (2.13, the queue action crm.lead of EnquiryDelivery): the contact is found by e-mail
 * or created, then the enquiry is attached as a lead or a note. Each CRM has its own small flow here; the request bodies
 * come from its connector class (pure). The returned string is '' when delivered, otherwise the error – the queue
 * retries and the Connections screen shows it.
 */
final class EnquiryCrm
{
    /** @param array<string, mixed> $payload */
    public static function deliver(App $app, string $action, array $payload): string
    {
        $service = (string) ($payload['service'] ?? '');
        $lead = EnquiryDelivery::lead(is_array($payload['fields'] ?? null) ? $payload['fields'] : [], (string) ($payload['email'] ?? ''));
        if ($lead['name'] === '') {
            $lead['name'] = $lead['email'] !== '' ? $lead['email'] : EnquiryDelivery::title($payload); // every CRM wants a name
        }
        $note = EnquiryDelivery::note($payload, $lead['text']);
        $error = match ($service) {
            HubSpot::KEY => self::hubspot($app, $lead, $note),
            Pipedrive::KEY => self::pipedrive($app, $payload, $lead, $note),
            Raynet::KEY => self::raynet($app, $payload, $lead, $note),
            default => 'No CRM ' . $service,
        };
        if (Connectors::service($service) !== null) {
            EnquiryDelivery::report($app->db(), $service, $error);
        }

        return $error;
    }

    /** @param array{name: string, email: string, phone: string, company: string, text: string} $lead */
    private static function hubspot(App $app, array $lead, string $note): string
    {
        $id = '';
        if ($lead['email'] !== '') {
            $answer = Connectors::request($app, HubSpot::KEY, 'POST', HubSpot::API . '/contacts/search', HubSpot::searchBody($lead['email']), [], 'crm.contact.search');
            if ($answer['error'] !== '') {
                return $answer['error'];
            }
            $id = (string) ($answer['json']['results'][0]['id'] ?? '');
        }
        if ($id !== '') {
            $answer = Connectors::request($app, HubSpot::KEY, 'PATCH', HubSpot::API . '/contacts/' . rawurlencode($id), HubSpot::contactBody($lead), [], 'crm.contact.update');
        } else {
            $answer = Connectors::request($app, HubSpot::KEY, 'POST', HubSpot::API . '/contacts', HubSpot::contactBody($lead), [], 'crm.contact.create');
            // the contact exists under another e-mail property: HubSpot answers 409 with its id
            $id = $answer['status'] === 409 && preg_match('/ID: (\d+)/', $answer['raw'], $m) === 1 ? $m[1] : (string) ($answer['json']['id'] ?? '');
            if ($id !== '') {
                $answer['error'] = '';
            }
        }
        if ($answer['error'] !== '') {
            return $answer['error'];
        }
        if ($id === '') {
            return 'HubSpot did not return the contact.';
        }

        return Connectors::request($app, HubSpot::KEY, 'POST', HubSpot::API . '/notes', HubSpot::noteBody($id, $note, time()), [], 'crm.note')['error'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{name: string, email: string, phone: string, company: string, text: string} $lead
     */
    private static function pipedrive(App $app, array $payload, array $lead, string $note): string
    {
        $api = Pipedrive::api(Connectors::config($app->db(), Pipedrive::KEY)['domain'] ?? '');
        if ($api === null) {
            return t('Enter the company domain of Pipedrive in Connections.');
        }
        // the token travels as ?api_token= (Pipedrive's way); the log keeps the action name, never the address
        $url = fn (string $path, array $query = []): string => $api . $path . '?' . http_build_query($query + ['api_token' => Connectors::credential($app, Pipedrive::KEY) ?? '']);
        $personId = 0;
        if ($lead['email'] !== '') {
            $answer = Connectors::request($app, Pipedrive::KEY, 'GET', $url('/persons/search', ['term' => $lead['email'], 'fields' => 'email', 'exact_match' => 'true', 'limit' => 1]), null, [], 'crm.person.search');
            if ($answer['error'] !== '') {
                return $answer['error'];
            }
            $personId = (int) ($answer['json']['data']['items'][0]['item']['id'] ?? 0);
        }
        if ($personId === 0) {
            $answer = Connectors::request($app, Pipedrive::KEY, 'POST', $url('/persons'), Pipedrive::personBody($lead), [], 'crm.person.create');
            if ($answer['error'] !== '') {
                return $answer['error'];
            }
            $personId = (int) ($answer['json']['data']['id'] ?? 0);
        }
        $answer = Connectors::request($app, Pipedrive::KEY, 'POST', $url('/leads'), Pipedrive::leadBody(EnquiryDelivery::title($payload), $personId), [], 'crm.lead');
        if ($answer['error'] !== '') {
            return $answer['error'];
        }
        $leadId = (string) ($answer['json']['data']['id'] ?? '');

        return Connectors::request($app, Pipedrive::KEY, 'POST', $url('/notes'), Pipedrive::noteBody($note, $leadId, $personId), [], 'crm.note')['error'];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{name: string, email: string, phone: string, company: string, text: string} $lead
     */
    private static function raynet(App $app, array $payload, array $lead, string $note): string
    {
        $instance = Connectors::config($app->db(), Raynet::KEY)['instance'] ?? '';
        if (preg_match('/^[a-z0-9._-]{1,100}$/i', $instance) !== 1) {
            return t('Enter the Raynet instance name in Connections.');
        }

        return Connectors::request($app, Raynet::KEY, 'PUT', Raynet::API . '/lead/', Raynet::leadBody(EnquiryDelivery::title($payload), $lead, $note), ['X-Instance-Name' => $instance], 'crm.lead')['error'];
    }
}
