<?php

declare(strict_types=1);

namespace Kaleta\Connectors;

/**
 * An outside service a site can connect to (2.13, Core\Connectors): how it signs in and where its API is. Features that
 * use it (Search Console data, a sheet of enquiries, a CRM lead) live in their own classes and call
 * Core\Connectors::request() with the service's key; deliveries that may fail go through Core\Connectors::queue().
 *
 * AUTH: oauth – the site's own OAuth app (client id and secret entered by an administrator), PKCE and a refresh token;
 * token – an API token the administrator pastes; basic – a user name and an API key.
 */
abstract class Connector
{
    public const string KEY = '';
    public const string NAME = '';
    public const string AUTH = 'token';
    public const string AUTHORIZE_URL = '';
    public const string TOKEN_URL = '';
    public const string REVOKE_URL = '';
    /** @var list<string> */
    public const array SCOPES = [];
    /** Where an administrator gets the credentials. */
    public const string HELP_URL = '';
    /** At most this many API calls a minute from one site. */
    public const int PER_MINUTE = 60;

    /**
     * Settings of the connection the administrator fills in (which sheet, which location, which pipeline):
     * key => [label, hint]. Stored in ka_connectors.config.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function settings(): array
    {
        return [];
    }

    /** The header that authorises a call with the stored credential. @return array<string, string> */
    public static function authHeaders(string $credential, string $account): array
    {
        return ['Authorization' => 'Bearer ' . $credential];
    }
}
