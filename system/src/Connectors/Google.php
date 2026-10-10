<?php

declare(strict_types=1);

namespace Talea\Connectors;

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
     * Search Console (Core\SearchData), the Business Profile (Core\GoogleBusiness) and the sheet of enquiries
     * (Core\EnquirySheet): the property to read, the location to sync, the news opt-in, the enquiry switch, the forms and
     * the spreadsheet "Create the sheet" filled in. The Business Profile keys have their own section of the Connections screen.
     */
    public static function settings(): array
    {
        return ['search_console_site' => ['Search Console property', 'sc-domain:example.com or https://example.com/ – load the list with the button below; empty = this site’s address.'],
            'location' => ['Business Profile location', 'accounts/…/locations/… – choose it from the loaded list'], 'post_news' => ['Post news to the Business Profile', ''],
            'enquiries' => ['Enquiries to a sheet', '', 'check']] + \Talea\Core\EnquiryDelivery::SETTINGS
            + ['sheet_id' => ['Spreadsheet ID', 'Filled in by “Create the sheet”; clear it to have a new sheet created']];
    }

    public static function disconnected(\Talea\Core\App $app): void
    {
        \Talea\Core\GoogleBusiness::forget($app);
    }
}
