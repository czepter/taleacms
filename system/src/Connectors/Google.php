<?php

declare(strict_types=1);

namespace Kaleta\Connectors;

/**
 * Google (2.13): one sign-in for the Business Profile, Search Console and Sheets. The site uses its own OAuth app – the
 * administrator creates it in Google Cloud and enters the client id and secret; the redirect address is shown in the
 * admin. Only the scopes listed here are ever asked for.
 */
final class Google extends Connector
{
    public const string KEY = 'google';
    public const string NAME = 'Google';
    public const string AUTH = 'oauth';
    public const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    public const array SCOPES = [
        'https://www.googleapis.com/auth/business.manage',     // the Business Profile: hours, posts, reviews
        'https://www.googleapis.com/auth/webmasters.readonly', // Search Console: queries, clicks, positions
        'https://www.googleapis.com/auth/drive.file',          // Sheets: only the files the site creates or is given
        'openid', 'email',
    ];
    public const string HELP_URL = 'https://console.cloud.google.com/apis/credentials';
    public const int PER_MINUTE = 60;

    /**
     * Search Console (Core\SearchData) and the Business Profile (Core\GoogleBusiness): the property to read, the location to
     * sync and the news opt-in – the Business Profile keys have their own section of the Connections screen.
     */
    public static function settings(): array
    {
        return ['search_console_site' => ['Search Console property', 'sc-domain:example.com or https://example.com/ – load the list with the button below; empty = this site’s address.'],
            'location' => ['Business Profile location', 'accounts/…/locations/… – choose it from the loaded list'], 'post_news' => ['Post news to the Business Profile', '']];
    }

    public static function disconnected(\Kaleta\Core\App $app): void
    {
        \Kaleta\Core\GoogleBusiness::forget($app);
    }
}
