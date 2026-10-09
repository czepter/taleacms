<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\SiteTestCase;
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
        $this->mcpText('create_collection', ['name' => 'Dokumenty', 'preset' => 'documents']);
        self::$docs = $this->sq("SELECT seo_link FROM ka_kolekce WHERE preset = 'documents' ORDER BY idk DESC LIMIT 1");
        self::$docsIdk = $this->sq('SELECT idk FROM ka_kolekce WHERE seo_link = ?', [self::$docs]);
        $docs = self::$docs;
        $base = $this->site()->base;

        $this->assertSame('1|file:soubor|issued', $this->sq("SELECT CONCAT(detail, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].klic')), ':', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[4].klic'))) FROM ka_kolekce WHERE idk = " . self::$docsIdk), 'the preset brings the file, category, version, summary and issued fields and item pages');
        $this->assertSame('1111', $this->sq("SELECT CONCAT(stavba LIKE '%{{latest}}%', stavba LIKE '%{{versions}}%', (SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', stavba LIKE '%\"razeni\":\"nazev\"%') FROM ka_stranky WHERE seo_link = ?)) FROM ka_kolekce WHERE idk = " . self::$docsIdk, [$docs]), 'the item template downloads through {{latest}} and lists {{versions}}; the list page sorts by name and filters by category');

        $saved = $this->mcpData('save_collection_item', ['collection' => $docs, 'name' => 'Ceník', 'slug' => 'cenik', 'values' => ['file' => '/media/cenik-v1.pdf', 'version' => '1.0', 'category' => 'Ceníky', 'summary' => 'Platný ceník.', 'issued' => '2026-01-10'], 'visible' => true]);
        self::$doc = $this->sq('SELECT idp FROM ka_kolekce_polozky WHERE idk = ? AND seo_link = ?', [self::$docsIdk, 'cenik']);
        $this->assertSame("$base/$docs/cenik/latest", $saved['latest_url'] ?? null, 'save_collection_item returns the stable address of the file');
        $this->assertSame('0', $this->sq('SELECT COUNT(*) FROM ka_document_versions WHERE idp = ?', [self::$doc]), 'a new document has no previous version');

        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => (int) self::$doc, 'values' => ['file' => '/media/cenik-v2.pdf', 'version' => '2.0']]);
        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => (int) self::$doc, 'values' => ['summary' => 'Platný ceník, nové ceny.']]);
        $this->assertSame('1|/media/cenik-v1.pdf|1.0|1', $this->sq("SELECT CONCAT(COUNT(*), '|', MAX(file), '|', MAX(version), '|', MAX(replaced_by) LIKE 'Tester%') FROM ka_document_versions WHERE idp = ?", [self::$doc]), 'a changed file keeps the previous file and its version for good, a save without a file change keeps nothing');

        $page = $this->visitor()->get("/$docs/cenik");
        $this->assertStringContainsString("href=\"/$docs/cenik/latest\"", $page->body, 'the item page downloads through the stable address');
        $this->assertStringContainsString('cenik-v2.pdf)', $page->body);
        $this->assertStringContainsString('href="/media/cenik-v1.pdf">cenik-v1.pdf · Verze 1.0</a>', $page->body, '... and lists the previous version with its number');
        $this->assertStringContainsString('Předchozí verze', $page->body);
    }

    public function testLatestRedirectAndDownloadCounts(): void
    {
        $docs = self::$docs;
        $base = $this->site()->base;
        $latest = $this->visitor()->get("/$docs/cenik/latest");
        $this->assertSame(302, $latest->status, '/…/latest answers 302');
        $this->assertSame("$base/media/cenik-v2.pdf", $latest->redirect, '... to the current file');
        $this->assertStringStartsWith('no-store', strtolower($latest->headers['cache-control'] ?? ''), 'the redirect to the file is never cached');
        $this->visitor()->get("/$docs/cenik/latest", userAgent: 'curl/8.0'); // curl's own user agent counts as a bot
        $this->assertSame('1', $this->sq('SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = ?', [self::$doc]), 'two downloads from one address within an hour count once, a bot never');

        $list = $this->assertPage('/admin.php?module=collections&action=items&id=' . self::$docsIdk, 200, '<td class="cislo stazeni">1 / 1</td>', message: 'the admin items list shows the downloads (30 days / total)');
        $this->assertStringContainsString("href=\"/$docs/cenik/latest\"", $list->body, 'the admin items list links the stable address');

        $items = $this->mcpData('list_collection_items', ['collection' => $docs]);
        $this->assertSame("1|1|$base/$docs/cenik/latest", ($items['items'][0]['downloads']['total'] ?? '') . '|' . ($items['items'][0]['downloads']['last_30_days'] ?? '') . '|' . ($items['items'][0]['latest_url'] ?? ''), 'list_collection_items carries the downloads and the stable address of a document');
    }

    public function testHiddenExpiredAndExpiringDocuments(): void
    {
        $docs = self::$docs;
        $id = (int) self::$doc;
        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $id, 'visible' => false]);
        $this->assertSame(404, $this->visitor()->get("/$docs/cenik/latest")->status, 'a hidden document has no download address');

        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $id, 'visible' => true, 'valid_until' => $this->siteDate('yesterday')]);
        $this->assertSame(404, $this->visitor()->get("/$docs/cenik/latest")->status, 'an expired document has no download address even before the hourly job hides it');

        $this->mcpText('save_collection_item', ['collection' => $docs, 'id' => $id, 'visible' => true, 'valid_until' => date('Y-m-d', strtotime('+10 days'))]);
        $audit = $this->mcpData('site_audit', ['kind' => 'document']);
        $this->assertSame('1|' . self::$doc, ($audit['total'] ?? '') . '|' . ($audit['findings'][0]['target']['item'] ?? ''), 'the site audit warns 30 days before a document expires, with the item to fix');
        $this->assertPage('/admin.php?module=audit', 200, 'Dokument platí do', message: 'Administration → Site audit shows the expiring document');
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
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => (string) $port, 'smtp_encryption' => 'zadne', 'smtp_user' => '', 'mail_from' => 'web@example.cz'] as $key => $value) {
            $site->setting($key, $value);
        }

        $this->mcpText('create_page', ['title' => 'Ceník e-mailem', 'slug' => 'cenik-emailem', 'visible' => true]);
        $page = (int) $this->sq("SELECT ids FROM ka_stranky WHERE seo_link = 'cenik-emailem'");
        $this->mcpText('stavba_uloz', ['id' => $page, 'publikovat' => true, 'stavba' => ['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [
            ['id' => 'gate123', 'typ' => 'formular', 'obsah' => ['nazev' => 'Ceník na e-mail', 'poslat_soubor' => '/media/cenik-v2.pdf', 'pole' => [['popisek' => 'E-mail', 'typ' => 'email', 'povinne' => true]]]],
        ]]]]]);
        $this->assertSame('1', $this->sq('SELECT stavba LIKE \'%"poslat_soubor":"/media/cenik-v2.pdf"%\' FROM ka_stranky WHERE ids = ?', [$page]), 'the published form keeps the file to send');

        $site->clearPageCache();
        $visitor = $this->visitor();
        $form = $visitor->get('/cenik-emailem');
        sleep(4); // the antispam minimum time, as the old script waited
        $sent = $visitor->post('/formular', ['zdroj' => $form->field('zdroj'), 'prvek' => $form->field('prvek'), 'zpet' => '/cenik-emailem', 'as_cas' => $form->field('as_cas'), 'as_podpis' => $form->field('as_podpis'), 'p0' => 'gate@example.cz']);
        $this->assertStringContainsString('result=ok', $sent->redirect, 'the form was sent');
        $this->assertSame('1', $this->sq("SELECT data LIKE '%Soubor poslan% e-mailem%cenik-v2.pdf%' FROM ka_poptavky WHERE email = 'gate@example.cz'"), 'the enquiry records which file was sent');

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
        $this->assertStringContainsString('Subject-Decoded: Váš soubor z webu', $mail, '... with the subject');
        $this->assertStringContainsString('cenik-v2.pdf', $mail, '... and the file name');

        $site->exec("DELETE FROM ka_kontrola_ip WHERE typ = 'stazeni'"); // an hour has passed for the counter
        $download = $this->visitor()->get($link);
        $this->assertSame(302, $download->status, 'the link redirects');
        $this->assertSame($site->base . '/media/cenik-v2.pdf', $download->redirect, '... to the file');
        $this->assertSame('2', $this->sq('SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = ?', [self::$doc]), '... and counts the download of the document');

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
