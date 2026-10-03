<?php

// Drupal JSON:API (3.0, Import\Drupal through Import\Fetch): /jsonapi/node/article and /jsonapi/node/page with the included
// resources, two items per page with links.next.href. Without an Authorization header only published nodes answer; Basic
// drupal:dr-pass or Bearer dr-token adds the unpublished one, any other credentials → 401. /jsonapi/taxonomy_term/tags is
// not offered (404) – the optional step is skipped. The data is tools/fixtures/drupal-fetch.json. The log never keeps
// the credentials, only whether they came.
if (preg_match('#^/jsonapi/(node/article|node/page|taxonomy_term/tags)$#', $path, $m) === 1) {
    $authorization = (string) ($headers['authorization'] ?? '');
    $signedIn = $authorization === 'Basic ' . base64_encode('drupal:dr-pass') || $authorization === 'Bearer dr-token';
    $log('drupal', ['path' => $path, 'offset' => (int) ($query['page']['offset'] ?? 0), 'has_auth' => $authorization !== '', 'signed_in' => $signedIn]);
    if ($authorization !== '' && !$signedIn) {
        return $reply(401, ['jsonapi' => ['version' => '1.0'], 'errors' => [['title' => 'Unauthorized', 'status' => '401']]]);
    }
    if ($m[1] === 'taxonomy_term/tags') {
        return $reply(404, ['jsonapi' => ['version' => '1.0'], 'errors' => [['title' => 'Not Found', 'status' => '404']]]);
    }
    $step = $m[1] === 'node/article' ? 'articles' : 'pages';
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/drupal-fetch.json'), true)['steps'][$step];
    $all = array_values(array_filter($fixture['data'], fn (array $node): bool => $signedIn || $node['attributes']['status'] === true));
    $offset = max(0, (int) ($query['page']['offset'] ?? 0));
    $base = 'http://' . $_SERVER['HTTP_HOST'] . $path . '?include=' . ($query['include'] ?? '');
    $links = ['self' => ['href' => $base . '&page[offset]=' . $offset . '&page[limit]=2']];
    if ($offset + 2 < count($all)) {
        $links['next'] = ['href' => $base . '&page[offset]=' . ($offset + 2) . '&page[limit]=2'];
    }

    return $reply(200, ['jsonapi' => ['version' => '1.0'], 'data' => array_slice($all, $offset, 2), 'included' => $fixture['included'] ?? [], 'links' => $links]);
}

return false;
