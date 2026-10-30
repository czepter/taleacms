<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\Collections;
use Talea\Builder\Components;
use Talea\Builder\Popups;
use Talea\Builder\SiteParts;
use Talea\Builder\Style;

/**
 * The rows of a Talea export, one cleaner per table (Core\SiteImport uses them): each takes the row as the archive has it and returns the
 * columns to store, or null to skip the row. Everything goes through the validators of the administration (docs/specs/split-large-files.md).
 *
 * @phpstan-ignore trait.unused
 */
trait SiteImportRows
{
    /** An address from the export when it is valid, otherwise one made from the name. */
    private static function slug(mixed $slug, string $fallback, int $max): string
    {
        $slug = is_string($slug) ? $slug : '';

        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) && strlen($slug) <= $max ? $slug : slugify($fallback, $max);
    }

    /** A value from a fixed list (types of pop-ups and the like), otherwise the first one. */
    private static function pick(array $list, mixed $value): string
    {
        return is_string($value) && isset($list[$value]) ? $value : (string) array_key_first($list);
    }

    private static function text(mixed $v, int $max): string
    {
        return mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
    }

    /**
     * The HTML of a page or news item from the export, sanitized the way a save without code rights is (Html::safe): the archive
     * may come from anywhere, so it never brings script into the site. Structure, classes, images and links stay; an embedded
     * YouTube or Vimeo player becomes its URL on its own line, which the site shows as a player again.
     */
    private static function html(mixed $v, int $max): string
    {
        return Html::safe(WpContent::embeddedVideos(self::text($v, $max)));
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v) ? $v : null;
    }

    /**
     * "True until" and "review by" (2.10) of pages, news, items and pop-ups.
     *
     * @return array{valid_until: ?string, review_by: ?string}
     */
    private static function validity(array $r): array
    {
        $day = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;

        return ['valid_until' => $day($r['valid_until'] ?? null), 'review_by' => $day($r['review_by'] ?? null)];
    }

    /** A business fact (2.10); a value that does not fit its type is left out. @return array<string, mixed>|null */
    private static function fact(array $r): ?array
    {
        $key = (string) ($r['fact_key'] ?? '');
        $type = isset(Facts::TYPES[$r['type'] ?? '']) ? (string) $r['type'] : 'text';
        $value = Facts::clean($type, (string) ($r['value'] ?? ''));
        if (!preg_match(Facts::KEY_PATTERN, $key) || isset(Facts::BUILT_IN[$key]) || $value === null || ($type === 'text' && Facts::startsWithScheme($value))) {
            return null; // a text fact "javascript:…" is refused like in Facts::save (3.3.3, N50)
        }

        return ['fact_key' => $key, 'language' => self::language($r['language'] ?? ''), 'label' => self::text(strip_tags((string) ($r['label'] ?? $key)), 150), 'type' => $type, 'value' => $value,
            'schema_prop' => isset(Facts::SCHEMA_PROPS[$r['schema_prop'] ?? '']) ? (string) $r['schema_prop'] : '', 'source' => self::text(strip_tags((string) ($r['source'] ?? '')), 255),
            'updated_at' => date('Y-m-d H:i:s')];
    }

    /** An exception to the opening hours (2.10). @return array<string, mixed>|null */
    /** An applied industry blueprint (2.11): only a manifest that passes Core\Blueprint::sanitize. */
    private static function blueprint(array $r): ?array
    {
        [$manifest] = \Talea\Core\Blueprint::sanitize(is_array($r['manifest'] ?? null) ? $r['manifest'] : json_decode((string) ($r['manifest'] ?? ''), true));

        return $manifest === null ? null : ['bkey' => $manifest['key'], 'name' => \Talea\Core\Blueprint::text($manifest['name']), 'manifest' => (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'applied_at' => date('Y-m-d H:i:s')];
    }

    /** A note of the agent notebook (2.15, Core\Notebook) – the author and the dates stay as they were. @return array<string, mixed>|null */
    private static function note(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 150);
        $text = self::text(trim(strip_tags((string) ($r['text'] ?? ''))), Notebook::MAX_TEXT);
        if ($title === '' || $text === '') {
            return null;
        }
        $date = fn (mixed $v): string => is_string($v) && strtotime($v) !== false ? date('Y-m-d H:i:s', (int) strtotime($v)) : date('Y-m-d H:i:s');

        return ['id' => (int) ($r['id'] ?? 0) > 0 ? (int) $r['id'] : null, 'topic' => Notebook::topic($r['topic'] ?? '') ?? 'other', 'title' => $title, 'text' => $text, 'pinned' => !empty($r['pinned']) ? 1 : 0,
            'author' => self::text(trim(strip_tags((string) ($r['author'] ?? ''))), 100), 'created_at' => $date($r['created_at'] ?? null), 'updated_at' => $date($r['updated_at'] ?? null)];
    }

    /** @param array<string, mixed> $r */
    private static function bookingService(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 150);
        $duration = (int) ($r['duration_min'] ?? 0);
        if (trim($name) === '' || $duration < 5 || $duration > Booking::MAX_DURATION) {
            return null;
        }

        return ['id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'duration_min' => $duration, 'buffer_min' => max(0, min(240, (int) ($r['buffer_min'] ?? 0))), 'price_text' => self::text(strip_tags((string) ($r['price_text'] ?? '')), 60),
            'description' => self::text(strip_tags((string) ($r['description'] ?? '')), 500), 'active' => !empty($r['active']) ? 1 : 0, 'requires_confirmation' => !empty($r['requires_confirmation']) ? 1 : 0, 'sort_order' => (int) ($r['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $r the account link (user_id) does not travel – the users of the new site are different */
    private static function bookingStaff(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 150);
        $email = trim((string) ($r['email'] ?? ''));
        if (trim($name) === '') {
            return null;
        }

        return ['id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 190) : '', 'active' => !empty($r['active']) ? 1 : 0, 'sort_order' => (int) ($r['sort_order'] ?? 0)];
    }

    /** @param array<string, mixed> $r */
    private function bookingHours(array $r): ?array
    {
        $ranges = Booking::parseHours([(int) ($r['weekday'] ?? 0) => (string) ($r['time_from'] ?? '') . '-' . (string) ($r['time_to'] ?? '')]);
        $staff = $this->ref('booking_staff', $r['staff_id'] ?? null);
        $service = ($r['service_id'] ?? null) === null ? null : $this->ref('booking_services', $r['service_id']);
        if ($staff <= 0 || $service === 0 || $ranges === null || $ranges === []) {
            return null; // hours of a service that did not come along are left out, never turned into the general hours
        }
        [$from, $to] = $ranges[(int) $r['weekday']][0];

        return ['staff_id' => $staff, 'service_id' => $service, 'weekday' => (int) $r['weekday'], 'time_from' => $from, 'time_to' => $to];
    }

    /** @param array<string, mixed> $r */
    private function bookingOff(array $r): ?array
    {
        $range = Booking::offRange(substr((string) ($r['off_from'] ?? ''), 0, 16), substr((string) ($r['off_to'] ?? ''), 0, 16));
        if ($range === null) {
            return null;
        }

        return ['staff_id' => ($staff = $this->ref('booking_staff', $r['staff_id'] ?? null)) > 0 ? $staff : null, 'off_from' => $range[0], 'off_to' => $range[1], 'note' => self::text(strip_tags((string) ($r['note'] ?? '')), 150)];
    }

    private static function hoursException(array $r): ?array
    {
        $from = (string) ($r['date_from'] ?? '');
        $to = (string) ($r['date_to'] ?? '');
        $closed = !empty($r['closed']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from
            || (!$closed && (Hours::parseRanges((string) ($r['hours'] ?? '')) ?? []) === [])) {
            return null;
        }

        return ['date_from' => $from, 'date_to' => $to, 'closed' => $closed ? 1 : 0, 'hours' => $closed ? '' : self::text((string) $r['hours'], 100),
            'note' => self::text(strip_tags((string) ($r['note'] ?? '')), 150), 'notice_days' => max(0, min(60, (int) ($r['notice_days'] ?? 7))), 'created_at' => date('Y-m-d H:i:s')];
    }

    /** A row of the notice log (2.11, Core\Notices) as exported – the trail is kept as it was. @return array<string, mixed>|null */
    private function noticeLogRow(array $r): ?array
    {
        $at = (string) ($r['at'] ?? '');
        $item = $this->ref('collection_items', $r['item_id'] ?? null);
        if ((int) ($r['id'] ?? 0) <= 0 || $item <= 0 || !in_array($r['action'] ?? '', Notices::ACTIONS, true) || strtotime($at) === false) {
            return null;
        }
        $fields = is_array($r['fields'] ?? null) ? $r['fields'] : json_decode((string) ($r['fields'] ?? ''), true);

        return ['id' => (int) $r['id'], 'item_id' => $item, 'action' => (string) $r['action'], 'at' => date('Y-m-d H:i:s', (int) strtotime($at)),
            'by' => self::text(strip_tags((string) ($r['by'] ?? '')), 100), 'fields' => (string) json_encode(is_array($fields) ? $fields : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    private static function language(mixed $v): string
    {
        return is_string($v) && preg_match('/^' . Language::TAG . '$/D', $v) ? $v : '';
    }

    /** A path of an image or file: from media/, or an https:// address; anything else (javascript:…) is dropped. */
    private static function file(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('#^(/?media/[^\s"\'<>]+|https://[^\s"\'<>]+)$#', $v) && !str_contains($v, '..') ? $v : '';
    }

    /** A builder build from the export: through the validator like any save; null for an empty or broken one. */
    private function build(mixed $v): ?string
    {
        $build = is_array($v) ? $v : (is_string($v) && $v !== '' ? json_decode($v, true) : null);
        if (!is_array($build)) {
            return null;
        }
        // components and booking elements point at public ids in the export: now at the keys they get on this site
        $build = json_decode(SiteExport::mapReferences('build', (string) json_encode($build), $this->mapper()), true) ?: $build;
        [$clean] = Build::sanitize($build, true);

        return Build::toJson($clean);
    }

    /** @return \Closure(string, mixed): int the reference map for SiteExport::mapReferences – a public id of the export to the new key (0 = none) */
    private function mapper(): \Closure
    {
        return fn (string $table, mixed $publicId): int => $this->ref($table, $publicId);
    }

    private function category(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 100);

        return $name !== '' ? ['category_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 120),
            'description' => self::text($r['description'] ?? '', 5000), 'weight' => (int) ($r['weight'] ?? 0), 'language' => self::language($r['language'] ?? ''),
            'translation_of' => $this->ref('categories', $r['translation_of'] ?? null) ?: null] : null;
    }

    private function tag(array $r): ?array
    {
        $name = self::text(strip_tags((string) ($r['name'] ?? '')), 100);

        return $name !== '' ? ['tag_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 120),
            'description' => self::text($r['description'] ?? '', 5000), 'image' => self::file($r['image'] ?? '')] : null;
    }

    private function page(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 200);
        if ($title === '') {
            return null;
        }

        return ['page_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'title' => $title, 'slug' => self::slug($r['slug'] ?? '', $title, 120), 'description' => self::text($r['description'] ?? '', 300),
            'seo_title' => self::text($r['seo_title'] ?? '', 200), 'image' => self::file($r['image'] ?? ''), 'noindex' => (int) !empty($r['noindex']),
            'text' => self::html($r['text'] ?? '', 4_000_000), 'visible' => (int) !empty($r['visible']), 'publish_at' => self::date($r['publish_at'] ?? null),
            'in_menu' => (int) !empty($r['in_menu']), 'sort_order' => (int) ($r['sort_order'] ?? 0), 'updated_at' => self::date($r['updated_at'] ?? null) ?? date('Y-m-d H:i:s'),
            'language' => self::language($r['language'] ?? ''), 'translation_of' => $this->ref('pages', $r['translation_of'] ?? null) ?: null, 'parent_id' => $this->ref('pages', $r['parent_id'] ?? null) ?: null,
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null)] + self::validity($r) + SeoFields::fromInput($r);
    }

    private function newsItem(array $r): ?array
    {
        $title = self::text(trim(strip_tags((string) ($r['title'] ?? ''))), 255);
        if ($title === '') {
            return null;
        }
        $row = ['news_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'title' => $title, 'slug' => self::slug($r['slug'] ?? '', $title, 160), 'intro' => self::html($r['intro'] ?? '', 100_000),
            'text' => self::html($r['text'] ?? '', 4_000_000), 'image' => self::file($r['image'] ?? ''), 'image_caption' => self::text($r['image_caption'] ?? '', 300),
            'image_author' => self::text($r['image_author'] ?? '', 120), 'category_id' => $this->ref('categories', $r['category_id'] ?? null), 'author_id' => $this->admin > 0 ? $this->admin : null,
            'published_at' => self::date($r['published_at'] ?? null) ?? date('Y-m-d H:i:s'), 'visible' => (int) !empty($r['visible']), 'keywords' => self::text($r['keywords'] ?? '', 500),
            'seo_title' => self::text($r['seo_title'] ?? '', 255), 'seo_description' => self::text($r['seo_description'] ?? '', 320), 'noindex' => (int) !empty($r['noindex']),
            'visit' => (int) ($r['visit'] ?? 0), 'edited_at' => self::date($r['edited_at'] ?? null), 'updated_at' => self::date($r['updated_at'] ?? null),
            // already announced on the old site: the import sends no webhook and no IndexNow for the whole archive
            'announced_at' => date('Y-m-d H:i:s'), 'language' => self::language($r['language'] ?? ''), 'translation_of' => $this->ref('news', $r['translation_of'] ?? null) ?: null, 'search_text' => null,
            '_tags' => array_values(array_filter(array_map(fn (mixed $t): int => $this->ref('tags', $t), is_array($r['tags'] ?? null) ? $r['tags'] : []), fn (int $i): bool => $i > 0))] + self::validity($r) + SeoFields::fromInput($r);
        if (is_string($r['faq'] ?? null)) {
            $row['faq'] = self::text($r['faq'], 60_000);
        }

        return $row;
    }

    private function redirect(array $r): ?array
    {
        // addresses as the Redirects module stores them: the old one without the slashes around, the target a path or a URL;
        // an empty target (or "/") is the home page, stored as '' and checked as "/" like the module does
        // an archive from before match types (or a converted Kaleta export) has no match_type: an exact rule
        $match = in_array($r['match_type'] ?? null, RedirectRules::MATCHES, true) ? $r['match_type'] : 'exact';
        $type = in_array((int) ($r['type'] ?? 301), RedirectRules::TYPES, true) ? (int) ($r['type'] ?? 301) : 301;
        $from = is_string($r['from_path'] ?? null) ? ($match === 'exact' ? trim($r['from_path'], '/ ') : $r['from_path']) : '';
        $to = is_string($r['to_path'] ?? null) ? trim($r['to_path']) : '';
        $home = $type < 400 && trim($to, '/') === '';
        if ($home) {
            $to = '';
        }

        $valid = ($match !== 'exact' || preg_match('#^[^\s/][^\s]{0,254}$#', $from))
            && RedirectRules::validate($from, $home ? '/' : $to, $match, $type) === null
            && is_string($r['to_path'] ?? null)
            && preg_match('#^(?!//)(?!javascript:)(?!data:)[^\s]{0,255}$#i', $to);

        return $valid
            ? ['redirect_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'from_path' => $from, 'to_path' => $to, 'match_type' => $match, 'type' => $type, 'active' => empty($r['active'] ?? 1) ? 0 : 1, 'hits' => 0, 'created_at' => date('Y-m-d H:i:s'),
                'auto_score' => is_numeric($r['auto_score'] ?? null) ? max(0, min(100, (int) $r['auto_score'])) : null]
            : null;
    }

    private function sharedClass(array $r): ?array
    {
        $name = (string) ($r['name'] ?? '');
        if (!preg_match(Build::CLASS_PATTERN, $name)) {
            return null;
        }
        $errors = [];
        $discarded = [];
        $style = Style::sanitize(is_array($r['style'] ?? null) ? $r['style'] : json_decode((string) ($r['style'] ?? ''), true), $name, $errors);

        return ['name' => $name, 'style' => (string) json_encode($style ?: new \stdClass(), JSON_UNESCAPED_UNICODE), 'css' => Style::customCss((string) ($r['css'] ?? ''), $discarded), 'updated_at' => date('Y-m-d H:i:s')];
    }

    private function sitePart(array $r): ?array
    {
        $type = (string) ($r['type'] ?? '');
        $variant = (string) ($r['variant'] ?? '');
        if (!isset(SiteParts::TYPES[$type]) || ($variant !== '' && !preg_match(SiteParts::VARIANT_PATTERN, $variant))) {
            return null;
        }
        $pages = is_array($r['pages'] ?? null) ? $r['pages'] : json_decode((string) ($r['pages'] ?? ''), true);
        $pages = is_array($pages) ? array_map(fn (mixed $p): int => $this->ref('pages', $p), $pages) : $pages;

        return ['type' => $type, 'language' => self::language($r['language'] ?? ''), 'variant' => $variant, 'name' => self::text(strip_tags((string) ($r['name'] ?? '')), 100),
            'pages' => is_array($pages) ? (string) json_encode(array_values(array_filter(array_map('intval', $pages), fn (int $i): bool => $i > 0))) : null,
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')];
    }

    /** How a component of the archive is found again among the numbers given to it: by its public id, else by its place in the file. */
    private static function componentKey(array $r): string
    {
        return Uuid::valid($r['public_id'] ?? null) ? (string) $r['public_id'] : '#' . (int) $r['_id'];
    }

    private function component(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);
        $properties = is_array($r['properties'] ?? null) ? $r['properties'] : json_decode((string) ($r['properties'] ?? ''), true);

        return $name !== '' ? ['component_id' => $this->siteMode() ? ($this->map['components'][self::componentKey($r)] ?? 0) : (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name,
            'properties' => (string) json_encode(Components::sanitizeProperties($properties), JSON_UNESCAPED_UNICODE),
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'kit_key' => self::kitKey($r['kit_key'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    /** A form (docs/specs/form-builder.md): its build is its one Form element, through the same validator as every build. */
    private function formRow(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 120);
        $form = fn (?string $json): ?string => $json === null ? null : Build::toJson(\Talea\Builder\FormRecords::definitionBuild(Build::fromJson($json)));

        return $name !== '' ? ['form_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'build' => $form($this->build($r['build'] ?? null)),
            'build_draft' => $form($this->build($r['build_draft'] ?? null)), 'origin' => '', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    /** The key a component or section got from a fleet design kit (2.16, Fleet\Kit) – kept, so the next kit updates it instead of adding a copy. */
    private static function kitKey(mixed $v): ?string
    {
        return is_string($v) && preg_match(\Talea\Fleet\Kit::KEY_PATTERN, $v) ? $v : null;
    }

    private function section(array $r): ?array
    {
        $element = is_array($r['element'] ?? null) ? $r['element'] : json_decode((string) ($r['element'] ?? ''), true);
        $element = is_array($element) ? json_decode(SiteExport::mapReferences('element', (string) json_encode($element), $this->mapper()), true) : $element;
        $build = is_array($element) ? json_decode((string) $this->build(['v' => Build::VERSION, 'children' => [$element]]), true) : null;
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);

        return $name !== '' && isset($build['children'][0])
            ? ['section_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'element' => (string) json_encode($build['children'][0], JSON_UNESCAPED_UNICODE), 'kit_key' => self::kitKey($r['kit_key'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function menu(array $r): ?array
    {
        $location = (string) ($r['location'] ?? '');
        $items = is_array($r['items'] ?? null) ? $r['items'] : json_decode((string) ($r['items'] ?? ''), true);
        $items = is_array($items) ? json_decode(SiteExport::mapReferences('menu', (string) json_encode($items), $this->mapper()), true) : $items;

        return isset(Menu::LOCATIONS[$location]) ? ['location' => $location, 'language' => self::language($r['language'] ?? ''),
            'items' => (string) json_encode(Menu::sanitize($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function collection(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);
        $fields = is_array($r['fields'] ?? null) ? $r['fields'] : json_decode((string) ($r['fields'] ?? ''), true);

        return $name !== '' ? ['collection_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 110),
            'fields' => (string) json_encode(Collections::sanitizeFields($fields), JSON_UNESCAPED_UNICODE), 'detail' => (int) !empty($r['detail']),
            'admin_menu' => (int) (!array_key_exists('admin_menu', $r) || !empty($r['admin_menu'])), // an older export has no such column: in the menu, as after the upgrade
            'hidden_redirect' => Collections::cleanRedirect((string) ($r['hidden_redirect'] ?? '')) ?? '',
            'preset' => \Talea\Builder\Presets::get((string) ($r['preset'] ?? '')) !== null ? (string) $r['preset'] : '',
            'schema_org' => ($schema = \Talea\Builder\CollectionSchema::sanitize(is_array($r['schema_org'] ?? null) ? $r['schema_org'] : json_decode((string) ($r['schema_org'] ?? ''), true), Collections::sanitizeFields($fields))) === null
                ? null : (string) json_encode($schema, JSON_UNESCAPED_UNICODE),
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function collectionTemplate(array $r): ?array
    {
        return ($idk = $this->ref('collections', $r['collection_id'] ?? null)) > 0 ? ['collection_id' => $idk, 'language' => self::language($r['language'] ?? ''), 'build' => $this->build($r['build'] ?? null),
            'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] : null;
    }

    private function collectionItem(array $r): ?array
    {
        $idk = $this->ref('collections', $r['collection_id'] ?? null);
        $fields = $idk > 0 ? json_decode((string) $this->db->value('SELECT fields FROM {collections} WHERE collection_id = ?', [$idk]), true) : null;
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 200);
        if (!is_array($fields) || $name === '') {
            return null; // an item without its collection
        }
        $data = is_array($r['data'] ?? null) ? $r['data'] : json_decode((string) ($r['data'] ?? ''), true);

        return ['item_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'collection_id' => $idk, 'name' => $name, 'slug' => self::slug($r['slug'] ?? '', $name, 160),
            'data' => (string) json_encode(Collections::sanitizeData($fields, is_array($data) ? $data : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => (int) ($r['sort_order'] ?? 0), 'visible' => (int) !empty($r['visible']), 'language' => self::language($r['language'] ?? ''),
            'created_at' => self::date($r['created_at'] ?? null) ?? date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            'seo_title' => self::text($r['seo_title'] ?? '', 200), 'description' => self::text($r['description'] ?? '', 300), 'image' => self::file($r['image'] ?? ''),
            'noindex' => (int) !empty($r['noindex']), 'publish_at' => self::date($r['publish_at'] ?? null)] + self::validity($r) + SeoFields::fromInput($r);
    }

    /** A previous file of a document (2.11); a row whose document was not imported fails on the foreign key and is skipped. */
    private function documentVersion(array $r): ?array
    {
        $file = is_string($r['file'] ?? null) ? trim($r['file']) : '';
        $item = $this->ref('collection_items', $r['item_id'] ?? null);
        if ($item <= 0 || preg_match(Collections::MEDIA_PATTERN, $file) !== 1 || str_contains($file, '..')) {
            return null;
        }

        return ['item_id' => $item, 'file' => $file, 'version' => self::text(strip_tags((string) ($r['version'] ?? '')), 100),
            'replaced_at' => self::date($r['replaced_at'] ?? null) ?? date('Y-m-d H:i:s'), 'replaced_by' => self::text(strip_tags((string) ($r['replaced_by'] ?? '')), 100)];
    }

    private function popup(array $r): ?array
    {
        $name = self::text(trim(strip_tags((string) ($r['name'] ?? ''))), 100);
        $address = (string) ($r['slug'] ?? '');
        if ($name === '' || !preg_match(Popups::ADDRESS_PATTERN, $address)) {
            return null;
        }
        $rules = is_array($r['rules'] ?? null) ? $r['rules'] : json_decode((string) ($r['rules'] ?? ''), true);
        $rules = is_array($rules) ? json_decode(SiteExport::mapReferences('rules', (string) json_encode($rules), $this->mapper()), true) : $rules;

        return ['popup_id' => (int) $r['_id'], 'public_id' => self::publicId($r), 'name' => $name, 'slug' => $address,
            'type' => self::pick(Popups::TYPES, $r['type'] ?? ''), 'trigger_type' => self::pick(Popups::TRIGGERS, $r['trigger_type'] ?? ''),
            'value' => max(0, min(100_000, (int) ($r['value'] ?? 0))), 'rules' => (string) json_encode(Popups::sanitizeRules(is_array($rules) ? $rules : []), JSON_UNESCAPED_UNICODE),
            'frequency' => self::pick(Popups::FREQUENCIES, $r['frequency'] ?? ''),
            'days' => max(0, min(3650, (int) ($r['days'] ?? 0))), 'active' => (int) !empty($r['active']), 'sort_order' => (int) ($r['sort_order'] ?? 0),
            'build' => $this->build($r['build'] ?? null), 'build_draft' => $this->build($r['build_draft'] ?? null), 'updated_at' => date('Y-m-d H:i:s')] + self::validity($r);
    }

    private function mediaRow(array $r): ?array
    {
        // format 1 named the columns differently (file, width, height)
        $file = self::file($r['image_path'] ?? ($r['file'] ?? ''));
        if (!str_starts_with(ltrim($file, '/'), 'media/')) {
            return null;
        }
        $folder = $this->ref('media_folders', $r['folder_id'] ?? null);

        return $this->ownerKey('media', 'media_id', (int) $r['_id']) + ['public_id' => $this->ownerPublicId('media', $r), 'owner_id' => $this->admin > 0 ? $this->admin : null,
            'folder_id' => $folder > 0 && $this->db->value('SELECT 1 FROM {media_folders} WHERE folder_id = ?', [$folder]) !== null ? $folder : null,
            'name' => self::text($r['name'] ?? '', 150), 'description' => self::text($r['description'] ?? '', 500), 'author' => self::text($r['author'] ?? '', 120),
            'image_path' => ltrim($file, '/'), 'image_width' => max(0, min(65535, (int) ($r['image_width'] ?? ($r['width'] ?? 0)))),
            'image_height' => max(0, min(65535, (int) ($r['image_height'] ?? ($r['height'] ?? 0)))), 'image_size' => max(0, (int) ($r['image_size'] ?? 0)),
            'thumb_path' => ltrim(self::file($r['thumb_path'] ?? ''), '/'), 'thumb_width' => max(0, min(65535, (int) ($r['thumb_width'] ?? 0))),
            'thumb_height' => max(0, min(65535, (int) ($r['thumb_height'] ?? 0))), 'color' => is_string($r['color'] ?? null) && preg_match('/^(#[0-9a-f]{6}|-)?$/i', $r['color']) ? $r['color'] : '',
            'focal_point' => is_string($r['focal_point'] ?? null) && preg_match('/^(\d{1,3}% \d{1,3}%)?$/', $r['focal_point']) ? $r['focal_point'] : '', 'created_at' => self::date($r['created_at'] ?? null) ?? date('Y-m-d H:i:s')];
    }
}
