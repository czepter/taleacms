<?php

// The CRMs (2.13, Core\EnquiryCrm): HubSpot (Bearer token, paths /crm/v3/…), Pipedrive (?api_token=, /api/v1/…) and
// Raynet (Basic + X-Instance-Name, /api/v2/lead/). Every call is logged with its body and how it was authorised. The
// contact known@example.cz already exists in HubSpot and Pipedrive (the update branch). The flag file
// kaleta-fake-<port>-crm.fail makes every CRM answer 500 (the retry test).
$fail = is_file($fakeFile('crm.fail'));

if (str_starts_with($path, '/crm/v3/objects/')) {
    $log('crm', ['crm' => 'hubspot', 'method' => $method, 'path' => $path, 'authorization' => $headers['authorization'] ?? '', 'body' => $json]);
    if (($headers['authorization'] ?? '') !== 'Bearer hs-token') {
        return $reply(401, ['message' => 'Authentication credentials not found.']);
    }
    if ($fail) {
        return $reply(500, ['message' => 'The fake CRM is broken.']);
    }
    if ($method === 'POST' && $path === '/crm/v3/objects/contacts/search') {
        $email = $json['filterGroups'][0]['filters'][0]['value'] ?? '';

        return $reply(200, ['total' => $email === 'known@example.cz' ? 1 : 0, 'results' => $email === 'known@example.cz' ? [['id' => '501', 'properties' => ['email' => $email]]] : []]);
    }
    if ($method === 'POST' && $path === '/crm/v3/objects/contacts') {
        return $reply(201, ['id' => '777', 'properties' => $json['properties'] ?? []]);
    }
    if ($method === 'PATCH' && preg_match('#^/crm/v3/objects/contacts/(\d+)$#', $path, $m) === 1) {
        return $reply(200, ['id' => $m[1], 'properties' => $json['properties'] ?? []]);
    }
    if ($method === 'POST' && $path === '/crm/v3/objects/notes') {
        return $reply(201, ['id' => '9001']);
    }

    return $reply(404, ['message' => 'no such HubSpot path']);
}

if (str_starts_with($path, '/api/v1/')) {
    $log('crm', ['crm' => 'pipedrive', 'method' => $method, 'path' => $path, 'api_token' => $query['api_token'] ?? '', 'query' => array_diff_key($query, ['api_token' => 1]), 'body' => $json]);
    if (($query['api_token'] ?? '') !== 'pd-token') {
        return $reply(401, ['success' => false, 'error' => 'unauthorized access']);
    }
    if ($fail) {
        return $reply(500, ['success' => false, 'error' => 'The fake CRM is broken.']);
    }
    if ($method === 'GET' && $path === '/api/v1/persons/search') {
        $known = ($query['term'] ?? '') === 'known@example.cz';

        return $reply(200, ['success' => true, 'data' => ['items' => $known ? [['item' => ['id' => 31, 'name' => 'Known Person']]] : []]]);
    }
    if ($method === 'POST' && $path === '/api/v1/persons') {
        return $reply(201, ['success' => true, 'data' => ['id' => 42, 'name' => $json['name'] ?? '']]);
    }
    if ($method === 'POST' && $path === '/api/v1/leads') {
        return $reply(201, ['success' => true, 'data' => ['id' => 'lead-uuid-1', 'title' => $json['title'] ?? '']]);
    }
    if ($method === 'POST' && $path === '/api/v1/notes') {
        return $reply(201, ['success' => true, 'data' => ['id' => 7]]);
    }

    return $reply(404, ['success' => false, 'error' => 'no such Pipedrive path']);
}

if (str_starts_with($path, '/api/v2/')) {
    $basic = base64_decode(substr((string) ($headers['authorization'] ?? ''), 6), true);
    $log('crm', ['crm' => 'raynet', 'method' => $method, 'path' => $path, 'username' => is_string($basic) ? strstr($basic, ':', true) : '', 'key_ok' => is_string($basic) && str_ends_with($basic, ':rn-key'),
        'instance' => $headers['x-instance-name'] ?? '', 'body' => $json]);
    if (!is_string($basic) || !str_ends_with($basic, ':rn-key') || ($headers['x-instance-name'] ?? '') === '') {
        return $reply(401, ['success' => false, 'error' => 'Unauthorized']);
    }
    if ($fail) {
        return $reply(500, ['success' => false, 'error' => 'The fake CRM is broken.']);
    }
    if ($method === 'PUT' && $path === '/api/v2/lead/') {
        return $reply(201, ['success' => true, 'data' => ['id' => 1201]]);
    }

    return $reply(404, ['success' => false, 'error' => 'no such Raynet path']);
}

return false;
