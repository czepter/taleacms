<?php

declare(strict_types=1);

namespace Talea\Mcp;

use Talea\Core\Db;
use Talea\Core\Uuid;

/**
 * The public ids of MCP (HF-16): a row of the site is named by its UUID v4 in every argument and every result of a tool –
 * never by its database number. The handlers keep working with numbers: Server::call translates the arguments in before the
 * tool runs (in()) and the result out afterwards (out()).
 *
 * A rule is a table name, or [sibling key, [value of the sibling => table]] when the table depends on a neighbour
 * (restore_from_trash by type, a broken link by kind). A path names the key, '*' stands for every element of a list or
 * object: 'items.*.id'. The rows a build refers to (the component element, the booking element) go through buildIn() and buildOut().
 */
final class PublicIds
{
    private const array PAGE = ['id' => 'pages', 'popup' => 'popups', 'component' => 'components'];

    /** Arguments naming a row: tool => path => rule. A value that is not the UUID of an existing row is refused. */
    private const array IN = [
        'get_page' => ['id' => 'pages'], 'trash_page' => ['id' => 'pages'],
        'create_page' => ['parent' => 'pages', 'translation_of' => 'pages'],
        'update_page' => ['id' => 'pages', 'parent' => 'pages', 'translation_of' => 'pages'],
        'save_menu' => ['items.*.page_id' => 'pages', 'items.*.children.*.page_id' => 'pages', 'items.*.children.*.children.*.page_id' => 'pages'],
        'get_build' => self::PAGE, 'edit_build' => self::PAGE, 'save_build' => self::PAGE, 'publish_build' => self::PAGE, 'list_build_versions' => self::PAGE,
        'restore_build_version' => self::PAGE, 'discard_draft' => self::PAGE, 'preview_link' => self::PAGE, 'save_section' => self::PAGE,
        'insert_section' => self::PAGE + ['saved_section' => 'sections'],
        'build_from_html' => ['id' => 'pages', 'popup' => 'popups'],
        'save_popup' => ['id' => 'popups', 'rules.pages.*' => 'pages'], 'delete_popup' => ['id' => 'popups'],
        'save_part_variant' => ['pages.*' => 'pages'],
        'save_component' => ['id' => 'components'], 'delete_component' => ['id' => 'components'], 'delete_section' => ['id' => 'sections'],
        'get_news' => ['id' => 'news'], 'update_news' => ['id' => 'news'], 'trash_news' => ['id' => 'news'], 'get_social_drafts' => ['id' => 'news'],
        'update_category' => ['id' => 'categories'], 'delete_category' => ['id' => 'categories'],
        'update_media' => ['id' => 'media'], 'delete_media' => ['id' => 'media'],
        'update_enquiry' => ['id' => 'enquiries'], 'delete_enquiry' => ['id' => 'enquiries'], 'request_testimonial' => ['id' => 'enquiries'],
        'save_collection_item' => ['id' => 'collection_items'], 'delete_collection_item' => ['id' => 'collection_items'], 'list_item_versions' => ['id' => 'collection_items'],
        'restore_item_version' => ['id' => 'collection_items'], 'get_email_signature' => ['id' => 'collection_items'], 'list_notice_log' => ['id' => 'collection_items'],
        'restore_from_trash' => ['id' => ['type', ['page' => 'pages', 'news' => 'news', 'collection_item' => 'collection_items']]],
        'update_settings' => ['settings.home_page' => 'pages'],
        'list_draft_comments' => ['page_id' => 'pages'],
        'list_bookings' => ['staff' => 'booking_staff', 'service' => 'booking_services'], 'booking_availability' => ['staff' => 'booking_staff', 'service' => 'booking_services'],
        'save_booking_service' => ['id' => 'booking_services', 'staff.*' => 'booking_staff'], 'save_booking_staff' => ['id' => 'booking_staff', 'services.*' => 'booking_services'],
        'cancel_booking' => ['id' => 'bookings'], 'confirm_booking' => ['id' => 'bookings'], 'decline_booking' => ['id' => 'bookings'], 'propose_booking_times' => ['id' => 'bookings'],
        'draft_newsletter' => ['id' => 'newsletters', 'news_ids.*' => 'news'], 'send_test_newsletter' => ['id' => 'newsletters'], 'send_newsletter' => ['id' => 'newsletters'],
        'delete_newsletter' => ['id' => 'newsletters'],
        'list_requests' => ['id' => 'requests'], 'update_request' => ['id' => 'requests'],
        'get_site' => ['id' => 'fleet_sites'],
    ];

