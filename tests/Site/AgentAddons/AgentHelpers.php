<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AgentAddons;

/**
 * Small helpers shared by the Claude/add-on site tests of this area (the old lib.sh mcp, mcp_as, mcp_text, mcp_value, contains,
 * and the drafts-only token that section 44 created). Needs the SiteTestCase methods site() and the PHPUnit assertions.
 */
trait AgentHelpers
{
    private static ?string $draftsToken = null;

    /** The whole answer of an MCP tool call as one JSON string (what the old script grepped). @param array<string, mixed>|object $args */
    private function mcpRawText(string $tool, array|object $args = [], ?string $token = null): string
    {
        return (string) json_encode($this->site()->mcp($tool, $args, $token), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The tool's own text (old mcp_text). @param array<string, mixed>|object $args */
    private function mcpText(string $tool, array|object $args = [], ?string $token = null): string
    {
        return (string) ($this->site()->mcp($tool, $args, $token)['result']['content'][0]['text'] ?? '');
    }

    /** The tool's text decoded as JSON. @param array<string, mixed>|object $args @return array<string, mixed> */
    private function mcpData(string $tool, array|object $args = [], ?string $token = null): array
    {
        $decoded = json_decode($this->mcpText($tool, $args, $token), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Old mcp_value: a nested value as the shell printed it (scalars as strings, null/false empty, arrays as JSON). */
    private function pick(mixed $data, string|int ...$keys): string
    {
        foreach ($keys as $key) {
            $data = is_array($data) ? ($data[$key] ?? null) : null;
        }

        return is_scalar($data) ? (string) $data : ($data === null ? '' : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The first "id":N in a text (grep -o '"id":[0-9]*' | head -1). */
    private function firstId(string $text): int
    {
        return preg_match('/"id":(\d+)/', $text, $m) ? (int) $m[1] : 0;
    }

    /** grep -c: the number of lines that contain the text. */
    private function lines(string $needle, string $haystack): int
    {
        return count(array_filter(explode("\n", $haystack), static fn (string $line): bool => str_contains($line, $needle)));
    }

    /** First column of a query as a string (what the mysql client printed). @param list<mixed> $params */
    private function sq(string $sql, array $params = []): string
    {
        return (string) $this->site()->value($sql, $params);
    }

    /** The drafts-only API token of section 44: created in My account, kept for the class. */
    private function draftsToken(): string
    {
        if (self::$draftsToken !== null) {
            return self::$draftsToken;
        }
        $admin = $this->site()->admin();
        $csrf = $admin->get('/admin.php?action=account')->csrf();
        $response = $admin->post('/admin.php?action=account', ['_csrf' => $csrf, 'co' => 'token_novy', 'nazev' => 'Claude drafts', 'access' => 'drafts']);
        $this->assertMatchesRegularExpression('/kaleta_[a-f0-9]{48}/', $response->body, 'My account shows the new drafts-only token');
        preg_match('/kaleta_[a-f0-9]{48}/', $response->body, $m);

        return self::$draftsToken = $m[0];
    }

    /** An API token for a user, made directly in the database (a fixed secret of 48 repeated characters). */
    private function tokenOf(string $user, string $name, string $char): string
    {
        $token = 'kaleta_' . str_repeat($char, 48);
        $this->site()->exec('INSERT INTO ka_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, ?, ?, NOW() FROM ka_users WHERE username = ?', [$name, hash('sha256', $token), $user]);

        return $token;
    }
}
