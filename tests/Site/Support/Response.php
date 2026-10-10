<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Support;

/** An HTTP answer of the test site. */
final class Response
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $redirect,
        public readonly array $headers = [],
    ) {
    }

    public function contains(string $needle): bool
    {
        return str_contains($this->body, $needle);
    }

    public function matches(string $pattern): bool
    {
        return preg_match($pattern, $this->body) === 1;
    }

    /** @return mixed decoded JSON body (null when it is not JSON) */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    /** Visible text of an HTML page: tags removed, whitespace folded. */
    public function text(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($this->body)));
    }

    /** The anti-forgery token of the page (name="_csrf"). */
    public function csrf(): string
    {
        return preg_match('/name="_csrf" value="([a-f0-9]*)"/', $this->body, $m) === 1 ? $m[1] : '';
    }

    /** The value of the first input with this name: name="x" value="…". */
    public function field(string $name): string
    {
        return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $this->body, $m) === 1 ? html_entity_decode($m[1]) : '';
    }

    /** True when the page shows a PHP error (what the old check() treated as a failure). */
    public function hasPhpError(): bool
    {
        return preg_match('/Fatal error|Warning:|Deprecated:|Notice:/', $this->body) === 1;
    }
}
