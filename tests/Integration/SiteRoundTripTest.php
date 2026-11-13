<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Builder\Build;
use Talea\Builder\Collections;
use Talea\Core\Db;
use Talea\Core\Migrator;
use Talea\Core\Settings;
use Talea\Core\SiteExport;
use Talea\Core\SiteImport;
use Talea\Core\WpFile;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * The whole journey of a Talea archive: a site with one of everything the editor and MCP can make is exported (SiteExport::create),
 * imported into an EMPTY site (SiteImport, the classic import: prepare, data and media batches) and exported again. The two archives
 * must be the same, table by table and value by value – a value the export writes and the import drops (or the other way round) is
 * data a moved site loses without any error. A second check keeps the lists of the export honest: every table and every column of the
 * schema, and every setting, is either in the archive or named here with the reason it stays behind.
 *
 * The source is the class's database under tl_, the empty site the same database under rt_ (its own migrations).
 */
#[CoversClass(SiteExport::class)]
#[CoversClass(SiteImport::class)]
final class SiteRoundTripTest extends DatabaseTestCase
{
    private const string TARGET_PREFIX = 'rt_';

    /** Tables that never travel, with the reason (a new table must be added to the export or here). */
    private const array NOT_EXPORTED = [
        // personal data of visitors and customers, and what belongs to them
        'enquiries' => 'personal data', 'form_steps' => 'personal data', 'form_links' => 'personal data (a link names its person)', 'bookings' => 'personal data',
        'booking_proposals' => 'personal data', 'subscribers' => 'personal data', 'subscription_queue' => 'personal data', 'newsletters' => 'mailings to subscribers',
        'newsletter_queue' => 'mailings to subscribers', 'members' => 'personal data (accounts are invited again)', 'member_group_links' => 'personal data',
        'member_tokens' => 'secrets', 'member_sessions' => 'secrets', 'consents' => 'personal data', 'draft_comments' => 'comments of reviewers on drafts', 'mail' => 'the mail queue',
        // accounts, secrets, connections
        'users' => 'accounts', 'user_passkeys' => 'secrets', 'user_permissions' => 'accounts', 'user_remember_tokens' => 'secrets', 'user_devices' => 'accounts', 'user_sites' => 'accounts',
        'role' => 'accounts', 'api_tokens' => 'secrets', 'oauth_clients' => 'secrets', 'oauth_codes' => 'secrets', 'handoff_tokens' => 'secrets', 'connectors' => 'secrets',
        'connector_log' => 'operational', 'connector_queue' => 'operational', 'fleet_pairing' => 'secrets', 'fleet_sites' => 'the console\'s own sites', 'fleet_kits' => 'the console\'s kits',
        // statistics, logs and history of this site
        'stats_days' => 'statistics', 'stats_pages' => 'statistics', 'stats_news' => 'statistics', 'stats_sources' => 'statistics', 'stats_devices' => 'statistics', 'stats_campaigns' => 'statistics',
        'stats_conversions' => 'statistics', 'stats_experiments' => 'statistics', 'stats_visitors' => 'statistics', 'web_vitals' => 'statistics', 'search_stats' => 'statistics',
        'events' => 'statistics', 'not_found' => 'statistics', 'ip_checks' => 'operational', 'document_downloads' => 'statistics', 'monthly_reports' => 'reports of this site',
        'change_log' => 'history', 'page_revisions' => 'history', 'news_revisions' => 'history', 'build_revisions' => 'history', 'look_versions' => 'history', 'fact_history' => 'history',
        'agent_sessions' => 'history', 'agent_journal' => 'history', 'agent_runs' => 'history', 'agent_schedules' => 'set up per site in Claude', 'requests' => 'the team\'s work list',
        'request_messages' => 'the team\'s work list', 'news_drafts' => 'autosave of one user', 'social_drafts' => 'drafts of posts, made again from the news',
        // operational and derived
        'settings' => 'in the header (an allow-list)', 'migrations' => 'schema', 'jobs' => 'operational', 'webhook_deliveries' => 'operational', 'mcp_uploads' => 'operational',
        'media_usage' => 'derived', 'broken_links' => 'derived', 'import_map' => 'derived', 'news_tags' => 'in the news rows (tags)', 'google_reviews' => 'fetched again from Google',
        'experiments' => 'A/B tests: tied to this site\'s statistics',
        // the install, not a site
        'sites' => 'the install', 'site_domains' => 'the install', 'owners' => 'the install',
    ];

