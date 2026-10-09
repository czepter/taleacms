<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

/** The few MCP shortcuts the old shell helpers (mcp, mcp_text, mcp_value) gave to the sections of this area. */
trait McpHelpers
{
    /** The text a tool returned (the old mcp_text). @param array<string, mixed> $arguments */
    private function mcpText(string $tool, array $arguments = []): string
    {
        return (string) ($this->site()->mcp($tool, $arguments)['result']['content'][0]['text'] ?? '');
    }

    /** The whole JSON-RPC answer as one string (the old raw $WORK/response). @param array<string, mixed> $arguments */
    private function mcpRawAnswer(string $tool, array $arguments = []): string
    {
        return (string) json_encode($this->site()->mcp($tool, $arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Creates a page (create_page / vytvor_stranku) and returns its id. @param array<string, mixed> $arguments */
    private function createPage(array $arguments, string $tool = 'create_page'): int
    {
        $text = $this->mcpText($tool, $arguments);
        $this->assertSame(1, preg_match('/"id":(\d+)/', $text, $m), "$tool answered without an id: " . mb_substr($text, 0, 200));

        return (int) $m[1];
    }

    /** A page id by its address. */
    private function pageIdBySlug(string $slug): int
    {
        return (int) $this->site()->value('SELECT ids FROM ka_stranky WHERE seo_link = ?', [$slug]);
    }

    /** A PNG as base64 for upload_file (the old $PNG); skips the test when GD is missing. */
    private function pngBase64(): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The PHP used by the test has no GD.');
        }
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 79, 46));
        ob_start();
        imagepng($image);

        return base64_encode((string) ob_get_clean());
    }
}
