<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\CollectionsA;

use Kaleta\Tests\Site\Support\Http;
use Kaleta\Tests\Site\Support\Response;

/** Shared helpers of the CollectionsA classes (the old mcp / sq / site_date / field_value shell functions). */
trait CollectionsHelpers
{
    /** The text a tool returned, as it is (the old `mcp … > response` followed by `contains -q`). @param array<string, mixed> $args */
    protected function mcpText(string $tool, array $args = []): string
    {
        return (string) ($this->site()->mcp($tool, $args)['result']['content'][0]['text'] ?? '');
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    protected function mcpData(string $tool, array $args = []): array
    {
        return (array) json_decode($this->mcpText($tool, $args), true);
    }

    /** The old sq: first column of the first row as a string. @param list<mixed> $params */
    protected function sq(string $sql, array $params = []): string
    {
        return (string) $this->site()->value($sql, $params);
    }

    /** A date on the site's clock (Europe/Prague set in bootstrap.php), like the old site_date. */
    protected function siteDate(string $when): string
    {
        return trim($this->site()->php('echo date("Y-m-d", strtotime(' . var_export($when, true) . '));'));
    }

    protected function visitor(): Http
    {
        return $this->site()->client('visitor');
    }

    /** The last value of a hidden input (the old `grep -o … | tail -1`). */
    protected function lastField(Response $page, string $name): string
    {
        preg_match_all('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $page->body, $m);

        return html_entity_decode((string) (end($m[1]) ?: ''));
    }
}