    private const array BY_KIND = ['kind', ['page' => 'pages', 'news' => 'news', 'item' => 'collection_items']];
    private const array BY_TYPE = ['type', ['page' => 'pages', 'news' => 'news', 'collection_item' => 'collection_items']];

    /**
     * Ids in results: tool => path => rule. What a handler builds itself (links, describeTarget, the *_build tools, builder_schema)
     * it writes as public ids already; a value that is not a number is left alone, so the two never conflict.
     */
    private const array OUT = [
        'site_info' => ['home_page' => 'pages'],
        'list_pages' => ['*.id' => 'pages'],
        'get_page' => ['id' => 'pages', 'parent' => 'pages', 'translation_of' => 'pages'],
        'create_page' => ['id' => 'pages'], 'update_page' => ['id' => 'pages'], 'trash_page' => ['id' => 'pages'],
        'get_menu' => self::MENU, 'save_menu' => self::MENU,
        'list_trash' => ['pages.*.id' => 'pages', 'news.*.id' => 'news', 'collection_items.*.id' => 'collection_items'],
        'restore_from_trash' => ['id' => ['restored', ['page' => 'pages', 'news' => 'news', 'collection_item' => 'collection_items']]],
        'translation_status' => ['items.*.id' => self::BY_TYPE, 'items.*.translations.*.id' => self::BY_TYPE],
        'list_site_parts' => ['*.pages.*' => 'pages'],
        'save_part_variant' => ['pages.*' => 'pages'],
        'delete_section' => ['deleted' => 'sections'], 'save_section' => ['id' => 'sections'],
        'list_components' => ['*.id' => 'components'], 'save_component' => ['id' => 'components'], 'delete_component' => ['deleted' => 'components'],
        'list_popups' => ['*.rules.pages.*' => 'pages'], 'save_popup' => ['rules.pages.*' => 'pages'], 'delete_popup' => ['deleted' => 'popups'],
        'create_collection' => ['list_page.id' => 'pages', 'more_pages.*.id' => 'pages'],
        'list_collection_items' => ['items.*.id' => 'collection_items'], 'save_collection_item' => ['id' => 'collection_items'],
        'delete_collection_item' => ['trashed' => 'collection_items'], 'restore_item_version' => ['restored' => 'collection_items'],
        'list_notice_log' => ['entries.*.item' => 'collection_items'],
        'list_news' => ['*.id' => 'news'], 'get_news' => ['id' => 'news'], 'create_news' => ['id' => 'news'], 'update_news' => ['id' => 'news'], 'trash_news' => ['trashed' => 'news'],
        'get_social_drafts' => ['news_id' => 'news'],
        'list_categories' => ['*.id' => 'categories'], 'create_category' => ['id' => 'categories'], 'update_category' => ['id' => 'categories'], 'delete_category' => ['deleted' => 'categories'],
        'list_media' => ['*.id' => 'media'], 'upload_file' => ['id' => 'media'], 'update_media' => ['id' => 'media'], 'delete_media' => ['deleted' => 'media'],
        'list_media_without_alt' => ['images.*.id' => 'media'],
        'list_enquiries' => ['*.id' => 'enquiries'], 'update_enquiry' => ['id' => 'enquiries'], 'delete_enquiry' => ['deleted' => 'enquiries'],
        'triage_enquiries' => ['enquiries.*.id' => 'enquiries'], 'find_personal_data' => ['enquiry_ids.*' => 'enquiries'],
        'erase_personal_data' => ['kept_testimonials.*' => 'collection_items'],
        'update_settings' => ['saved.home_page' => 'pages', 'settings.home_page' => 'pages'],
        'accessibility_statement' => ['page.id' => 'pages'],
        'list_draft_comments' => ['comments.*.page_id' => 'pages'], 'resolve_draft_comment' => ['page_id' => 'pages'],
        'list_broken_links' => ['links.*.id' => self::BY_KIND] + [
            'links.*.target.page' => 'pages', 'links.*.target.news' => 'news', 'links.*.target.item' => 'collection_items', 'links.*.target.component' => 'components', 'links.*.target.popup' => 'popups'],
        'suggest_internal_links' => ['orphans.*.id' => self::BY_KIND, 'orphans.*.candidates.*.page_id' => 'pages',
            'orphans.*.target.page' => 'pages', 'orphans.*.target.news' => 'news', 'orphans.*.target.item' => 'collection_items',
            'orphans.*.candidates.*.target.page' => 'pages'],
        'site_audit' => ['findings.*.target.page' => 'pages', 'findings.*.target.news' => 'news', 'findings.*.target.item' => 'collection_items',
            'findings.*.target.component' => 'components', 'findings.*.target.popup' => 'popups'],
        'get_stats' => ['news.*.id' => 'news'],
        'list_bookings' => ['bookings.*.id' => 'bookings'],
        'booking_availability' => ['services.*.id' => 'booking_services', 'services.*.staff.*' => 'booking_staff', 'staff.*.id' => 'booking_staff', 'staff.*.services.*' => 'booking_services'],
        'save_booking_service' => ['service.id' => 'booking_services', 'service.staff.*' => 'booking_staff'],
        'save_booking_staff' => ['staff.id' => 'booking_staff', 'staff.services.*' => 'booking_services', 'staff.user_id' => 'users'],
        'cancel_booking' => ['cancelled' => 'bookings'], 'confirm_booking' => ['confirmed' => 'bookings'], 'decline_booking' => ['declined' => 'bookings'],
        'propose_booking_times' => ['proposed' => 'bookings'],
        'list_newsletters' => ['newsletters.*.id' => 'newsletters', 'newsletters.*.news_ids.*' => 'news'], 'draft_newsletter' => ['id' => 'newsletters', 'news_ids.*' => 'news'],
        'send_newsletter' => ['id' => 'newsletters', 'news_ids.*' => 'news'], 'delete_newsletter' => ['deleted' => 'newsletters'],
        'list_requests' => ['requests.*.id' => 'requests', 'requests.*.about.id' => ['type', ['page' => 'pages', 'news' => 'news', 'item' => 'collection_items']], 'requests.*.attachments.*.id' => 'media'],
        'update_request' => ['id' => 'requests'],
        'list_sites' => ['sites.*.id' => 'fleet_sites'], 'get_site' => ['id' => 'fleet_sites'],
        'undo_agent_session' => ['conflicts.*.key' => ['table', []]],
        // what the event data points at: content.expired names a kind, the others the row by their own key
        'list_events' => ['events.*.data.id' => ['kind', ['page' => 'pages', 'news' => 'news', 'collection_item' => 'collection_items', 'popup' => 'popups']], 'events.*.data.page' => 'pages',
            'events.*.data.item' => 'collection_items', 'events.*.data.booking' => 'bookings', 'events.*.data.site' => 'fleet_sites'],
    ];

