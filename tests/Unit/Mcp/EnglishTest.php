<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

final class EnglishTest extends TestCase
{
    public function testToolsAndMessagesAreEnglish(): void
    {
        // MCP in English (since the hard fork the implementation is English: there is no translation layer): every tool of the public contract is
        // implemented, and no tool name, parameter name or description carries a Czech word or a Czech diacritic
        $mcpContract = json_decode((string) file_get_contents(TALEA_ROOT . '/tools/contracts/mcp-tools.json'), true);
        $mcpDefined = array_column([...\Talea\Mcp\Tools::definitions(), ...\Talea\Mcp\Discovery::definitions()], null, 'name'); // the meta tools of the Essentials tool set are in the contract too
        $mcpCzech = [];
        foreach (\Talea\Mcp\Tools::definitions() as $mcpTool) {
            $mcpText = $mcpTool['name'] . ' ' . $mcpTool['description'];
            foreach ((array) $mcpTool['inputSchema']['properties'] as $mcpParam => $mcpDefinition) {
                $mcpText .= ' ' . $mcpParam . ' ' . ($mcpDefinition['description'] ?? '');
            }
            if (preg_match('/[ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚ]/u', $mcpText) === 1 || preg_match('/\b(nazev|adresa|stranka|stranky|polozka|polozky|kolekce|poradi|publikovat|hledat|stitky|kategorie|datum|vydat|operace|sekce|uplne|prvky|zobrazit|smazat|nahradit|komentare|potvrdit|presmerovani|novinky|vzor|predvolba)\b/u', $mcpText) === 1) { // check-english: allow
                $mcpCzech[] = $mcpTool['name'];
            }
        }
        $this->assertSame([[], [], [], false], [array_values(array_diff(array_keys($mcpContract), array_keys($mcpDefined))), array_values(array_diff(array_keys($mcpDefined), array_keys($mcpContract), array_keys(\Talea\Mcp\Catalog::TOOLS), array_keys(\Talea\Mcp\Catalog::META))), $mcpCzech, class_exists('Talea\\Mcp\\Translator', false)], 'MCP: every tool of the contract is implemented under its English name; no Czech in the names, parameters and descriptions');
        $mcpCzechMessages = [];
        foreach ([...glob(TALEA_ROOT . '/system/src/Mcp/*.php'), ...glob(TALEA_ROOT . '/system/src/Mcp/Handlers/*.php')] as $mcpFile) {
            if (preg_match("/Exception\\('[^']*[ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚ]/u", (string) file_get_contents($mcpFile)) === 1) { // check-english: allow
                $mcpCzechMessages[] = basename($mcpFile);
            }
        }
        $this->assertSame([], $mcpCzechMessages, 'MCP: the fixed messages of the tools are English (no Czech diacritics in the exceptions of Mcp\\)');
    }
}
