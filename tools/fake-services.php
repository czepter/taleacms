<?php

declare(strict_types=1);

/*
 * The fake of every outside service for tools/test.sh (2.13, Core\Connectors): started on PORT+15, the site's
 * KALETA_CONNECTORS_FAKE points every connector call here (the path and the query stay). Each service has its own file
 * in tools/fake/ that answers its paths; the first one that returns true has handled the request.
 *
 * A fake file gets $method, $path, $query (array), $body (raw string), $json (decoded body or null), $headers (lower-case
 * names) and $log (fn (string $name, array $entry) appends a JSON line to a log the test can read).
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY), $query);
$body = (string) file_get_contents('php://input');
$json = json_decode($body, true);
$headers = array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : []);
$log = function (string $name, array $entry): void {
    file_put_contents(sys_get_temp_dir() . '/kaleta-fake-' . $_SERVER['SERVER_PORT'] . '-' . $name . '.log', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
};
$reply = function (int $status, array $data): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return true;
};
foreach (glob(__DIR__ . '/fake/*.php') ?: [] as $file) {
    if ((require $file) === true) {
        return true;
    }
}
$reply(404, ['error' => 'the fake has no ' . $method . ' ' . $path]);

return true;
