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
 * Tools::definitions(); tests/Unit/Mcp/CatalogTest.php checks that all three agree.
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
        'list_schema_types' => ['read', ''],
        'get_build' => ['read', ''],
        'try_form' => ['read', ''],
        'edit_build' => ['draft', ''],
        'build_from_html' => ['draft', ''],
        'save_build' => ['draft', ''],
        'insert_section' => ['draft', ''],
        'publish_build' => ['write', ''],
        'list_build_versions' => ['read', ''],
        'compare_build_version' => ['read', ''],
        'restore_build_version' => ['destructive', ''],
        'discard_draft' => ['destructive', ''],
        'save_section' => ['draft', ''],
        'delete_section' => ['destructive', ''],
        'list_components' => ['read', ''],
        'save_component' => ['write', ''],
        'delete_component' => ['destructive', ''],
        'list_forms' => ['read', 'enquiries'],
        'create_form' => ['draft', 'enquiries'],
        'delete_form' => ['destructive', 'enquiries'],
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
        'list_looks' => ['read', ''],
        'apply_look' => ['draft', ''],
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
        'upload_files' => ['draft', ''],
        'request_upload' => ['draft', ''],
        'import_website' => ['write', ''],
        'update_media' => ['write', ''],
        'delete_media' => ['destructive', ''],
        'replace_media' => ['destructive', ''],
        'list_media_folders' => ['read', ''],
        'save_media_folder' => ['write', ''],
        'delete_media_folder' => ['destructive', ''],
        'move_media_folder' => ['write', ''],
        'move_media' => ['write', ''],
        'list_media_without_alt' => ['read', ''],
        // Enquiries and pop-ups
        'list_enquiries' => ['read', ''],
        // 3.2: a drafts-only connection saves only the triage – a suggestion a person's sorting overrides
        'update_enquiry' => ['draft', 'enquiries'],
        'delete_enquiry' => ['destructive', 'enquiries'],
        'find_personal_data' => ['read', 'enquiries'],
        'erase_personal_data' => ['destructive', 'enquiries'],
        'import_enquiries' => ['write', 'enquiries'],
        // course registrations (the Enquiries right and the Collections section, checked in the handlers)
        'list_registrations' => ['read', 'enquiries'],
        'update_registration' => ['write', 'enquiries'],
        'cancel_course' => ['destructive', 'enquiries'],
        'list_popups' => ['read', ''],
        'save_popup' => ['write', ''],
        'delete_popup' => ['destructive', ''],
        // A/B tests (Builder\Experiments): promoting the winner changes the page, so it is "destructive" – only on the user's explicit request
        'list_experiments' => ['read', 'stats'],
        'create_experiment' => ['write', 'stats'],
        'get_experiment_result' => ['read', 'stats'],
        'update_experiment' => ['write', 'stats'],
        'promote_experiment_winner' => ['destructive', 'stats'],
        'delete_experiment' => ['destructive', 'stats'],
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
        'get_search_performance' => ['read', ''],
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
        // Member login (HF-33): groups and which content they may read; member addresses and invitations stay in the administration
        'list_member_groups' => ['read', 'members'],
        'save_member_group' => ['write', 'members'],
        'delete_member_group' => ['destructive', 'members'],
        'set_content_groups' => ['write', 'members'],
        'update_social_draft' => ['write', 'news'],
        'apply_blueprint' => ['write', ''],
        'remove_blueprint' => ['destructive', ''],
        'export_blueprint' => ['read', ''],
        'list_notice_log' => ['read', 'notice_board'],
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
        // The owner's sites (multisite, only with TALEA_MULTISITE and for administrators): Handlers\SiteTools
        'list_owner_sites' => ['read', ''],
        'create_site' => ['write', ''],
        'update_site' => ['write', ''],
        'set_look_parent' => ['write', ''],
        'transfer_site' => ['destructive', ''],
        'trash_site' => ['destructive', ''],
        'restore_site' => ['write', ''],
        'add_site_domain' => ['write', ''],
        'check_site_domain' => ['write', ''],
        'update_site_domain' => ['write', ''],
        'remove_site_domain' => ['destructive', ''],
        // Requests to Claude (2.15): update_request changes nothing on the site – a note and a status on a request, whose
        // work is done as drafts – so a drafts-only connection may answer the requests it works on
        'list_requests' => ['read', ''],
        // Waiting for you (3.2): drafts and proposals that wait for a person
        'list_pending_review' => ['read', ''],
        // The archive of the monthly reports (#161): administrators only (Handlers\ReportTools)
        'list_monthly_reports' => ['read', ''],
        'get_monthly_report' => ['read', ''],
        'update_request' => ['draft', ''],
        // Scheduled runs (2.17): a routine in Claude asks what is due and reports what it did as drafts – report_agent_run
        // only records the run, so a drafts-only connection (the one the runs are meant for) may call both; handing a run out
        // records it as running, so get_due_agent_runs is a draft too – a read-only connection must not start runs
        'get_due_agent_runs' => ['draft', ''],
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

    /**
     * Tools that reach outside the site (an upload from an address, an import, the migration report that fetches the old
     * site) – the MCP openWorldHint, and a reason for run_destructive_tool.
     */
    public const array OPEN_WORLD = ['upload_file', 'upload_files', 'replace_media', 'import_website', 'migration_report', 'check_site_domain'];

    /**
     * Writes that send something to a person (an e-mail to a customer or a test newsletter): the runner treats them like a
     * destructive tool – nothing can take a sent e-mail back – although their access is "write" (update_request: draft, but status done e-mails the requester).
     */
    public const array SENDS = ['send_test_newsletter', 'request_testimonial', 'confirm_booking', 'propose_booking_times', 'update_request', 'update_registration', 'cancel_course'];

    /**
     * The tools of the owner's sites: listed and callable only with TALEA_MULTISITE (Core\Sites::enabled()), like the tools of an
     * extension that is off. They work on the registry of sites, so they take no `site` argument.
     */
    public const array SITE_TOOLS = ['list_owner_sites', 'create_site', 'update_site', 'set_look_parent', 'transfer_site', 'trash_site', 'restore_site', 'add_site_domain', 'check_site_domain', 'update_site_domain', 'remove_site_domain'];

    /** How a connection lists its tools: "all" (as before) or "essentials" (ESSENTIALS, find_tools and the runners it needs). */
    public const array TOOL_SETS = ['all', 'essentials'];

    /**
     * The core tools an essentials connection lists: pages, the builder, the menu and the look, collections, media,
     * requests and enquiries, facts, the audit, the change log and undo_agent_session (the safety net of a Claude session).
     * tools/list of these plus the meta tools stays far below the list of all tools (tests/Unit/Mcp/DiscoveryTest.php); every
     * other tool is one runner away (Discovery::runner).
     */
    public const array ESSENTIALS = ['site_info', 'list_pages', 'get_page', 'create_page', 'update_page', 'get_menu', 'save_menu', 'builder_schema', 'get_build',
        'edit_build', 'build_from_html', 'save_build', 'insert_section', 'publish_build', 'preview_link', 'list_classes', 'save_classes', 'list_collections',
        'list_collection_items', 'save_collection_item', 'list_media', 'upload_file', 'update_media', 'list_requests', 'update_request', 'list_enquiries',
        'list_facts', 'save_fact', 'update_design_system', 'publish_look', 'site_audit', 'list_changes', 'undo_agent_session', 'read_notebook'];

    /**
     * The meta tools: name => [access, title]. They are listed for an essentials connection and callable on any; they are not
     * site tools (no Tools method) – Mcp\Server runs them itself. The runners are annotated so that the annotation holds for
     * everything a runner takes (Discovery::runner): run_read_tool only reads inside the site, run_tool saves drafts or changes
     * the site, run_destructive_tool takes whatever removes, sends or reaches outside.
     *
     * @var array<string, array{0: 'read'|'draft'|'destructive', 1: string}>
     */
    public const array META = [
        'find_tools' => ['read', 'Find more tools'],
        'run_read_tool' => ['read', 'Run a read-only tool by name'],
        'run_tool' => ['draft', 'Run a tool that saves or changes'],
        'run_destructive_tool' => ['destructive', 'Run a destructive or outside tool'],
    ];

    /** A known tool set; anything else (an old row) is "all". */
    public static function toolSet(string $set): string
    {
        return in_array($set, self::TOOL_SETS, true) ? $set : 'all';
    }

    /** Does the tool reach outside the site? */
    public static function isOpenWorld(string $name): bool
    {
        return in_array($name, self::OPEN_WORLD, true) || $name === 'run_destructive_tool';
    }

    /** Is this a tool of the system or of an add-on (3.0)? */
    public static function exists(string $name): bool
    {
        return isset(self::TOOLS[$name]) || \Talea\Extension\Registry::get()->tool($name) !== null;
    }

    public static function access(string $name): string
    {
        if (isset(self::META[$name])) {
            return self::META[$name][0];
        }
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