    private const array MENU = ['items.*.page_id' => 'pages', 'items.*.children.*.page_id' => 'pages', 'items.*.children.*.children.*.page_id' => 'pages', 'pages.*.page_id' => 'pages'];

    /**
     * The arguments of a tool with the public ids replaced by the row numbers the handlers work with. With $strict false an id that
     * names no row stays as it is (the guardrails look at the call first and refuse it with their own words; the strict pass follows
     * before the tool runs).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws \InvalidArgumentException an id that is not the public id of an existing row (a number from outside included)
     */
    public static function in(Db $db, string $tool, array $arguments, bool $strict = true): array
    {
        foreach (self::IN[$tool] ?? [] as $path => $rule) {
            self::walk($arguments, explode('.', $path), $rule, $db, $arguments, $strict ? self::TO_NUMBER : self::TO_NUMBER_SOFT);
        }

        return $arguments;
    }

    /** The result of a tool with the row numbers replaced by public ids. */
    public static function out(Db $db, string $tool, mixed $result, array $arguments = []): mixed
    {
        if (!is_array($result)) {
            return $result;
        }
        if ($tool === 'list_events') {
            foreach ($result['events'] ?? [] as $i => $event) { // a request.created event names its request by id
                if (($event['type'] ?? '') === 'request.created' && isset($event['data']['id'])) {
                    self::convert($result['events'][$i]['data']['id'], $result['events'][$i]['data'], 'requests', $db, $arguments, self::TO_PUBLIC, 'id');
                }
            }
        }
        foreach (self::OUT[$tool] ?? [] as $path => $rule) {
            self::walk($result, explode('.', $path), $rule, $db, $arguments, self::TO_PUBLIC);
        }

        return $result;
    }

