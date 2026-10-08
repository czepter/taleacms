<?php

declare(strict_types=1);

/*
 * A fake old site with many pages (3.7) for tools/test.sh – the import from a website and the migration report must go
 * past the old limit of 300 addresses: php -S 127.0.0.1:<port> tools/fake-old-site.php
 *
 *  - /robots.txt: /private/ is disallowed for every robot, Crawl-delay 0 (the test does not wait), the sitemap index;
 *  - /sitemap_index.xml → /sitemap-1.xml (pages 1–200) and /sitemap-2.xml (pages 201–350 and /private/secret/);
 *  - /, /p/<n>/ and /private/secret/: small HTML pages; /img/<name>.png: a PNG image (for the CSV import of items);
 *  - every request is appended to the file named by KALETA_FAKE_LOG (one path per line), so the test can see that
 *    a disallowed page was never downloaded.
 */
const PAGES = 350;

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$log = (string) getenv('KALETA_FAKE_LOG');
if ($log !== '') {
    file_put_contents($log, $path . "\n", FILE_APPEND | LOCK_EX);
}
$origin = 'http://' . (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$urlset = fn (array $paths): string => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . implode('', array_map(fn (string $p): string => '<url><loc>' . $origin . $p . '</loc></url>', $paths)) . '</urlset>';
$page = fn (string $title): string => '<!doctype html><html><head><title>' . $title . ' | Old Shop</title></head><body><header><nav><a href="/">Home</a></nav></header>'
    . '<main><h1>' . $title . '</h1><p>' . str_repeat('A product of the old shop, made with care and sold for many years. ', 3) . '</p></main></body></html>';

if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /private/\nCrawl-delay: 0\n\nSitemap: {$origin}/sitemap_index.xml\n";
} elseif ($path === '/sitemap_index.xml') {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<sitemap><loc>' . $origin . '/sitemap-1.xml</loc></sitemap><sitemap><loc>' . $origin . '/sitemap-2.xml</loc></sitemap></sitemapindex>';
} elseif ($path === '/sitemap-1.xml' || $path === '/sitemap-2.xml') {
    header('Content-Type: application/xml');
    $range = $path === '/sitemap-1.xml' ? range(1, 200) : range(201, PAGES);
    echo $urlset([...array_map(fn (int $n): string => '/p/' . $n . '/', $range), ...($path === '/sitemap-2.xml' ? ['/private/secret/'] : [])]);
} elseif ($path === '/' || preg_match('#^/p/(\d+)/$#', $path, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= PAGES) {
    header('Content-Type: text/html; charset=utf-8');
    echo $page($path === '/' ? 'Old Shop' : 'Product ' . ($m[1] ?? ''));
} elseif ($path === '/private/secret/') {
    header('Content-Type: text/html; charset=utf-8');
    echo $page('Secret');
} elseif (preg_match('#^/img/[a-z0-9-]+\.png$#', $path) === 1) {
    header('Content-Type: image/png');
    $image = imagecreatetruecolor(320, 240);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 90));
    imagepng($image);
} else {
    http_response_code(404);
    echo 'Not found';
}

return true;
