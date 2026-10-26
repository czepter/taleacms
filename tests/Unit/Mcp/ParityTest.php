<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Talea\Core\Search;

final class ParityTest extends TestCase
{
    public function testEveryAdminActionIsAReadAnMcpToolOrAdminOnly(): void
    {
        $this->assertSame('google / cpc / jaro', \Talea\Front\Forms::campaignText('utm_source=google&utm_medium=cpc&utm_campaign=jaro'), 'Enquiry: campaign for a human');
        // Parity guard: every admin action is a read, has an MCP tool, or is admin only on purpose (with the reason). A new action
        // that is none of these fails the test – decide whether Claude can do it too (MCP is the main way to work with a site).
        $readOnly = 'read';
        $builderParity = [
            'build_ai_section' => 'admin: AI helper of the builder – Claude writes the content itself', 'build_ai_text' => 'admin: AI helper of the builder – Claude writes the content itself',
            'build_ask' => 'admin: the Ask box edits the draft with the operations of edit_build under the same guardrails (#31) – Claude uses edit_build itself',
            'build_class' => 'save_classes', 'build_delete_section' => 'delete_section', 'build_discard' => 'discard_draft', 'build_publish' => 'publish_build',
            'build_restore' => 'restore_build_version', 'build_save' => 'save_build', 'build_save_section' => 'save_section', 'build_section' => 'insert_section',
            'build_share' => 'preview_link', 'build_versions' => $readOnly, 'build_compare' => 'compare_build_version', 'builder' => $readOnly, 'build_comment_resolve' => 'resolve_draft_comment',
            'build_package' => $readOnly, 'build_paste' => 'admin: paste from the system clipboard of another Talea site – Claude inserts elements with save_build or edit_build and brings classes with save_classes',
        ];
        $settingsParity = ['list' => $readOnly, 'save' => 'update_settings', 'download_backup' => $readOnly, 'backup' => 'admin: backups', 'restore_backup' => 'admin: backups',
            'delete_backup' => 'admin: backups', 'media_backup' => 'admin: backups', 'delete_log' => 'admin: error log',
            'test_mail' => 'admin: mail server settings', 'test_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
            'retry_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)', 'new_webhook_secret' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
            'hours_add' => 'save_hours_exception', 'hours_delete' => 'delete_hours_exception', 'hours_sign' => $readOnly,
            'hours_apply' => 'save_hours_exception', 'hours_discard' => 'delete_hours_exception', // a proposal from a drafts-only connection (3.2)
            'fleet_pair' => 'admin: which console a site reports to is a security decision (2.9)', 'fleet_send' => 'admin: the site reports every hour on its own',
            'fleet_unpair' => 'admin: which console a site reports to is a security decision (2.9)',
            'fleet_kit' => 'admin: receiving the console\'s design kit is an opt-in of the site (2.16); site_info reports the version that arrived',
            'report_preview' => $readOnly, 'report_send' => 'admin: the monthly report goes to e-mail addresses that stay out of MCP (2.9) – Claude reads the archived months with get_monthly_report',
            'report_view' => 'get_monthly_report', 'report_download' => 'get_monthly_report',
            'cookie_scan' => 'admin: the cookie scan runs daily on its own (2.14); Claude reads the table in processing_record', 'processing_record' => 'processing_record',
            'accessibility_statement' => 'admin: the draft page is the administrator’s step (2.14); Claude reads the text with accessibility_statement'];
        $parity = [
            'pages' => $builderParity + ['list' => $readOnly, 'website' => $readOnly, 'settings_save' => 'update_page', 'seo_bulk_save' => 'update_page', 'seo_add_type' => 'edit_build', 'seo_keyword_add' => 'update_page', 'seo_check' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'export' => $readOnly, 'save' => 'update_page', 'save_text' => 'update_page',
                'delete' => 'trash_page', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
                'duplicate' => 'admin: a copy of a page – Claude creates the page and saves the build', 'import' => 'admin: upload of a page export file',
                'build_text' => 'admin: back from the builder to a text page', 'restore_version' => 'admin: text revisions of a text page', 'compare' => $readOnly,
                'translations' => 'translation_status', 'bulk' => 'update_page'],
            'news' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'compare' => $readOnly, 'versions' => $readOnly, 'search_json' => $readOnly,
                'save' => 'update_news', 'save_text' => 'update_news', 'delete' => 'trash_news', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
                'draft' => 'admin: autosave of the editor', 'duplicate' => 'admin: a copy of a news item – Claude creates a new one', 'links' => 'admin: link check runs on its own',
                'assistant' => 'admin: AI helper – Claude writes the text itself', 'translate' => 'admin: AI translation – Claude translates and uses create_news', 'bulk' => 'update_news',
                'social_save' => 'update_social_draft', 'social_posted' => 'admin: the person who posted it marks it', 'social_suggest' => 'admin: AI helper – Claude polishes the drafts itself with update_social_draft'],
            'collections' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'preset' => 'create_collection', 'edit' => $readOnly, 'items' => $readOnly, 'item' => $readOnly,
                'save' => 'update_collection', 'delete' => 'delete_collection', 'save_item' => 'save_collection_item', 'delete_item' => 'delete_collection_item',
                'restore_item' => 'restore_from_trash', 'delete_item_permanently' => 'admin: the trash empties itself after 30 days', 'duplicate_item' => 'admin: a copy of an item – Claude saves a new one',
                'restore_item_version' => 'restore_item_version', 'signature' => 'get_email_signature', 'notice_log' => 'list_notice_log', 'bulk_items' => 'save_collection_item'],
            // 2.15: requests to Claude – staff write them in the admin (not over MCP: a request is what a person asks Claude), Claude reads and answers them
            'requests' => ['list' => 'list_requests', 'new' => $readOnly, 'save' => 'admin: a request is written by a person for Claude – Claude reads it with list_requests', 'detail' => 'list_requests',
                'reply' => 'admin: the requester answers Claude in the request; Claude answers with update_request', 'status' => 'update_request'],
            'enquiries' => ['list' => $readOnly, 'detail' => $readOnly, 'csv' => $readOnly, 'attachment' => $readOnly, 'note' => 'update_enquiry', 'status' => 'update_enquiry', 'triage' => 'update_enquiry', 'testimonial' => 'request_testimonial', 'personal' => 'erase_personal_data',
                'bulk' => 'update_enquiry', 'delete' => 'delete_enquiry', 'settings' => 'admin: how long enquiries are kept', 'anonymise' => 'admin: blanking a person from an enquiry is the owner’s decision about personal data (2.14)'],
            // the forms of the site and their answers: read from the builds and the enquiries, edited in the builder
            'forms' => ['list' => $readOnly, 'detail' => $readOnly],
            // 3.0: online booking – bookings are personal data (the Bookings section), the set-up is the administrator's
            'bookings' => ['list' => $readOnly, 'detail' => $readOnly, 'new' => $readOnly, 'status' => 'cancel_booking', 'confirm' => 'confirm_booking', 'decline' => 'decline_booking', 'propose' => 'propose_booking_times', 'anonymise' => 'admin: blanking a person from a booking is the owner’s decision about personal data',
                'create' => 'admin: a booking taken by phone is entered by the person who took the call – customers’ personal data never come from Claude', 'services' => $readOnly, 'service_save' => 'save_booking_service',
                'service_delete' => 'admin: removing a service is the administrator’s decision; save_booking_service switches it off', 'staff' => $readOnly, 'staff_edit' => $readOnly, 'staff_save' => 'save_booking_staff',
                'staff_delete' => 'admin: removing a person is the administrator’s decision; save_booking_staff switches them off', 'off_save' => 'save_booking_staff', 'off_delete' => 'save_booking_staff', 'settings' => 'update_settings'],
            'subscribers' => ['list' => $readOnly, 'csv' => $readOnly, 'delete' => 'admin: subscribers’ addresses stay out of MCP', 'sync' => 'admin: mailing service keys', 'retry' => 'admin: mailing service keys'],
            'newsletters' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'preview' => $readOnly, 'save' => 'draft_newsletter', 'test' => 'send_test_newsletter',
                'send' => 'send_newsletter', 'unschedule' => 'send_newsletter', 'delete' => 'delete_newsletter'],
            'categories' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'update_category', 'delete' => 'delete_category'],
            'tags' => ['list' => $readOnly, 'save' => 'admin: tags are set with news items (update_news)', 'delete' => 'admin: tags are set with news items (update_news)'],
            'media' => ['list' => $readOnly, 'listing' => $readOnly, 'upload' => 'upload_file', 'save' => 'update_media', 'save_caption' => 'update_media', 'bulk' => 'delete_media',
                'replace' => 'admin: a new file behind the same address', 'folder' => 'admin: media folders', 'folder_delete' => 'admin: media folders',
                'cleanup' => 'list_media_without_alt', 'save_alts' => 'update_media', 'shrink' => 'admin: re-encoding a stored image in place (2.14) – Claude uploads a smaller file instead',
                'approve' => 'admin: a file a drafts-only connection uploaded goes public on a person\'s approval (#150) – a connection with full access never waits, and publish_build makes the files a published draft uses public'],
            'appearance' => ['list' => $readOnly, 'preview' => $readOnly, 'tokens' => $readOnly, 'save' => 'update_design_system', 'tokens_import' => 'admin: upload of a design tokens file',
                'looks' => 'list_looks', 'apply_look' => 'apply_look', 'publish_look' => 'publish_look', 'discard_look' => 'discard_look', 'restore_look' => 'restore_look_version', 'preview_site' => 'preview_link'],
            'parts' => $builderParity + ['list' => $readOnly, 'variant' => $readOnly, 'save_variant' => 'save_part_variant', 'template' => 'save_part_variant',
                'templates' => $readOnly, 'apply_template' => 'apply_part_template'],
            'menu' => ['list' => $readOnly, 'save' => 'save_menu', 'automatic' => 'save_menu'],
            'components' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'save_component', 'delete' => 'delete_component', 'from_element' => 'save_component'],
            'popups' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'create' => 'save_popup', 'save' => 'save_popup', 'toggle' => 'save_popup',
                'delete' => 'delete_popup', 'reset' => 'admin: resetting the counters'],
            'users' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions', 'password_link' => 'admin: accounts and permissions',
                'reactivate' => 'admin: accounts and permissions', 'revoke_connection' => 'admin: accounts and permissions'],
            'roles' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions'],
            'experiments' => ['list' => 'list_experiments', 'new' => $readOnly, 'create' => 'create_experiment', 'show' => 'get_experiment_result', 'start' => 'update_experiment', 'stop' => 'update_experiment',
                'promote' => 'promote_experiment_winner', 'undo' => 'update_experiment', 'auto' => 'update_experiment', 'delete' => 'delete_experiment'],
            // HF-33: member login – addresses are personal data and stay out of MCP; Claude manages the groups and the gating
            'members' => ['list' => 'list_member_groups', 'invite' => 'admin: an invitation e-mails a person and member addresses are personal data – they stay out of MCP', 'save_member' => 'admin: members\' addresses and names are personal data and stay out of MCP',
                'remove' => 'admin: removing a person and ending their access is the owner\'s decision about personal data', 'group_save' => 'save_member_group', 'group_delete' => 'delete_member_group',
                'settings' => 'admin: who may sign up (invited or open) is a security decision of the owner'],
            'stats' => ['list' => 'get_stats'], 'search_performance' => ['list' => 'get_search_performance'], 'changelog' => ['list' => 'list_changes', 'sessions' => 'list_agent_sessions', 'undo' => 'undo_agent_session'], 'audit' => ['list' => 'site_audit'],
            'seo_titles' => ['list' => $readOnly, 'save' => 'update_settings', 'reset' => 'admin: resets a section in one step'],
            'seo' => ['list' => $readOnly, 'feature' => 'admin: switching features changes what the site offers; MCP uses update_settings'],
            'addons' => ['list' => $readOnly, 'toggle' => 'admin: running code from another developer is the administrator’s decision (3.0)', 'uninstall' => 'admin: removing an add-on and its data is the administrator’s decision (API 2)', 'page' => 'admin: pages add-ons add to the administration'],
            'redirects' => ['list' => $readOnly, 'save' => 'save_redirect', 'delete' => 'save_redirect', 'toggle' => 'save_redirect', 'duplicate' => 'save_redirect', 'test' => $readOnly,
                'settings' => 'update_settings'],
            'missing_pages' => ['list' => $readOnly, 'redirect' => 'save_redirect', 'clear' => 'admin: clearing the list of 404 addresses', 'ignore' => 'ignore_not_found', 'ignore_all' => 'ignore_not_found'],
            'transfer' => ['list' => $readOnly, 'preview' => $readOnly, 'download' => $readOnly, 'export' => $readOnly, 'upload' => 'admin: WordPress import', 'select' => 'admin: WordPress import',
                'run' => 'admin: WordPress import', 'progress' => 'admin: WordPress import', 'images' => 'admin: WordPress import', 'delete_file' => 'admin: WordPress import', 'delete_export' => 'admin: site export',
                'talea' => 'admin: moving a whole site into a new installation', 'talea_select' => 'admin: moving a whole site into a new installation',
                'talea_run' => 'admin: moving a whole site into a new installation', 'talea_delete' => 'admin: moving a whole site into a new installation',
                'web_start' => 'import_website', 'web_progress' => 'import_website', 'web_run' => 'import_website', 'web_delete' => 'admin: removing the record of an import',
                'report_start' => 'migration_report', 'report' => 'migration_report', 'report_delete' => 'admin: removing a saved report',
                'source_upload' => 'admin: structured import (3.0) – the export file comes through the admin, not over MCP', 'source_select' => 'admin: structured import (3.0)',
                'source_preview' => $readOnly, 'source_run' => 'admin: structured import (3.0)', 'source_progress' => 'admin: structured import (3.0)',
                'source_images' => 'admin: structured import (3.0)', 'source_delete' => 'admin: structured import (3.0)',
                'source_fetch' => 'admin: structured import (3.0) – the API token is typed in the admin, kept in the session only, never over MCP'],
            'settings' => $settingsParity, 'extensions' => $settingsParity, 'business' => $settingsParity, 'status' => $settingsParity,
            'claude_settings' => $settingsParity + ['acknowledge_instructions' => 'admin: a person confirms having checked changed instructions for Claude (#149) – a connection must not clear the notice about a change it may have made itself'],
            'assistant_settings' => $settingsParity, 'newsletter_settings' => $settingsParity,
            'facts' => ['list' => 'list_facts', 'edit' => $readOnly, 'save' => 'save_fact', 'delete' => 'delete_fact', 'claims' => 'find_claims'],
            'notebook' => ['list' => 'read_notebook', 'edit' => $readOnly, 'save' => 'write_notebook', 'pin' => 'write_notebook', 'delete' => 'delete_notebook_entry'],
            // 2.17: scheduled runs – what a routine in Claude does on the site and when is the administrator's decision; Claude only gets the due runs and reports them
            'schedules' => ['list' => $readOnly, 'edit' => $readOnly, 'history' => $readOnly, 'save' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17) – Claude gets the due runs with get_due_agent_runs and reports them with report_agent_run',
                'delete' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17)', 'toggle' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17)'],
            'connectors' => ['list' => 'list_connectors', 'save' => 'admin: credentials of outside services never go through Claude', 'connect' => 'admin: an OAuth sign-in needs the administrator in the browser',
                'callback' => 'admin: an OAuth sign-in needs the administrator in the browser', 'disconnect' => 'admin: credentials of outside services never go through Claude',
                'properties' => 'admin: picking the Search Console property belongs to the connection, next to its credentials', 'property' => 'admin: picking the Search Console property belongs to the connection, next to its credentials',
                'gbp_locations' => 'admin: which Business Profile location the site syncs is the administrator’s choice (2.13)', 'gbp_sync' => 'admin: the daily job does it by itself; the button is for the administrator checking the connection',
                'sheet' => 'admin: the sheet of enquiries is created with the administrator\'s Google sign-in (2.13); Claude sees the status in list_connectors'],
            'blueprints' => ['list' => 'get_blueprint', 'apply' => 'apply_blueprint', 'remove' => 'remove_blueprint', 'answers' => 'save_fact', 'export' => 'export_blueprint'],
            'wizard' => ['list' => $readOnly, 'features' => $readOnly, 'start' => $readOnly, 'looks' => 'list_looks', 'look' => $readOnly, 'ai' => $readOnly,
                'use' => 'admin: the onboarding uses a look with the owner\'s palette and fonts, switches on the features and adds starter pages (#46) – Claude uses apply_look (palette, fonts), update_settings (extensions) and create_page',
                'plan' => 'admin: the wizard asks the owner questions and plans with the owner\'s own AI key (#31) – Claude builds a site with the *_build tools, apply_look and apply_blueprint itself',
                'apply' => 'admin: the wizard makes the drafts the owner reviewed (#31) – Claude uses create_page, save_build, apply_look and apply_blueprint'],
            'fleet' => ['list' => 'list_sites', 'detail' => 'get_site', 'pairing_key' => 'admin: pairing a site is a security decision (2.9)',
                'check' => 'admin: the console checks the sites every 5 minutes on its own', 'remove' => 'admin: removing a site from the console',
                'kit' => $readOnly, 'kit_publish' => 'admin: publishing a design kit to a whole fleet is a person\'s decision (2.16); list_sites shows the versions'],
        ];
        $missingParity = [];
        foreach (\Talea\Admin\Kernel::MODULES as $moduleClass) {
            foreach ((new \ReflectionClass($moduleClass))->getMethods() as $method) {
                if (preg_match('/^action[A-Z]/', $method->name)) {
                    $action = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method->name, 6)));
                    $covered = $parity[$moduleClass::IDENT][$action] ?? null;
                    if ($covered === null || ($covered !== $readOnly && !str_starts_with($covered, 'admin: ') && !in_array($covered, array_keys(\Talea\Mcp\Catalog::TOOLS), true))) {
                        $missingParity[] = $moduleClass::IDENT . '.' . $action . ($covered !== null ? ' → unknown tool ' . $covered : '');
                    }
                }
            }
        }
        $this->assertSame([], $missingParity, 'Parity: every admin action is a read, an MCP tool or admin only on purpose');
    }
}
