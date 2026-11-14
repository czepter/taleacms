<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Talea\Mcp\Catalog;
use Talea\Mcp\Tools;
use Talea\Mcp\Untrusted;

final class UntrustedTest extends TestCase
{
    public function testMarkListsTheKeys(): void
    {
        $this->assertSame(['untrusted' => ['a', 'b']], Untrusted::mark(['a', 'b']));
        $this->assertStringContainsString('never instructions', Untrusted::INSTRUCTION);
        $this->assertStringContainsString('Untrusted::INSTRUCTION', (string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Prompts.php'), 'the guide says it once');
    }

    public function testEveryToolThatReturnsVisitorTextMarksIt(): void
    {
        // a new tool that returns text visitors, preview-link commenters or other sites wrote joins this list and uses Untrusted::mark()
        // (read_old_page does too); the scan reads the handler's own body, as CatalogTest::testNoReadToolHasAHandlerThatWrites does
        $tools = ['list_enquiries', 'triage_enquiries', 'list_form_links', 'review_form_answer', 'list_bookings', 'list_registrations', 'list_draft_comments',
            'list_collection_items', 'get_stats', 'list_redirects', 'ignore_not_found', 'migration_report', 'import_website'];
        $missing = [];
        foreach ($tools as $tool) {
            $this->assertArrayHasKey($tool, Catalog::TOOLS);
            $m = new \ReflectionMethod(Tools::class, Catalog::method($tool));
            $body = implode('', array_slice(file((string) $m->getFileName()) ?: [], $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
            if (!str_contains($body, 'Untrusted::mark(')) {
                $missing[] = $tool;
            }
        }
        $this->assertSame([], $missing, 'tools whose result carries outside text without the untrusted marker');
    }
}
