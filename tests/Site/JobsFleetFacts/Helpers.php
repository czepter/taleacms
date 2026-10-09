<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\JobsFleetFacts;

use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\Site;

/** Small helpers shared by the classes of this area (the old `mcp` + `contains` and `expect` shell functions). */
trait Helpers
{
    /** The raw text an MCP tool returned (the old `mcp tool '{…}' > response` followed by greps). @param array<string, mixed> $args */
    private function mcpText(string $tool, array $args = [], ?Site $site = null): string
    {
        $answer = ($site ?? $this->site())->mcp($tool, $args);

        return (string) ($answer['result']['content'][0]['text'] ?? json_encode($answer));
    }

    /** POST as a site's administrator with a fresh token taken from $formPage. @param array<string, mixed> $fields */
    private function postAs(Site $site, string $path, array $fields, string $formPage): Response
    {
        $http = $site->admin();

        return $http->post($path, ['_csrf' => $http->get($formPage)->csrf()] + $fields);
    }

    private function sameValue(string $expected, mixed $actual, string $message): void
    {
        $this->assertSame($expected, (string) $actual, $message);
    }
}
