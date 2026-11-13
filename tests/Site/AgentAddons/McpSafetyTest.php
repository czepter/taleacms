<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AgentAddons;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * MCP safety (#334): downloads by address only on full access, the untrusted marker, the two-step confirmation and what a
 * scheduled run does about it. The tests run in order.
 */
#[Group('site')]
final class McpSafetyTest extends SiteTestCase
{
    use AgentHelpers;

    /** A read-only token for the admin user, made directly in the database. */
    private function readToken(): string
    {
        $token = 'talea_' . str_repeat('d', 48);
        $this->site()->exec("INSERT INTO tl_api_tokens (user_id, name, token_hash, access, created_at) SELECT user_id, 'Reader', ?, 'read', NOW() FROM tl_users WHERE username = 'admin'", [hash('sha256', $token)]);

        return $token;
    }

    /** @return list<string> the tool names tools/list shows for a token */
    private function listedTools(string $token): array
    {
        $response = $this->site()->mcpRaw((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), $token);

        return array_column((array) ($response['result']['tools'] ?? []), 'name');
    }

    public function testUrlFetchingNeedsFullAccess(): void
    {
        $drafts = $this->draftsToken();
        $before = $this->sq('SELECT COUNT(*) FROM tl_media');

        $this->assertStringContainsString('full access', $this->mcpRawText('upload_file', ['filename' => 'a.png', 'url' => 'https://example.com/a.png'], $drafts), 'a drafts-only connection cannot make the site download by address');
        $this->assertStringContainsString('full access', $this->mcpRawText('upload_files', ['files' => [['filename' => 'a.png', 'data' => base64_encode('x')], ['filename' => 'b.png', 'url' => 'https://example.com/b.png']]], $drafts), 'one url file refuses the whole call');
        $this->assertSame($before, $this->sq('SELECT COUNT(*) FROM tl_media'), 'nothing was stored');

        $png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->assertStringNotContainsString('full access', $this->mcpRawText('upload_file', ['filename' => 'sent.png', 'data' => $png], $drafts), 'a file it sends itself is fine');
        $this->assertSame((string) ((int) $before + 1), $this->sq('SELECT COUNT(*) FROM tl_media'), 'the file it sent is stored');

        $read = $this->readToken();
        $this->assertNotContains('migration_report', $this->listedTools($read), 'a read-only connection does not list the tool that crawls another site');
        $this->assertStringContainsString('full access', $this->mcpRawText('migration_report', ['url' => 'https://example.com'], $read));
        $this->assertNotContains('migration_report', $this->listedTools($drafts));
        $this->assertContains('migration_report', $this->listedTools($this->site()->mcpToken), 'a full connection lists it');
    }
}
