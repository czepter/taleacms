<?php

// Joomla 4/5 Web Services API (3.0, Import\Joomla through Import\Fetch): /api/index.php/v1/content/articles,
// content/categories, users and tags, two items per page with links.next, the token jm-secret-token required in
// X-Joomla-Token (anything else → 401). The data is the fixture tools/fixtures/joomla-fetch.json (the fetched form), so the
// unit test and the smoke test read the same fictional bakery. The log keeps whether a token came, never the token.
// /images/… answers a generated PNG for the image download.
if (preg_match('#^/api/index\.php/v1/(content/articles|content/categories|users|tags)$#', $path, $m) === 1) {
    $log('joomla', ['path' => $path, 'offset' => (int) ($query['page']['offset'] ?? 0), 'has_token' => isset($headers['x-joomla-token'])]);
    if (($headers['x-joomla-token'] ?? '') !== 'jm-secret-token') {
        return $reply(401, ['errors' => [['title' => 'Forbidden', 'code' => 401]]]);
    }
    $step = ['content/articles' => 'articles', 'content/categories' => 'categories', 'users' => 'users', 'tags' => 'tags'][$m[1]];
    $all = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/joomla-fetch.json'), true)['steps'][$step]['data'];
    $offset = max(0, (int) ($query['page']['offset'] ?? 0));
    $base = 'http://' . $_SERVER['HTTP_HOST'] . $path;
    $links = ['self' => $base . '?page[offset]=' . $offset . '&page[limit]=2'];
    if ($offset + 2 < count($all)) {
        $links['next'] = $base . '?page[offset]=' . ($offset + 2) . '&page[limit]=2';
    }

    return $reply(200, ['links' => $links, 'data' => array_slice($all, $offset, 2), 'meta' => ['total-pages' => (int) ceil(count($all) / 2)]]);
}
if ($path === '/images/blog/oven.jpg' || $path === '/sites/default/files/2024-03/oven.jpg') {
    $image = imagecreatetruecolor(320, 200);
    imagefill($image, 0, 0, imagecolorallocate($image, 160, 90, 40));
    header('Content-Type: image/png');
    imagepng($image);

    return true;
}

return false;
