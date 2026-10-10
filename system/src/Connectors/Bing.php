<?php

declare(strict_types=1);

namespace Talea\Connectors;

/**
 * Bing Webmaster Tools (2.13): the queries and pages Bing shows the site for (Core\SearchData). The administrator pastes
 * the API key from Webmaster Tools → Settings → API access; Bing wants it as the query parameter apikey, not in a header,
 * so the key travels in the address of every call – Core\Connectors logs only the path, never the query.
 */
final class Bing extends Connector
{
    public const string KEY = 'bing';
    public const string NAME = 'Bing Webmaster Tools';
    public const string AUTH = 'token';
    public const string HELP_URL = 'https://www.bing.com/webmasters/';
    public const int PER_MINUTE = 30;

    public static function settings(): array
    {
        return ['site_url' => ['Site in Bing Webmaster Tools', 'The address as it is registered there, e.g. https://example.com/ – empty = this site’s address.']];
    }

    /** No header: Bing takes the key in the query (authQuery). */
    public static function authHeaders(string $credential, string $account): array
    {
        return [];
    }

    public static function authQuery(string $credential): array
    {
        return ['apikey' => $credential];
    }
}
