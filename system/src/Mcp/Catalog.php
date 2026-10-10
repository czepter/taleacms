<?php

declare(strict_types=1);

namespace Talea\Mcp;

/**
 * Every MCP tool once (2.1): its name => [access, the extension it needs ('' = none)].
 *
 * Access is what an MCP client should confirm with the user: "read" changes nothing, "draft" only saves drafts (a build,
 * a hidden page, a draft look, a news draft – publishing it needs the publishing permission – and since 3.2 a hidden
 * collection item, a proposed exception to the opening hours, an enquiry triage and a notebook note; the tool itself
 * refuses a drafts-only connection anything more, Auth::draftsOnly), "write" changes the site,
 * and "destructive" removes or overwrites something the user may want back, or cannot be taken back (sending). A
 * connection limited to drafts (2.2) may use "read" and "draft" tools and never publishes; a read-only one only "read". The
 * annotations, the change log, the extension gate and tools/list all come from here. The tool itself is the method
 * Tools::tool<Name> (toolSiteInfo for site_info) and its definition (description, parameters and their types) is in
 * Tools::definitions(); tools/unit-tests.php checks that all three agree.
 */
final class Catalog
{
    /** @var array<string, array{0: 'read'|'draft'|'write'|'destructive', 1: string}> */
    public const array TOOLS = [
        // Site and pages
        'site_info' => ['read', ''],
        'list_pages' => ['read', ''],
        'get_page' => ['read', ''],
        'create_page' => ['draft', ''],
        'update_page' => ['draft', ''],
        'trash_page' => ['destructive', ''],
        'get_menu' => ['read', ''],
        'save_menu' => ['draft', ''],
        'list_trash' => ['read', ''],
        'restore_from_trash' => ['write', ''],
        // Builder
        'builder_schema' => ['read', ''],
        'get_build' => ['read', ''],
        'edit_build' => ['draft', ''],
        'build_from_html' => ['draft', ''],
        'save_build' => ['draft', ''],
        'insert_section' => ['draft', ''],
        'publish_build' => ['write', ''],
        'list_build_versions' => ['read', ''],
        'restore_build_version' => ['destructive', ''],
        'discard_draft' => ['destructive', ''],
        'save_section' => ['draft', ''],
        'delete_section' => ['destructive', ''],
        'list_components' => ['read', ''],
        'save_component' => ['write', ''],
        'delete_component' => ['destructive', ''],
        'list_site_parts' => ['read', ''],
        'save_part_variant' => ['write', ''],
        'apply_part_template' => ['draft', ''],
        'preview_link' => ['read', ''],
        // Comments on drafts (2.15)
        'list_draft_comments' => ['read', ''],
        'resolve_draft_comment' => ['write', ''],
        // Look
        'list_classes' => ['read', ''],
        'save_classes' => ['draft', ''],
        'update_design_system' => ['draft', ''],
        'publish_look' => ['destructive', ''],
        'discard_look' => ['destructive', ''],
        'list_look_versions' => ['read', ''],
        'restore_look_version' => ['draft', ''],
        // Collections
        'list_collections' => ['read', ''],
        'create_collection' => ['write', ''],
        'update_collection' => ['write', ''],
        'delete_collection' => ['destructive', ''],
        'list_collection_items' => ['read', ''],
        // 3.2: a drafts-only connection saves hidden items only (Handlers\CollectionTools)
        'save_collection_item' => ['draft', ''],
        'delete_collection_item' => ['destructive', ''],
        'list_item_versions' => ['read', ''],
        'restore_item_version' => ['write', ''],
        'get_email_signature' => ['read', ''],
        // News
        'list_news' => ['read', 'news'],
        'get_news' => ['read', 'news'],
        'create_news' => ['draft', 'news'],
        'update_news' => ['draft', 'news'],
        'trash_news' => ['destructive', 'news'],
        'list_categories' => ['read', 'news'],
        'create_category' => ['write', 'news'],
        'update_category' => ['write', 'news'],
        'delete_category' => ['destructive', 'news'],
        // Media
        'list_media' => ['read', ''],
        'upload_file' => ['draft', ''],
        'import_website' => ['write', ''],
        'update_media' => ['write', ''],
        'delete_media' => ['destructive', ''],
        'list_media_without_alt' => ['read', ''],
        // Enquiries and pop-ups
        'list_enquiries' => ['read', ''],
        // 3.2: a drafts-only connection saves only the triage – a suggestion a person's sorting overrides
        'update_enquiry' => ['draft', 'enquiries'],
        'delete_enquiry' => ['destructive', 'enquiries'],
        'find_personal_data' => ['read', 'enquiries'],
        'erase_personal_data' => ['destructive', 'enquiries'],
        'import_enquiries' => ['write', 'enquiries'],
        'list_popups' => ['read', ''],
        'save_popup' => ['write', ''],
        'delete_popup' => ['destructive', ''],
        // Settings, redirects and audit
        'update_settings' => ['write', ''],
        'list_redirects' => ['read', ''],
        'save_redirect' => ['write', ''],
        'ignore_not_found' => ['write', 'redirects'],
        'site_audit' => ['read', ''],
        'list_broken_links' => ['read', ''],
        'suggest_internal_links' => ['read', ''],
        'list_agent_sessions' => ['read', ''],
        'undo_agent_session' => ['destructive', ''],
        'list_changes' => ['read', ''],
        'get_stats' => ['read', ''],
        // Moving a site (2.7)
        'migration_report' => ['read', ''],
        // A site that runs itself (2.8)
        'get_health' => ['read', ''],
        'list_events' => ['read', ''],
        'list_facts' => ['read', ''],
        'save_fact' => ['write', ''],
        'delete_fact' => ['destructive', ''],
        'find_claims' => ['read', ''],
        'list_hours' => ['read', ''],
        'list_collection_presets' => ['read', ''],
        'triage_enquiries' => ['read', 'enquiries'],
        'request_testimonial' => ['write', 'enquiries'],
        'get_blueprint' => ['read', ''],
        'list_connectors' => ['read', ''],
        'processing_record' => ['read', ''],
        'accessibility_statement' => ['read', ''],
        'get_social_drafts' => ['read', 'news'],
        // Agent notebook (2.15)
        'read_notebook' => ['read', ''],
        'write_notebook' => ['draft', ''], // 3.2: internal notes, never shown on the site
        'delete_notebook_entry' => ['destructive', ''],
        'update_social_draft' => ['write', 'news'],
        'apply_blueprint' => ['write', ''],
        'remove_blueprint' => ['destructive', ''],
        'export_blueprint' => ['read', ''],
        'list_notice_log' => ['read', ''],
        // 3.2: from a drafts-only connection a PROPOSED exception the site ignores until a person applies it
        'save_hours_exception' => ['draft', ''],
        'delete_hours_exception' => ['destructive', ''],
        // Online booking (3.0)
        'list_bookings' => ['read', ''],
        'booking_availability' => ['read', ''],
        'save_booking_service' => ['write', ''],
        'save_booking_staff' => ['write', ''],
        'cancel_booking' => ['destructive', ''],
        'confirm_booking' => ['write', ''],
        'decline_booking' => ['destructive', ''],
        'propose_booking_times' => ['write', ''],
        'list_sites' => ['read', 'fleet'],
        'get_site' => ['read', 'fleet'],
        // Requests to Claude (2.15): update_request changes nothing on the site – a note and a status on a request, whose
        // work is done as drafts – so a drafts-only connection may answer the requests it works on
        'list_requests' => ['read', ''],
        // Waiting for you (3.2): drafts and proposals that wait for a person
        'list_pending_review' => ['read', ''],
        'update_request' => ['draft', ''],
        // Scheduled runs (2.17): a routine in Claude asks what is due and reports what it did as drafts – report_agent_run
        // only records the run, so a drafts-only connection (the one the runs are meant for) may call both
        'get_due_agent_runs' => ['read', ''],
        'report_agent_run' => ['draft', ''],
        // Content hygiene (2.14)
        'translation_status' => ['read', ''],
        // Newsletter
        'list_newsletters' => ['read', 'newsletter_signup'],
        'draft_newsletter' => ['draft', 'newsletter_signup'],
        'send_test_newsletter' => ['write', 'newsletter_signup'],
        'send_newsletter' => ['destructive', 'newsletter_signup'],
        'delete_newsletter' => ['destructive', 'newsletter_signup'],
    ];

    /** Is this a tool of the system or of an add-on (3.0)? */
    public static function exists(string $name): bool
    {
        return isset(self::TOOLS[$name]) || \Talea\Extension\Registry::get()->tool($name) !== null;
    }

    public static function access(string $name): string
    {
        $addon = \Talea\Extension\Registry::get()->tool($name);
        if ($addon !== null) {
            return $addon['access'];
        }

        return self::TOOLS[$name][0] ?? 'read';
    }

    public static function extension(string $name): string
    {
        return self::TOOLS[$name][1] ?? '';
    }

    /** Connection access levels (2.2) => the tool access they allow. */
    public const array CONNECTION_ACCESS = ['full' => ['read', 'draft', 'write', 'destructive'], 'drafts' => ['read', 'draft'], 'read' => ['read']];

    /** May a connection with this access run the tool? */
    public static function allows(string $connectionAccess, string $name): bool
    {
        return in_array(self::access($name), self::CONNECTION_ACCESS[$connectionAccess] ?? self::CONNECTION_ACCESS['read'], true);
    }

    /** The method of Tools that runs the tool: site_info => toolSiteInfo. */
    public static function method(string $english): string
    {
        return 'tool' . str_replace('_', '', ucwords($english, '_'));
    }
}
