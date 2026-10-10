<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsLook;

/** Small shell-helper replacements shared by the classes of this area (mcp_value, sq, grep -c, field_value). */
trait SiteHelpers
{
    /** The decoded result of an MCP tool (the text when it is not JSON). @param array<string, mixed> $arguments */
    private function call(string $tool, array $arguments = []): mixed
    {
        return $this->site()->mcpResult($tool, $arguments);
    }

    /** The whole JSON-RPC answer as text, for the old "mcp … | contains 'text'" checks. @param array<string, mixed> $arguments */
    private function raw(string $tool, array $arguments = []): string
    {
        return (string) json_encode($this->site()->mcp($tool, $arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The old mcp_value: a value by keys; scalars as text (true = "1", false/null = ""), arrays as JSON. */
    private function pick(mixed $value, string|int ...$keys): string
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_scalar($value) ? ($value === true ? '1' : (string) $value) : ($value === null ? '' : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** First column of the first row as text (the old sq). @param list<mixed> $params */
    private function sql(string $sql, array $params = []): string
    {
        return (string) $this->site()->value($sql, $params);
    }

    /** The first row as "a|b|c" (the old sq … | tr '\t' '|'). */
    private function sqlRow(string $sql): string
    {
        $row = $this->site()->rows($sql)[0] ?? [];

        return implode('|', array_map(static fn ($v) => (string) $v, array_values($row)));
    }

    /** The old grep -c: the number of lines that contain the text. */
    private function lines(string $body, string $needle): int
    {
        return count(array_filter(explode("\n", $body), static fn (string $line) => str_contains($line, $needle)));
    }

    /** The old field_value: value of name="x" value="…". */
    private function formField(string $html, string $name): string
    {
        return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) === 1 ? $m[1] : '';
    }
}