    /** Columns of exported tables that stay behind on purpose. */
    private const array NOT_EXPORTED_COLUMNS = [
        '*' => ['deleted_at' => 'the trash is not exported', 'seo_score' => 'derived', 'seo_checked_at' => 'derived', 'links_checked' => 'derived', 'links_checked_at' => 'derived'],
        'news' => ['search_text' => 'derived', 'announced_at' => 'operational', 'author_id' => 'accounts (the author travels as a name)'],
        'media' => ['owner_id' => 'accounts', 'staged_at' => 'a waiting file is not exported'],
        'redirects' => ['hits' => 'statistics', 'created_at' => 'operational', 'last_hit_at' => 'statistics'],
        'short_links' => ['hits' => 'statistics', 'last_hit_at' => 'statistics'],
        'popups' => ['impressions' => 'statistics', 'closes' => 'statistics', 'conversions' => 'statistics'],
        'forms' => ['origin' => 'names the integer key of the build it was adopted from', 'created_at' => 'operational'],
        'booking_staff' => ['user_id' => 'accounts'],
        'facts' => ['updated_at' => 'operational', 'site_id' => 'the install'],
        'components' => ['site_id' => 'the install'],
        'hours_exceptions' => ['proposed' => 'only confirmed exceptions travel', 'created_at' => 'operational'],
        'document_versions' => ['downloads' => 'statistics'],
        'blueprints' => ['applied_at' => 'operational'],
        'member_groups' => ['created_at' => 'operational'],
        '' => ['updated_at' => 'operational'],
    ];

    /** Settings that stay behind (an exported setting is in SiteExport::SETTINGS). */
    private const array SETTINGS_NOT_EXPORTED = [
        'secrets' => ['secret_key', 'site_key_secret', 'webhook_secret', 'tasks_token', 'health_token', 'screen_secret', 'indexnow_key', 'captcha_secret', 'smtp_password', 'backup_password', 'ai_key', 'fleet_console_key'],
        'code and trackers' => ['head_code', 'marketing_code', 'cookies_external_code', 'ga4_id', 'gtm_id', 'matomo_url', 'matomo_id', 'plausible_domain', 'plausible_url', 'umami_id', 'umami_url',
            'meta_pixel_id', 'google_ads_id', 'linkedin_partner_id', 'tiktok_pixel_id', 'microsoft_uet_id', 'pinterest_tag_id', 'cookies_mode', 'cookies_log', 'cookies_log_months', 'verification_google', 'verification_bing',
            'captcha_provider', 'captcha_site_key', 'captcha_fail_open'],
        'mail, backups, connections, the agency' => ['site_email', 'mail_mode', 'mail_from', 'mail_from_name', 'mail_reply_to', 'mail_reply_to_name', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_user', 'smtp_provider',
            'remote_backup', 'backup_host', 'backup_user', 'backup_folder', 'backup_region', 'remote_backup_status', 'auto_backups', 'backup_media', 'remote_media_status', 'media_sync_check',
            'webhook_url', 'webhook_enquiries', 'indexnow', 'fleet_console_url', 'fleet_console_name', 'fleet_site_id', 'fleet_last_sent', 'fleet_last_error', 'fleet_kit', 'fleet_kit_version', 'fleet_kit_applied_at', 'fleet_kit_error',
            'agency_enabled', 'agency_name', 'agency_url', 'agency_email', 'agency_phone', 'agency_logo', 'ai_provider', 'ai_model', 'ai_models', 'trusted_proxy', 'security_contact'],
        'this install and its people' => ['redirect_to_site_url', 'maintenance', 'require_2fa', 'passkey_only', 'auto_suspend', 'page_cache', 'stats', 'stats_counting_since', 'indexing', 'look_draft', 'alerts_enabled', 'alerts_email', 'alerts_kinds',
            'alerts_interval', 'alerts_cursor', 'alerts_last_sent', 'report_monthly', 'report_all_sites', 'report_recipients', 'report_last_month', 'claude_change_limit', 'claude_destructive', 'claude_protected_pages', 'claude_instructions',
            'claude_instructions_seen', 'first_steps_hidden', 'first_steps_skipped', 'appearance_saved', 'tasks_last_run', 'notification_check', 'imported_recheck', 'seo_audit_score', 'seo_audit_previous', 'lead_attribution'],
        'about enquiries, subscribers and data kept (which never travel)' => ['enquiries_months', 'triage_assistant', 'spam_assistant', 'enquiry_kinds', 'job_applications_months', 'enquiries_expiry', 'newsletter_hourly_limit', 'data_cleanup',
            'link_check', 'link_check_time', 'redirect_auto', 'redirect_auto_threshold', 'social_networks'],
    ];

