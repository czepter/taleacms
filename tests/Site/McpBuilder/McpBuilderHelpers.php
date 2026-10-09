<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\McpBuilder;

use Kaleta\Tests\Site\Support\Http;

/**
 * Shared by the classes of this area: the anonymous visitor, MCP answers as text/data, and the page the old section 9 created
 * (IDZ = /z-html, built by Claude through MCP) that the later sections kept building on.
 */
trait McpBuilderHelpers
{
    private static ?Http $visitorBrowser = null;
    private static int $zPage = 0;

    private function visitor(): Http
    {
        return self::$visitorBrowser ??= $this->site()->client('visitor');
    }

    /** The text of a tool's answer (or the whole answer when it has none), as the old greps saw it. */
    private function rawText(string $tool, array $args = []): string
    {
        $answer = $this->site()->mcp($tool, $args);
        $text = $answer['result']['content'][0]['text'] ?? null;

        return is_string($text) ? $text : (string) json_encode($answer, JSON_UNESCAPED_UNICODE);
    }

    /** Like rawText() with JSON-escaped slashes written plainly (the old greps looked for "tym\/zdenek" in a double-encoded answer). */
    private function mcpText(string $tool, array $args = []): string
    {
        return str_replace('\/', '/', $this->rawText($tool, $args));
    }

    /** The decoded JSON a tool returned. */
    private function mcpData(string $tool, array $args = []): mixed
    {
        return json_decode($this->rawText($tool, $args), true);
    }

    /** Fetches a page as an anonymous visitor (the old plain `curl -s "$B/…"`). */
    private function visit(string $path): string
    {
        return $this->visitor()->get($path)->body;
    }

    /** The page of the old section 9: Claude builds it from HTML through MCP, it is published and shown. Created once per class. */
    private function zPage(): int
    {
        if (self::$zPage !== 0) {
            return self::$zPage;
        }
        $site = $this->site();
        $site->mcp('stavba_z_html', ['titulek' => 'Z HTML', 'html' => self::Z_HTML]);
        self::$zPage = (int) $site->value("SELECT ids FROM ka_stranky WHERE seo_link = 'z-html'");
        $site->mcp('vloz_sekci', ['id' => self::$zPage, 'sekce' => 'faq']);
        $site->mcp('publikuj_stavbu', ['id' => self::$zPage]);
        $site->exec('UPDATE ka_stranky SET zobrazit = 1 WHERE ids = ?', [self::$zPage]);
        $site->clearPageCache();

        return self::$zPage;
    }

    private const string Z_HTML = '<style>.uvod-x { padding-block: var(--ka-mezera-2xl); } .uvod-x h1 { color: red }</style><header class="uvod-x"><div class="container"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class="btn" href="/kontakt">Kontakt</a></div></header><form><input></form>';
}