    private const int TO_PUBLIC = 0;
    private const int TO_NUMBER = 1;
    private const int TO_NUMBER_SOFT = 2;

    /** What a build element's content refers to by row: property => table (the component element, the booking element). */
    private const array BUILD_REFS = ['component' => 'components', 'service' => 'booking_services'];

    /**
     * A build (or build operations) from a tool argument with the rows its elements refer to named by number, as builds are stored.
     *
     * @throws \InvalidArgumentException a reference that is not the public id of an existing row
     */
    public static function buildIn(Db $db, mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if ($key === 'content' && is_array($value)) {
                foreach (self::BUILD_REFS as $property => $table) {
                    if (array_key_exists($property, $value)) {
                        self::convert($value[$property], $value, $table, $db, [], self::TO_NUMBER, $property);
                    }
                }
            }
            $node[$key] = self::buildIn($db, $value);
        }

        return $node;
    }

    /** A build for a tool result: the rows its elements refer to named by public id. */
    public static function buildOut(Db $db, mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $key => $value) {
            if ($key === 'content' && is_array($value)) {
                foreach (self::BUILD_REFS as $property => $table) {
                    if (array_key_exists($property, $value)) {
                        self::convert($value[$property], $value, $table, $db, [], self::TO_PUBLIC, $property);
                    }
                }
            }
            $node[$key] = self::buildOut($db, $value);
        }

        return $node;
    }

    /** @param array<string, mixed> $arguments the call's arguments (the sibling a typed rule reads when it is not in the node) */
    private static function walk(mixed &$node, array $segments, string|array $rule, Db $db, array $arguments, int $mode): void
    {
        if (!is_array($node)) {
            return;
        }
        $segment = array_shift($segments);
        $keys = $segment === '*' ? array_keys($node) : (array_key_exists($segment, $node) ? [$segment] : []);
        foreach ($keys as $key) {
            if ($segments === []) {
                self::convert($node[$key], $node, $rule, $db, $arguments, $mode, (string) $key);
            } else {
                self::walk($node[$key], $segments, $rule, $db, $arguments, $mode);
            }
        }
    }

    /** @param array<mixed> $parent */
    private static function convert(mixed &$value, array $parent, string|array $rule, Db $db, array $arguments, int $mode, string $key): void
    {
        $table = is_string($rule) ? $rule : (self::table($rule, $parent, $arguments));
        if ($mode !== self::TO_PUBLIC) {
            if ($value === null || $value === '' || $value === 0) {
                return; // nothing named: "no parent", "no popup" – the handler's own default
            }
            $id = $table !== null && Uuid::valid($value) ? $db->internalId($table, $value) : 0;
            if ($id === 0 && $mode === self::TO_NUMBER_SOFT) {
                return;
            }
            if ($id === 0) {
                throw new \InvalidArgumentException('The id given as “' . $key . '” is not valid: rows are named by their public id (UUID), exactly as the list and get tools return it.');
            }
            $db->publicId($table, $id); // remembered, so a result can name a row the tool deleted
            $value = $id;

            return;
        }
        if ($table === null || !(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value <= 0) {
            return;
        }
        $public = $db->publicId($table, (int) $value);
        $value = $public !== '' ? $public : null;
    }

    /** @param array{0: string, 1: array<string, string>} $rule @param array<mixed> $parent */
    private static function table(array $rule, array $parent, array $arguments): ?string
    {
        $sibling = $parent[$rule[0]] ?? $arguments[$rule[0]] ?? null;

        if ($rule[1] === []) {
            return is_string($sibling) && in_array($sibling, Db::PUBLIC_ID_TABLES, true) ? $sibling : null; // the sibling names the table itself
        }

        return is_string($sibling) ? ($rule[1][$sibling] ?? null) : null;
    }
}