    /** Values the import replaces on purpose: [table, column] => what it becomes. */
    private const array DROPPED = [
        'pages.head_code' => 'code from an archive never runs on the new site',
        'news.author' => 'accounts do not travel: the imported news belong to the administrator who imports them',
    ];

    private static bool $made = false;

    /** @var array<string, mixed> */
    private static array $before = [];

    /** @var array<string, mixed> */
    private static array $after = [];

    /** @var array<string, mixed> */
    private static array $state = [];

    /** @var list<string> */
    private static array $files = [];

    private static ?Db $target = null;

    protected function setUp(): void
    {
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                self::$made = true;
                $this->build($config);
            }
        }
        parent::setUp();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$files as $file) {
            is_file($file) && @unlink($file);
        }
        self::$files = [];
        self::$made = false;
        self::$target = null;
        parent::tearDownAfterClass();
    }

    /** The site with one of everything, its archive, the import into the empty site and that site's archive. */
    private function build(array $config): void
    {
        $db = $this->db();
        $this->fill($db);
        self::$before = $this->archive($db);

        Migrator::migrate(['prefix' => self::TARGET_PREFIX] + $config);
        $target = self::$target = Db::fromConfig(['prefix' => self::TARGET_PREFIX] + $config);
        $admin = $target->insert('users', ['username' => 'rt-admin', 'password' => 'x', 'name' => 'Importer', 'email' => 'rt@example.test', 'admin' => 2, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);
        $file = 'round-trip-' . bin2hex(random_bytes(4)) . '.zip';
        copy(self::$before['zip'], WpFile::dir() . '/' . $file);
        self::$files[] = WpFile::dir() . '/' . $file;
        $state = SiteImport::newState($file);
        SiteImport::prepare($state);
        $import = new SiteImport($target, new Settings($target), $admin);
        for ($i = 0; $i < 500 && in_array($state['phase'], ['preview', 'data', 'media'], true); $i++) {
            $state['phase'] === 'media' ? $import->importMedia($state) : $import->importData($state);
            $state['phase'] = $state['phase'] === 'preview' ? 'data' : $state['phase'];
        }
        if ($state['backup'] !== '') {
            self::$files[] = \Talea\Core\Backup::FOLDER . '/' . $state['backup'];
        }
        SiteImport::deleteState($file);
        self::$state = $state;
        self::$after = $this->archive($target);
    }

    /** @return array{zip: string, content: array<string, mixed>} the archive of a site, decoded */
    private function archive(Db $db): array
    {
        $created = SiteExport::create($db, new Settings($db));
        $path = \Talea\Core\Backup::FOLDER . WpFile::sitePart() . '/' . $created['file'];
        self::$files[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path);
        $content = json_decode((string) $zip->getFromName('content.json'), true);
        $zip->close();

        return ['zip' => $path, 'content' => is_array($content) ? $content : []];
    }

    private static function build1(array $children): string
    {
        return Build::toJson(Build::sanitize(['v' => 1, 'children' => $children])[0]);
    }

    /** One of everything the editor and MCP make, with every column the editor fills set to something other than its default. */
    private function fill(Db $db): void
    {
        $now = date('Y-m-d H:i:s');
        $day = date('Y-m-d', strtotime('+30 days'));
        $seo = ['focus_keywords' => "garden\nhedge", 'key_page' => 1, 'robots' => 'nofollow', 'canonical_url' => 'https://example.test/canonical', 'breadcrumb_title' => 'Crumb',
            'share_title' => 'Share title', 'share_description' => 'Share description'];
        @mkdir(TALEA_ROOT . '/media/2026/11', 0775, true);
        $image = imagecreatetruecolor(40, 30);
        imagejpeg($image, TALEA_ROOT . '/media/2026/11/rt-photo.jpg');
        self::$files[] = TALEA_ROOT . '/media/2026/11/rt-photo.jpg';
        file_put_contents(TALEA_ROOT . '/media/2026/11/rt-price-list.pdf', "%PDF-1.4\n%%EOF\n");
        self::$files[] = TALEA_ROOT . '/media/2026/11/rt-price-list.pdf';
        $photo = 'media/2026/11/rt-photo.jpg';

        // media
        $top = $db->insert('media_folders', ['name' => 'Team']);
        $sub = $db->insert('media_folders', ['name' => 'Portraits', 'parent_id' => $top]);
        $db->insert('media', ['image_path' => $photo, 'thumb_path' => '', 'name' => 'Alt text', 'description' => 'Caption', 'author' => 'Photographer', 'folder_id' => $sub,
            'image_width' => 40, 'image_height' => 30, 'image_size' => 1234, 'color' => '#336699', 'focal_point' => '30% 60%', 'created_at' => '2026-10-01 10:00:00', 'source_sha1' => sha1('rt-photo')]);
        $db->insert('media', ['image_path' => 'media/2026/11/rt-price-list.pdf', 'name' => 'Price list', 'created_at' => '2026-10-02 10:00:00']);

        // booking set-up (the booking element below points at it)
        $service = $db->insert('booking_services', ['name' => 'Consultation', 'duration_min' => 45, 'buffer_min' => 15, 'price_text' => '[price]', 'description' => 'A talk', 'active' => 1, 'requires_confirmation' => 1, 'sort_order' => 2]);
        $staff = $db->insert('booking_staff', ['name' => 'Alex', 'email' => 'alex@example.test', 'active' => 1, 'sort_order' => 1]);
        $db->insert('booking_staff_services', ['staff_id' => $staff, 'service_id' => $service]);
        $db->insert('booking_hours', ['staff_id' => $staff, 'service_id' => null, 'weekday' => 1, 'time_from' => '09:00', 'time_to' => '17:00']);
        $db->insert('booking_hours', ['staff_id' => $staff, 'service_id' => $service, 'weekday' => 2, 'time_from' => '10:00', 'time_to' => '12:00']);
        $db->insert('booking_off', ['staff_id' => $staff, 'off_from' => date('Y-m-d H:i:00', strtotime('+3 days')), 'off_to' => date('Y-m-d H:i:00', strtotime('+4 days')), 'note' => 'Away']);

        // a component, a form record, a library section, a page template
        $component = $db->insert('components', ['name' => 'Hero', 'properties' => '[]', 'site_id' => 1, 'kit_key' => null, 'updated_at' => $now,
            'build' => self::build1([['id' => 'hh1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Hero text']]])]);
        $componentId = (string) $db->value('SELECT public_id FROM {components} WHERE component_id = ?', [$component]);
        $formBuild = Build::toJson(\Talea\Builder\FormRecords::definitionBuild(Build::sanitize(['v' => 1, 'children' => [['id' => 'ff1', 'type' => 'form', 'content' => [
            'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'E-mail', 'type' => 'email', 'required' => true]]]]]])[0]));
        $form = $db->insert('forms', ['name' => 'Contact form', 'build' => $formBuild, 'build_draft' => $formBuild, 'origin' => '', 'created_at' => $now, 'updated_at' => $now]);
        $formId = (string) $db->value('SELECT public_id FROM {forms} WHERE form_id = ?', [$form]);
        $db->insert('sections', ['name' => 'Saved hero', 'element' => (string) json_encode(Build::sanitize(['v' => 1, 'children' => [['id' => 'ss1', 'type' => 'section', 'children' => [['id' => 'ss2', 'type' => 'component', 'content' => ['component' => (string) $component]]]]]])[0]['children'][0]), 'updated_at' => $now]);
        $db->insert('page_templates', ['name' => 'Landing', 'build' => self::build1([['id' => 'pt1', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => '[Headline]']]]), 'created_at' => '2026-10-03 10:00:00', 'updated_at' => '2026-10-04 10:00:00']);

        // pages: a home page, a child, a translation, a password-protected one
        $build = self::build1([
            ['id' => 'b1', 'type' => 'component', 'content' => ['component' => (string) $component]],
            ['id' => 'b2', 'type' => 'booking', 'content' => ['service' => $service, 'staff_member' => $staff]],
            ['id' => 'b3', 'type' => 'form_embed', 'content' => ['form' => $formId, 'button_text' => 'Send']],
            ['id' => 'b4', 'type' => 'image', 'content' => ['src' => $photo, 'alt' => 'x']],
        ]);
        $home = $db->insert('pages', ['title' => 'Home', 'slug' => 'home', 'description' => 'Meta', 'seo_title' => 'SEO home', 'image' => $photo, 'noindex' => 0, 'text' => '<p>Home</p>',
            'visible' => 1, 'in_menu' => 1, 'sort_order' => 1, 'updated_at' => '2026-10-05 10:00:00', 'build' => $build, 'build_draft' => $build, 'valid_until' => $day, 'review_by' => $day] + $seo);
        $child = $db->insert('pages', ['title' => 'Services', 'slug' => 'services', 'description' => '', 'text' => '<p>Services</p>', 'visible' => 1, 'in_menu' => 0, 'sort_order' => 2,
            'updated_at' => '2026-10-05 10:00:00', 'parent_id' => $home, 'publish_at' => '2026-12-01 08:00:00']);
        $db->insert('pages', ['title' => 'Startseite', 'slug' => 'home', 'description' => '', 'text' => '<p>Start</p>', 'visible' => 1, 'in_menu' => 1, 'sort_order' => 1,
            'updated_at' => '2026-10-05 10:00:00', 'language' => 'de', 'translation_of' => $home]);
        $private = $db->insert('pages', ['title' => 'Members area', 'slug' => 'members-area', 'description' => '', 'text' => '<p>Private</p>', 'visible' => 1, 'in_menu' => 0, 'sort_order' => 3,
            'updated_at' => '2026-10-05 10:00:00', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT), 'head_code' => '<script>alert(1)</script>']);

        // news
        $category = $db->insert('categories', ['name' => 'Stories', 'slug' => 'stories', 'description' => 'All stories', 'weight' => 3]);
        $categoryDe = $db->insert('categories', ['name' => 'Geschichten', 'slug' => 'geschichten', 'description' => '', 'language' => 'de', 'translation_of' => $category]);
        $tag = $db->insert('tags', ['name' => 'Garden', 'slug' => 'garden', 'description' => 'About gardens', 'image' => $photo]);
        $news = $db->insert('news', ['title' => 'First story', 'slug' => 'first-story', 'intro' => '<p>Intro</p>', 'text' => '<p>Story</p>', 'image' => $photo, 'image_caption' => 'Caption',
            'image_author' => 'Author', 'category_id' => $category, 'published_at' => '2026-10-06 10:00:00', 'visible' => 1, 'keywords' => 'garden', 'seo_title' => 'SEO story',
            'seo_description' => 'SEO description', 'noindex' => 1, 'faq' => '[{"q":"Why?","a":"Because."}]', 'visit' => 7, 'edited_at' => '2026-10-07 10:00:00', 'updated_at' => '2026-10-07 10:00:00',
            'valid_until' => $day, 'review_by' => $day] + $seo);
        $db->insert('news_tags', ['news_id' => $news, 'tag_id' => $tag]);
        $db->insert('news', ['title' => 'Erste Geschichte', 'slug' => 'erste-geschichte', 'intro' => '', 'text' => '<p>Geschichte</p>', 'category_id' => $categoryDe, 'published_at' => '2026-10-06 10:00:00', 'visible' => 1,
            'language' => 'de', 'translation_of' => $news]);

        // collections: every field type, a template, items, a previous file of a document
        $fields = Collections::sanitizeFields([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'width' => 'half', 'help' => 'Help', 'placeholder' => '[Title]', 'required' => true],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'lines'], ['key' => 'body', 'label' => 'Body', 'type' => 'html'], ['key' => 'photo', 'label' => 'Photo', 'type' => 'image'],
            ['key' => 'web', 'label' => 'Web', 'type' => 'link'], ['key' => 'price', 'label' => 'Price', 'type' => 'number'], ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
            ['key' => 'starts', 'label' => 'Starts', 'type' => 'datetime'], ['key' => 'file', 'label' => 'File', 'type' => 'file'], ['key' => 'place', 'label' => 'Place', 'type' => 'location'],
            ['key' => 'size', 'label' => 'Size', 'type' => 'radio', 'options' => ['S', 'M']], ['key' => 'specs', 'label' => 'Specs', 'type' => 'parameters'],
            ['key' => 'variants', 'label' => 'Variants', 'type' => 'variants'], ['key' => 'related', 'label' => 'Related', 'type' => 'item', 'collection' => 'products'],
            ['key' => 'photos', 'label' => 'Photos', 'type' => 'gallery'],
        ]);
        $products = $db->insert('collections', ['name' => 'Products', 'slug' => 'products', 'fields' => (string) json_encode($fields), 'detail' => 1, 'admin_menu' => 0, 'hidden_redirect' => '',
            'preset' => '', 'updated_at' => $now, 'build' => self::build1([['id' => 'cb1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Products']]])]);
        $db->insert('collection_templates', ['collection_id' => $products, 'language' => '', 'build' => self::build1([['id' => 'ct1', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => '{{name}}']]]), 'updated_at' => $now]);
        $data = ['title' => 'Chair', 'notes' => "Line one\nLine two", 'body' => '<p><strong>Bold</strong> text</p>', 'photo' => $photo, 'web' => 'https://example.test/chair', 'price' => '120',
            'day' => '2026-12-24', 'starts' => '2026-12-24 18:00', 'file' => 'media/2026/11/rt-price-list.pdf', 'place' => '50.1, 14.4', 'size' => 'M', 'specs' => 'Colour: red',
            'variants' => 'Small | S1 | 100', 'related' => 'table', 'photos' => $photo . ' | A chair'];
        $item = $db->insert('collection_items', ['collection_id' => $products, 'name' => 'Chair', 'slug' => 'chair', 'data' => (string) json_encode(Collections::sanitizeData($fields, $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'seo_title' => 'SEO chair', 'description' => 'A chair', 'image' => $photo, 'noindex' => 1, 'sort_order' => 4, 'visible' => 1, 'publish_at' => '2026-12-01 08:00:00', 'valid_until' => $day, 'review_by' => $day,
            'created_at' => '2026-10-08 10:00:00', 'updated_at' => $now, 'submitted_via' => $form] + $seo);
        $db->insert('collection_items', ['collection_id' => $products, 'name' => 'Table', 'slug' => 'table', 'data' => (string) json_encode(Collections::sanitizeData($fields, ['title' => 'Table']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'sort_order' => 5, 'visible' => 0, 'created_at' => '2026-10-08 10:00:00', 'updated_at' => $now]);
        $db->insert('document_versions', ['item_id' => $item, 'file' => 'media/2026/11/rt-price-list.pdf', 'version' => '1.0', 'replaced_at' => '2026-10-09 10:00:00', 'replaced_by' => 'Editor']);

        // the rest of the site
        $db->insert('classes', ['name' => 'card', 'style' => '{"base":{"color":"primary"}}', 'css' => '', 'updated_at' => $now]);
        $db->insert('site_parts', ['type' => 'header', 'language' => '', 'variant' => '', 'name' => 'Header', 'pages' => null, 'updated_at' => $now,
            'build' => self::build1([['id' => 'hp1', 'type' => 'heading', 'tag' => 'p', 'content' => ['text' => 'Header']]])]);
        $db->insert('menus', ['location' => 'main', 'language' => '', 'items' => (string) json_encode(\Talea\Core\Menu::sanitize([['type' => 'page', 'text' => 'Our services', 'page_id' => $child], ['type' => 'link', 'text' => 'Shop', 'url' => 'https://example.test/shop']])), 'updated_at' => $now]);
        $db->insert('popups', ['name' => 'Offer', 'slug' => 'offer', 'type' => 'slide_in', 'trigger_type' => 'scroll', 'value' => 5, 'rules' => (string) json_encode(\Talea\Builder\Popups::sanitizeRules(['pages' => [$home], 'device' => 'mobile']), JSON_UNESCAPED_UNICODE), 'frequency' => 'days',
            'days' => 3, 'active' => 1, 'sort_order' => 1, 'valid_until' => $day, 'review_by' => $day, 'updated_at' => $now, 'build' => self::build1([['id' => 'pp1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Offer']]])]);
        $db->insert('redirects', ['from_path' => 'old-page', 'to_path' => '/services', 'match_type' => 'exact', 'type' => 302, 'active' => 1, 'auto_score' => null, 'created_at' => $now]);
        $db->insert('short_links', ['path' => 'flyer', 'target_type' => 'page', 'target_id' => $child, 'target_url' => null, 'utm_source' => 'flyer', 'utm_medium' => 'print', 'utm_campaign' => 'autumn',
            'utm_term' => '', 'utm_content' => '', 'label' => 'Autumn flyer', 'created_at' => '2026-10-10 10:00:00']);
        $db->insert('facts', ['fact_key' => 'founded', 'language' => '', 'label' => 'Founded', 'type' => 'text', 'value' => '1990', 'schema_prop' => '', 'source' => 'Register', 'updated_at' => $now, 'site_id' => 1]);
        $db->insert('hours_exceptions', ['date_from' => $day, 'date_to' => $day, 'closed' => 1, 'hours' => '', 'note' => 'Holiday', 'notice_days' => 5, 'created_at' => $now]);
        $db->insert('notebook', ['topic' => 'history', 'title' => 'Moved', 'text' => 'The site moved.', 'pinned' => 1, 'author' => 'Claude', 'created_at' => '2026-10-11 10:00:00', 'updated_at' => '2026-10-11 10:00:00']);

        [$manifest] = \Talea\Core\Blueprint::sanitize(json_decode((string) file_get_contents(TALEA_ROOT . '/system/blueprints/agency.json'), true));
        $db->insert('blueprints', ['bkey' => $manifest['key'], 'name' => \Talea\Core\Blueprint::text($manifest['name']), 'manifest' => (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'applied_at' => $now]);
        $db->insert('notice_log', ['item_id' => $item, 'action' => 'posted', 'at' => '2026-10-12 10:00:00', 'by' => 'Clerk', 'fields' => '{"name":"Chair"}']);

        // member login: a group restricts the private page and the news item
        $group = $db->insert('member_groups', ['name' => 'Customers', 'created_at' => $now]);
        $db->insert('content_groups', ['content_type' => 'page', 'content_id' => $private, 'group_id' => $group]);
        $db->insert('content_groups', ['content_type' => 'news', 'content_id' => $news, 'group_id' => $group]);
        $db->insert('content_groups', ['content_type' => 'item', 'content_id' => $item, 'group_id' => $group]);

        // settings: the home page, a language version, and the texts and rules of features whose content travels
        $set = fn (string $name, string $value) => $db->upsert('settings', ['name' => $name, 'value' => $value], ['name']);
        $set('home_page', (string) $home);
        $set('site_name', 'Round trip');
        $set('site_name_de', 'Rundreise');
        $set('additional_languages', 'de');
        \Talea\Core\Extensions::save(new Settings($db), array_values(array_unique([...\Talea\Core\Extensions::fromValue(''), 'members', 'bookings', 'short_links'])));
        $set('german_register', 'formal');
        $set('member_signup', 'open');
        $set('booking_lead_hours', '6');
        $set('booking_horizon_days', '90');
        $set('booking_cancel_hours', '12');
        $set('booking_reminder_hours', '48');
        $set('booking_hold_hours', '24');
        $set('url_slash', 'slash');
        $set('markdown_versions', '0');
        $set('accessibility_toolbar', '1');
        $set('ai_crawlers', 'block');
        $set('message_course_registered_subject', 'You are in: {course}');
        $set('message_course_registered_body_de', 'Sie sind angemeldet: {course}');
    }

    /** A table of an archive as comparable rows: by public id (or the natural key), in a stable order. @return array<string, mixed> */
    private static function rows(array $archive, string $table): array
    {
        $out = [];
        foreach ((array) ($archive['content'][$table] ?? []) as $i => $row) {
            foreach (self::DROPPED as $place => $reason) {
                [$t, $column] = explode('.', $place);
                if ($t === $table) {
                    unset($row[$column]);
                }
            }
            foreach (['updated_at', 'applied_at'] as $operational) {
                unset($row[$operational]); // written when the row is stored, also by the editor
            }
            $key = (string) ($row['public_id'] ?? json_encode(array_intersect_key($row, array_flip(['location', 'type', 'language', 'variant', 'name', 'fact_key', 'collection_id', 'staff_id', 'service_id', 'weekday', 'content_type', 'content_id', 'group_id', 'id', 'item_id', 'date_from', 'off_from', 'bkey']))));
            ksort($row);
            $out[$key] = $row;
        }
        ksort($out);

        return $out;
    }

    public function testTheImportWentThrough(): void
    {
        $this->assertSame('done', self::$state['phase'] ?? null, 'the import finished: ' . json_encode(self::$state['error'] ?? ''));
        $this->assertSame([], array_filter(array_map(fn (array $r): int => (int) ($r['skipped'] ?? 0), (array) self::$state['result'])), 'no row of the archive was skipped');
    }

    public function testEveryTableComesBackWithEveryValue(): void
    {
        $lost = [];
        $empty = [];
        foreach (array_keys(self::$before['content']) as $table) {
            if (!is_array(self::$before['content'][$table]) || $table === 'settings') {
                continue;
            }
            if (self::$before['content'][$table] === []) {
                $empty[] = $table;
            }
            $before = self::rows(self::$before, $table);
            $after = self::rows(self::$after, $table);
            foreach ($before as $key => $row) {
                if (!isset($after[$key])) {
                    $lost[] = "$table: row $key is missing";
                    continue;
                }
                foreach ($row as $column => $value) {
                    if (($after[$key][$column] ?? null) !== $value) {
                        $lost[] = "$table.$column of $key: " . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' became ' . json_encode($after[$key][$column] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        }
        $this->assertSame([], $empty, 'the site of the test has a row in every exported table – add one to fill()');
        $this->assertSame([], $lost, 'export → import → export keeps every row and value');
    }

    public function testTheSettingsComeBack(): void
    {
        $before = self::$before['content']['settings'];
        $after = self::$after['content']['settings'];
        unset($before['site_url'], $after['site_url']); // the new site keeps its own address
        $lost = [];
        foreach ($before as $key => $value) {
            if (($after[$key] ?? null) !== $value) {
                $lost[] = "$key: " . json_encode($value) . ' became ' . json_encode($after[$key] ?? null);
            }
        }
        $this->assertSame([], $lost, 'every exported setting is imported with its value');
        foreach (['german_register', 'member_signup', 'booking_lead_hours', 'url_slash', 'message_course_registered_subject', 'message_course_registered_body_de'] as $key) {
            $this->assertArrayHasKey($key, $before, "the setting $key travels with the site");
        }
    }

    public function testRestrictedAndProtectedContentStaysClosed(): void
    {
        $target = self::$target ?? throw new \LogicException('No target.');
        $page = $target->one("SELECT page_id, password_hash, head_code FROM {pages} WHERE slug = 'members-area'");
        $this->assertTrue(password_verify('secret', (string) ($page['password_hash'] ?? '')), 'a password-protected page keeps its password');
        $this->assertSame('', (string) $page['head_code'], 'code in the head of a page never comes in from an archive');
        foreach (['page' => 'pages', 'news' => 'news', 'item' => 'collection_items'] as $type => $table) {
            $this->assertNotNull($target->value('SELECT 1 FROM {content_groups} WHERE content_type = ?', [$type]), "a $type restricted to a member group stays restricted");
        }
        $this->assertSame('Customers', $target->value('SELECT g.name FROM {content_groups} c JOIN {member_groups} g ON g.group_id = c.group_id WHERE c.content_type = ? AND c.content_id = ?', ['page', (int) $page['page_id']]));
    }

    public function testEveryTableAndColumnIsExportedOrNamedAsLeftBehind(): void
    {
        $db = $this->db();
        $tables = array_map(fn (string $name): string => substr($name, strlen($db->prefix)), $db->dialect()->dumpTables($db->pdo(), $db->prefix));
        $exported = array_keys(array_filter(self::$before['content'], 'is_array'));
        $unknown = [];
        foreach ($tables as $table) {
            if (!in_array($table, $exported, true) && !isset(self::NOT_EXPORTED[$table])) {
                $unknown[] = $table;
            }
        }
        $this->assertSame([], $unknown, 'every table is exported or named in NOT_EXPORTED with its reason');

        $missing = [];
        foreach ($exported as $table) {
            if ($table === 'settings' || !in_array($table, $tables, true) || self::$before['content'][$table] === []) {
                continue;
            }
            $row = self::$before['content'][$table][0];
            $left = self::NOT_EXPORTED_COLUMNS[$table] ?? [];
            foreach ($db->columns($table) as $column) {
                $key = Db::PRIMARY_KEYS[$table] ?? null;
                if ($column === $key || in_array($column, ['id', 'public_id'], true) || array_key_exists($column, $row) || isset($left[$column]) || isset(self::NOT_EXPORTED_COLUMNS['*'][$column]) || isset(self::NOT_EXPORTED_COLUMNS[''][$column])) {
                    continue;
                }
                $missing[] = "$table.$column";
            }
        }
        $this->assertSame([], $missing, 'every column of an exported table is in the archive or named in NOT_EXPORTED_COLUMNS');

        $settings = array_merge(SiteExport::SETTINGS, ...array_values(self::SETTINGS_NOT_EXPORTED));
        $this->assertSame([], array_values(array_diff(array_keys(Settings::DEFAULTS), $settings)), 'every setting is exported or named in SETTINGS_NOT_EXPORTED');
    }
}
