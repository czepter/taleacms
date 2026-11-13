<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\WebImport;
use Talea\Core\WebImportFiles;
use Talea\Core\WebImportWidgets;

#[CoversClass(WebImportFiles::class)]
#[CoversClass(WebImportWidgets::class)]
final class WebImportDocumentsTest extends TestCase
{
    public function testDocumentsAreDownloadedFromTheOldSitesOwnHostOnly(): void
    {
        $downloader = WebImportFiles::downloader('https://www.old.example');
        $this->assertSame([true, false, false], [$downloader->isAllowedUrl('https://old.example/a.pdf'), $downloader->isAllowedUrl('https://cdn.other.example/a.pdf'),
            $downloader->isAllowedUrl('https://www.old.example.evil.example/a.pdf')], 'a redirect step to another host is refused: every step is checked by isAllowedUrl');
    }

    /** @return array{0: list<array{kind: string, html: string, host: string}>, 1: array<string, mixed>} */
    private function prepared(string $panel, bool $enquiries): array
    {
        $page = WebImport::extract('<html><head><title>FAQ | Acme</title></head><body><main><h1>FAQ</h1><p>' . str_repeat('Questions we hear again and again. ', 4) . '</p>'
            . '<div class="faq-item"><div class="faq-question">First?</div><div class="faq-answer"><p>Yes.</p></div></div>'
            . '<div class="faq-item"><div class="faq-question">Second?</div><div class="faq-answer">' . $panel . '</div></div></main></body></html>', 'https://old.example/faq/');
        $state = ['attention' => []];
        $widgets = WebImportWidgets::prepare($page['widgets'], $page['content'], 'en', $enquiries, '/faq', $state);

        return [$widgets, $state];
    }

    public function testAFrameInsideAnAccordionGetsAPlaceholderAndALineUnderAttention(): void
    {
        [$widgets, $state] = $this->prepared('<p>See our calendar.</p><iframe src="https://calendar.unknown-service.example/embed/1"></iframe>', true);
        $this->assertSame(['accordion'], array_column($widgets, 'kind'));
        $this->assertStringNotContainsString('<iframe', $widgets[0]['html']);
        $this->assertStringContainsString('[Embedded content from calendar.unknown-service.example: add it again with an Embed element.]', $widgets[0]['html']);
        $this->assertSame([['kind' => 'frame', 'path' => '/faq', 'host' => 'calendar.unknown-service.example']], $state['attention']);
    }

    public function testAFormInsideAnAccordionIsMetLikeOneOnThePage(): void
    {
        [$widgets, $state] = $this->prepared('<form action="/send"><input name="email" type="email"><button>Send</button></form>', false);
        $this->assertStringNotContainsString('<form', $widgets[0]['html']);
        $this->assertStringContainsString('[A form was here: switch on Enquiries and add a form.]', $widgets[0]['html']);
        $this->assertSame('form_off', $state['attention'][0]['kind']);
        [$widgets, $state] = $this->prepared('<form action="/send"><input name="email" type="email"><button>Send</button></form>', true);
        $this->assertStringContainsString('<form', $widgets[0]['html'], 'with Enquiries on the form stays a form for the converter');
        $this->assertSame([], $state['attention']);
    }
}
