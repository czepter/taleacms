<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsA;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** 2.11 document library – versions, the stable latest address, download counts, gated downloads (was: section 61 of tools/test.sh). YESTERDAY comes from the site clock. */
#[Group('site')]
final class DocumentLibraryTest extends SiteTestCase
{
    use CollectionsHelpers;

    private static string $docs = '';
    private static string $docsIdk = '';
    private static string $doc = '';
    /** @var resource|null */
    private static $smtp = null;

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$smtp)) {
            proc_terminate(self::$smtp);
            proc_close(self::$smtp);
        }
        parent::tearDownAfterClass();
    }

    public function testPresetVersionsAndTheStableAddress(): void
    {
        $this->mcpText('create_collection', ['name' => 'Documents', 'preset' => 'documents']);
        self::$docs = $this->sq("SELECT slug FROM tl_collections WHERE preset = 'documents' ORDER BY collection_id DESC LIMIT 1");
        self::$docsIdk = $this->sq('SELECT collection_id FROM tl_collections WHERE slug = ?', [self::$docs]);
        $docs = self::$docs;
        $base = $this->site()->base;

        $this->assertSame('1|file:file|issued', $this->sq("SELECT CONCAT(detail, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[0].key')), ':', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[0].type')), '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[4].key'))) FROM tl_collections WHERE collection_id = " . self::$docsIdk), 'the preset brings the file, category, version, summary and issued fields and item pages');
        $this->assertSame('1111', $this->sq("SELECT CONCAT(build LIKE '%{{latest}}%', build LIKE '%{{versions}}%', (SELECT CONCAT(build LIKE '%\"filter_field\":\"category\"%', build LIKE '%\"sort\":\"name\"%') FROM tl_pages WHERE slug = ?)) FROM tl_collections WHERE collection_id = " . self::$docsIdk, [$docs]), 'the item template downloads through {{latest}} and lists {{versions}}; the list page sorts by name and filters by category');

        $saved = $this->mcpData('save_collection_item', ['collection' => $docs, 'name' => 'Price list', 'slug' => 'price-list', 'values' => ['file' => '/media/pricelist-v1.pdf', 'version' => '1.0', 'category' => 'Price lists', 'summary' => 'Current price list.', 'issued' => '2026-01-10'], 'visible' => true]);
        self::$doc = $this->sq('SELECT item_id FROM tl_collection_items WHERE collection_id = ? AND slug = ?', [self::$docsIdk, 'price-list']);
        $this->assertSame("$base/$docs/price-list/latest", $saved['latest_url'] ?? null, 'save_collection_item returns the stable address of the file');
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM tl_document_versions WHERE item_id = ?', [self::$doc]), 'a new document has no previous version');

        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $this->site()->publicId('collection_items', (int) self::$doc), 'values' => ['file' => '/media/pricelist-v2.pdf', 'version' => '2.0']]);
        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $this->site()->publicId('collection_items', (int) self::$doc), 'values' => ['summary' => 'Current price list, new prices.']]);
        $this->assertSame('1|/media/pricelist-v1.pdf|1.0|1', $this->sq("SELECT CONCAT(COUNT(*), '|', MAX(file), '|', MAX(version), '|', MAX(replaced_by) LIKE 'Tester%') FROM tl_document_versions WHERE item_id = ?", [self::$doc]), 'a changed file keeps the previous file and its version for good, a save without a file change keeps nothing');

        $page = $this->visitor()->get("/$docs/price-list");
        $this->assertStringContainsString("href=\"/$docs/price-list/latest\"", $page->body, 'the item page downloads through the stable address');
        $this->assertStringContainsString('pricelist-v2.pdf)', $page->body);
        $this->assertStringContainsString('href="/media/pricelist-v1.pdf">pricelist-v1.pdf · Version 1.0</a>', $page->body, '... and lists the previous version with its number');
        $this->assertStringContainsString('Previous versions', $page->body);
    }

    public function testLatestRedirectAndDownloadCounts(): void
    {
        $docs = self::$docs;
        $base = $this->site()->base;
        $latest = $this->visitor()->get("/$docs/price-list/latest");
        $this->assertSame(302, $latest->status, '/…/latest answers 302');
        $this->assertSame("$base/media/pricelist-v2.pdf", $latest->redirect, '... to the current file');
        $this->assertStringStartsWith('no-store', strtolower($latest->headers['cache-control'] ?? ''), 'the redirect to the file is never cached');
        $this->visitor()->get("/$docs/price-list/latest", userAgent: 'curl/8.0'); // curl's own user agent counts as a bot
        $this->assertSame('1', $this->sq('SELECT COALESCE(SUM(d.count), 0) FROM tl_document_downloads d WHERE d.item_id = ?', [self::$doc]), 'two downloads from one address within an hour count once, a bot never');

        $list = $this->assertPage('/admin.php?module=collections&action=items&id=' . $this->site()->publicId('collections', (int) self::$docsIdk), 200, '<td class="number download">1 / 1</td>', message: 'the admin items list shows the downloads (30 days / total)');
        $this->assertStringContainsString("href=\"/$docs/price-list/latest\"", $list->body, 'the admin items list links the stable address');

        $items = $this->mcpData('list_collection_items', ['collection' => $docs]);
        $this->assertSame("1|1|$base/$docs/price-list/latest", ($items['items'][0]['downloads']['total'] ?? '') . '|' . ($items['items'][0]['downloads']['last_30_days'] ?? '') . '|' . ($items['items'][0]['latest_url'] ?? ''), 'list_collection_items carries the downloads and the stable address of a document');
    }

    public function testHiddenExpiredAndExpiringDocuments(): void
    {
        $docs = self::$docs;
        $id = (int) self::$doc;
        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $this->site()->publicId('collection_items', $id), 'visible' => false]);
        $this->assertSame(404, $this->visitor()->get("/$docs/price-list/latest")->status, 'a hidden document has no download address');

        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $this->site()->publicId('collection_items', $id), 'visible' => true, 'valid_until' => $this->siteDate('yesterday')]);
        $this->assertSame(404, $this->visitor()->get("/$docs/price-list/latest")->status, 'an expired document has no download address even before the hourly job hides it');

        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $this->site()->publicId('collection_items', $id), 'visible' => true, 'valid_until' => date('Y-m-d', strtotime('+10 days'))]);
        $audit = $this->mcpData('site_audit', ['kind' => 'document']);
        $this->assertSame('1|' . $this->site()->publicId('collection_items', (int) self::$doc), ($audit['total'] ?? '') . '|' . ($audit['findings'][0]['target']['item'] ?? ''), 'the site audit warns 30 days before a document expires, with the item to fix');
        $this->assertPage('/admin.php?module=audit', 200, 'The document is valid until', message: 'Administration → Site audit shows the expiring document');
    }

    /** A form that e-mails a file after sending: the enquiry records it, the e-mail carries a signed link (fake SMTP). */
    public function testGatedDownload(): void
    {
        $site = $this->site();
        $dir = $site->workDir('smtp2');
        $port = $site->freePort();
        self::$smtp = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/tools/fake-smtp.php', (string) $port, $dir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2) === false; $i++) {
            usleep(50_000);
        }
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'none', 'smtp_user' => '', 'mail_from' => 'web@example.com'] as $key => $value) {
            $site->setting($key, $value);
        }

        $this->mcpText('create_page', ['title' => 'Price list by e-mail', 'slug' => 'price-list-by-email', 'visible' => true]);
        $page = (int) $this->sq("SELECT page_id FROM tl_pages WHERE slug = 'price-list-by-email'");
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['id' => 'gate123', 'type' => 'form', 'content' => ['name' => 'Price list by e-mail', 'send_file' => '/media/pricelist-v2.pdf', 'fields' => [['label' => 'E-mail', 'type' => 'email', 'required' => true]]]],
        ]]]]]);
        $this->assertSame('1', $this->sq('SELECT build LIKE \'%"send_file":"/media/pricelist-v2.pdf"%\' FROM tl_pages WHERE page_id = ?', [$page]), 'the published form keeps the file to send');

        $site->clearPageCache();
        $visitor = $this->visitor();
        $form = $visitor->get('/price-list-by-email');
        sleep(4); // the antispam minimum time, as the old script waited
        $sent = $visitor->post('/form', ['source' => $form->field('source'), 'element' => $form->field('element'), 'back' => '/price-list-by-email', 'as_time' => $form->field('as_time'), 'as_signature' => $form->field('as_signature'), 'p0' => 'gate@example.cz']);
        $this->assertStringContainsString('result=ok', $sent->redirect, 'the form was sent');
        $this->assertSame('1', $this->sq("SELECT data LIKE '%File sent by e-mail%pricelist-v2.pdf%' FROM tl_enquiries WHERE email = 'gate@example.cz'"), 'the enquiry records which file was sent');

        $mail = '';
        for ($i = 0; $i < 100 && $mail === ''; $i++) {
            foreach (array_reverse(glob($dir . '/*.eml') ?: []) as $file) {
                if (str_contains((string) file_get_contents($file), "X-Rcpt-To: gate@example.cz")) {
                    $mail = $this->decodeMail($file);
                    break;
                }
            }
            $mail === '' && usleep(100_000);
        }
        $link = preg_match('#' . preg_quote($site->base, '#') . '/download/[A-Za-z0-9._-]*#', $mail, $m) === 1 ? $m[0] : '';
        $this->assertNotSame('', $link, 'the visitor got an e-mail with the download link');
        $this->assertStringContainsString('Subject-Decoded: Your file from', $mail, '... with the subject');
        $this->assertStringContainsString('pricelist-v2.pdf', $mail, '... and the file name');

        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'download'"); // an hour has passed for the counter
        $download = $this->visitor()->get($link);
        $this->assertSame(302, $download->status, 'the link redirects');
        $this->assertSame($site->base . '/media/pricelist-v2.pdf', $download->redirect, '... to the file');
        $this->assertSame('2', $this->sq('SELECT COALESCE(SUM(d.count), 0) FROM tl_document_downloads d WHERE d.item_id = ?', [self::$doc]), '... and counts the download of the document');

        $tampered = substr($link, 0, -1) . (str_ends_with($link, 'a') ? 'b' : 'a');
        $this->assertSame(404, $this->visitor()->get($tampered)->status, 'a tampered token is not found');
        $this->assertSame(404, $this->visitor()->get('/download/nonsense.token.here')->status, 'a nonsense token is not found');

        $site->setting('mail_mode', 'mail');
        $site->setting('smtp_host', '');
    }

    /** A plain-text e-mail: the subject decoded and the single base64 body decoded (the old gate_mail). */
    private function decodeMail(string $file): string
    {
        [$headers, $body] = explode("\r\n\r\n", (string) file_get_contents($file), 2);
        preg_match('/^Subject: (.*)$/m', $headers, $subject);

        return 'Subject-Decoded: ' . mb_decode_mimeheader(trim($subject[1] ?? '')) . "\n" . base64_decode($body);
    }
}
