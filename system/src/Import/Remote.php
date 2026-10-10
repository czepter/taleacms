<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * A structured importer whose "export" does not exist as a file: Joomla and Drupal only have an API. Such a Source
 * also implements this interface and Import\Fetch turns the API into a local JSON file in storage/import/sources/,
 * which the Source then reads like any other export (so Batch, the preview and the mapping stay the same).
 *
 * The fetch runs in steps (articles, categories, users…), each step is a paginated listing. The admin ticks which steps
 * to fetch; the first step is the content itself and is always fetched. The static methods here are pure: they build
 * addresses and read answers, they never make a request themselves – Fetch does, under its SSRF rules.
 */
interface Remote
{
    /**
     * What can be fetched, in order: step key => English label (goes through t()). The first step is required.
     *
     * @return non-empty-array<string, string>
     */
    public static function steps(): array;

    /** How the administrator gets an API token, in English (goes through t()); empty = no token is used. */
    public static function tokenHint(): string;

    /**
     * HTTP header lines for a request with the given token ('' = the administrator typed none). Any Accept header goes
     * here too.
     *
     * @return list<string>
     */
    public static function headers(string $token): array;

    /** The address of the first page of a step on the old site (absolute). */
    public static function firstPage(string $siteUrl, string $step): string;

    /**
     * Reads one answered page: the items, the included (side-loaded) resources and the address of the next page.
     *
     * @param array<string, mixed> $json the decoded answer, not trusted
     * @return array{0: list<mixed>, 1: list<mixed>, 2: string} items, included resources, next page URL ('' = the last page)
     * @throws \RuntimeException with an English message when the answer is not this system's API (the wrong address)
     */
    public static function page(array $json, string $step): array;
}
