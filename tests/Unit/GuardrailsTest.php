<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GuardrailsTest extends TestCase
{
    /** 2.15: guardrails for Claude and the reason of a change (Core\Guardrails) */
    public function testGuardrails(): void
    {
        $this->assertSame([12, 7, null, null, null, null], [
            \Talea\Core\Guardrails::targetPage('update_page', ['id' => 12]), \Talea\Core\Guardrails::targetPage('save_build', ['id' => '7']), \Talea\Core\Guardrails::targetPage('save_build', ['id' => 7, 'component' => 3]),
            \Talea\Core\Guardrails::targetPage('save_build', ['part' => 'header']), \Talea\Core\Guardrails::targetPage('update_news', ['id' => 12]), \Talea\Core\Guardrails::targetPage('publish_build', ['id' => 0])], '2.15 Guardrails::targetPage – a page tool with an id is that page; another build target or another tool is none');
        $this->assertSame(['Request #4: new hours', 255, '', ''], [
            \Talea\Core\Guardrails::reason("  Request #4:\n<b>new</b> hours  "), mb_strlen(\Talea\Core\Guardrails::reason(str_repeat('a', 400))), \Talea\Core\Guardrails::reason(['x']), \Talea\Core\Guardrails::reason(null)], '2.15 Guardrails::reason – one line of plain text, at most 255 characters; anything else is none');
        $withReason = \Talea\Mcp\Server::withReason(['name' => 'update_page', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]);
        $this->assertSame([['id', 'reason'], []], [array_keys($withReason['inputSchema']['properties']),
            \Talea\Mcp\Server::withReason(['name' => 'get_page', 'inputSchema' => ['type' => 'object', 'properties' => []]])['inputSchema']['properties']], '2.15 Server::withReason – write tools offer a reason, read tools do not');
    }

    /** 2.15: agent notebook – topic validation */
    public function testNotebookTopics(): void
    {
        $this->assertSame(['decisions', 'style', 'todo', null, null, null, null], [
            \Talea\Core\Notebook::topic('decisions'), \Talea\Core\Notebook::topic(' Style '), \Talea\Core\Notebook::topic('TODO'), \Talea\Core\Notebook::topic('pricing'),
            \Talea\Core\Notebook::topic(''), \Talea\Core\Notebook::topic(null), \Talea\Core\Notebook::topic(['style'])], '2.15: Notebook::topic – a known topic in any case and with spaces around; anything else (an unknown word, empty, not a string) is refused');
        $this->assertSame(['decisions', 'style', 'credits', 'history', 'todo', 'other'], array_keys(\Talea\Core\Notebook::TOPICS), '2.15: Notebook::TOPICS – the topics the admin filter, read_notebook and the export know, every one with a label');
    }
}
