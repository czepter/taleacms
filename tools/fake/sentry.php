<?php

// Error tracking (Core\ErrorReport): the envelope endpoint of Sentry and GlitchTip, POST /sentry/api/<project>/envelope/ (the tests set
// the DSN http://test-key@127.0.0.1:<port>/sentry/<project>). The log keeps the key from X-Sentry-Auth and the event of each envelope.
// The flag file "sentry-down" makes it answer 503, as a tracker that is away.
if (preg_match('#^/sentry/api/(\d+)/envelope/$#', $path, $m) !== 1 || $method !== 'POST') {
    return false;
}
if (is_file($fakeFile('sentry-down'))) {
    return $reply(503, ['detail' => 'down']);
}
preg_match('/sentry_key=([^,\s]+)/', $headers['x-sentry-auth'] ?? '', $key);
$lines = explode("\n", $body);
$event = json_decode($lines[2] ?? '', true);
$log('sentry', ['project' => $m[1], 'key' => $key[1] ?? '', 'type' => json_decode($lines[1] ?? '', true)['type'] ?? '', 'event' => $event, 'raw' => $body]);

return $reply(200, ['id' => (string) ($event['event_id'] ?? '')]);
