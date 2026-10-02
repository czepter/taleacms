<?php

// Google Sheets (2.13, Core\EnquirySheet): spreadsheets.create answers with an id, values.append with the number of rows;
// every call must carry a Bearer token. The bodies are logged so the test can see the title, the header and the rows.
// The flag file kaleta-fake-<port>-sheets.fail makes append answer 500 (the retry test).
if ($method === 'POST' && $path === '/v4/spreadsheets') {
    $log('sheets', ['call' => 'create', 'authorization' => $headers['authorization'] ?? '', 'body' => $json]);
    if (!str_starts_with((string) ($headers['authorization'] ?? ''), 'Bearer ')) {
        return $reply(401, ['error' => ['message' => 'Request had invalid authentication credentials.']]);
    }

    return $reply(200, ['spreadsheetId' => 'sheet-test-1', 'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/sheet-test-1/edit']);
}
if ($method === 'POST' && preg_match('#^/v4/spreadsheets/([^/]+)/values/([^:]+):append$#', $path, $m) === 1) {
    $log('sheets', ['call' => 'append', 'sheet' => $m[1], 'range' => $m[2], 'query' => $query, 'authorization' => $headers['authorization'] ?? '', 'body' => $json]);
    if (is_file(sys_get_temp_dir() . '/kaleta-fake-' . $_SERVER['SERVER_PORT'] . '-sheets.fail')) {
        return $reply(500, ['error' => ['message' => 'The fake sheet is broken.']]);
    }

    return $reply(200, ['spreadsheetId' => $m[1], 'updates' => ['updatedRows' => count($json['values'] ?? [])]]);
}

return false;
