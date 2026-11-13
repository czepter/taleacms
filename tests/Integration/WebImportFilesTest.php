<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\ImageDownloader;
use Talea\Core\Settings;
use Talea\Core\WebImport;
use Talea\Core\WebImportFiles;
use Talea\Core\WebImportPaused;
use Talea\Tests\Support\DatabaseTestCase;
use Talea\Tests\Support\LocalOldSite;

/** What the website import brings over besides the text: the documents and images of a page, within one budget and one step's time. */
#[CoversClass(WebImportFiles::class)]
#[CoversClass(WebImport::class)]
final class WebImportFilesTest extends DatabaseTestCase
{
    private LocalOldSite $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->old = new LocalOldSite();
        $this->old->start();
    }

    protected function tearDown(): void
    {
        foreach ($this->db()->all('SELECT image_path FROM {media}') as $row) {
            \Talea\Core\Files::delete((string) $row['image_path']); // what the test brought into the media folder
        }
        $this->old->stop();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function state(bool $images = true): array
    {
        return WebImport::newState($this->old->origin, ['images' => $images, 'redirects' => false, 'news' => false]);
    }

    private function files(?\Closure $mayDownload = null): WebImportFiles
    {
        return new WebImportFiles($this->db(), new ImageDownloader($this->old->origin), $this->author(), '', $mayDownload);
    }

    private function author(): int
    {
        $row = $this->db()->value("SELECT user_id FROM {users} WHERE username = 'importer'");

        return $row !== null ? (int) $row : $this->db()->insert('users', ['username' => 'importer', 'password' => 'x', 'name' => 'Importer', 'email' => 'importer@example.test', 'admin' => 2, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);
    }

    private function mapped(string $type): int
    {
        return (int) $this->db()->value('SELECT COUNT(*) FROM {import_map} WHERE type = ?', [$type]);
    }

    private function links(int $from, int $to): string
    {
        $html = '';
        for ($i = $from; $i <= $to; $i++) {
            $html .= '<a href="' . $this->old->origin . '/docs/file-' . $i . '.pdf">file ' . $i . '</a> ';
        }

        return $html;
    }

    public function testAPageHasOneDocumentBudgetForItsContentAndItsWidgets(): void
    {
        $state = $this->state();
        $files = $this->files();
        $files->bring($this->links(1, 6), $state);
        $files->bring($this->links(7, 12), $state); // a widget of the same page
        $this->assertSame(WebImportFiles::PER_PAGE, $this->mapped('file'), 'ten documents for the whole page, not ten per piece of HTML');
    }

    public function testAPageHasOneImageBudgetForItsContentAndItsWidgets(): void
    {
        $state = $this->state();
        $images = static fn (int $from, int $to): string => implode('', array_map(static fn (int $i): string => '<img src="/img/' . $i . '.jpg" alt="">', range($from, $to)));
        $page = '<html><head><title>FAQ | Acme</title></head><body><main><h1>FAQ</h1><p>' . str_repeat('Questions we hear again and again. ', 4) . '</p>' . $images(1, 30)
            . '<div class="faq-item"><div class="faq-question">First?</div><div class="faq-answer">' . $images(31, 50) . '</div></div>'
            . '<div class="faq-item"><div class="faq-question">Second?</div><div class="faq-answer">' . $images(51, 70) . '</div></div></main></body></html>';
        $import = new WebImport($this->db(), new Settings($this->db()), 0, new ImageDownloader($this->old->origin, true));
        $import->importHtml($this->old->origin . '/faq/', $page, $state);
        $this->assertSame(40, $this->mapped('image'), 'forty images for the whole page: the content and the answers share them');
    }

    public function testAPageWithoutTimeLeftPausesAndTheNextStepFinishesIt(): void
    {
        $state = $this->state();
        $page = '<html><head><title>About | Acme</title></head><body><main><h1>About</h1><p>' . str_repeat('We have been here for thirty years. ', 4) . '</p><img src="/img/team.jpg" alt=""></main></body></html>';
        $this->db()->insert('settings', ['name' => 'extensions', 'value' => 'redirects']);
        $import = new WebImport($this->db(), new Settings($this->db()), 0, new ImageDownloader($this->old->origin, true));
        $end = new \ReflectionProperty($import, 'end');
        $end->setValue($import, microtime(true) - 100); // this step's time is used up
        try {
            $import->importHtml($this->old->origin . '/about/', $page, $state);
            $this->fail('the page should pause');
        } catch (WebImportPaused) {
            $this->assertSame([0, 0, []], [$state['result']['pages'], $this->mapped('image'), $state['attention']], 'nothing was downloaded, nothing was created, nothing noted');
        }
        $end->setValue($import, microtime(true) + 60); // the next step
        $import->importHtml($this->old->origin . '/about/', $page, $state);
        $this->assertSame(1, $state['result']['pages'], 'the page is finished in the next step');
    }

    public function testADocumentOfAFilesPausedPageIsNotDownloadedTwice(): void
    {
        $state = $this->state();
        $this->old->put('docs/file-1.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF");
        $calls = 0;
        $files = $this->files(function () use (&$calls): bool {
            return ++$calls === 1; // time for the first download only
        });
        $html = $this->links(1, 2);
        try {
            $files->bring($html, $state);
            $this->fail('the page should pause at the second document');
        } catch (WebImportPaused) {
        }
        $this->assertSame([1, 1], [$state['result']['files'], $this->mapped('file')], 'the first document is in Media and remembered');
        $again = $this->files()->bring($html, $state);
        $this->assertSame(1, $state['result']['files'], 'a document brought over before is reused');
        $this->assertSame(2, substr_count($again, '<a href='));
    }

    public function testADocumentWithASpaceInItsNameIsBroughtOverAndItsLinkPointsAtMedia(): void
    {
        $state = $this->state();
        $this->old->put('docs/Price List 2024.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF");
        $page = WebImport::extract('<html><body><main><h1>Prices</h1><p>' . str_repeat('Our prices are fair and we are happy to explain them. ', 3)
            . '</p><p><a href="/docs/Price%20List%202024.pdf">Price list</a> <a href="/contact/">Contact</a></p></main></body></html>', $this->old->origin . '/prices/');
        $this->assertStringContainsString('href="' . $this->old->origin . '/docs/Price%20List%202024.pdf"', $page['content'], 'the document keeps its encoded address, a page link becomes a path');
        $this->assertStringContainsString('href="/contact"', $page['content']);
        $html = $this->files()->bring($page['content'], $state);
        $this->assertSame(1, $state['result']['files']);
        $this->assertMatchesRegularExpression('#href="/media/\d{4}/\d{2}/price-list-2024-[0-9a-f]{6}\.pdf"#', $html, 'the link points at the Media file');
    }

    public function testTheQueryThatIdentifiesADocumentIsKept(): void
    {
        $this->assertSame([$this->old->origin . '/download.pdf?id=7'], WebImportFiles::documentLinks('<a href="/download.pdf?id=7">x</a>', $this->old->origin));
    }

    public function testADocumentThatCannotBeFetchedIsReportedAndItsLinkKeepsTheOldAddress(): void
    {
        $state = $this->state();
        $address = $this->old->origin . '/docs/Missing%20List.pdf';
        $html = '<a href="' . $address . '">list</a>';
        $files = new WebImportFiles($this->db(), new ImageDownloader($this->old->origin), $this->author(), '', null, '/prices');
        $this->assertSame($html, $files->bring($html, $state));
        $this->assertSame([['kind' => 'document', 'path' => '/prices', 'host' => 'Missing List.pdf']], $state['attention']);
    }
}
