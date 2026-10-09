<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Custom post types and custom fields of a WordPress export as Kaleta collections (2.7): CPT UI, ACF, Secure Custom
 * Fields, Pods and plain custom fields keep their values in <wp:postmeta>.
 *
 *  - Which post types count: everything except the WordPress internals and the types of known plugins (menus, blocks,
 *    templates, form definitions, builder libraries, orders…) – EXCLUDED.
 *  - Which meta keys are fields: those without a leading underscore that do not belong to a known plugin (SYSTEM_META).
 *    ACF and Secure Custom Fields add "_<name>" = "field_…" next to each field – such a key is always a field.
 *  - Field types are guessed from the values (FIELD_TYPES of Builder\Collections): an attachment number or an image
 *    address = image, Ymd or Y-m-d = date, an address = link, a number, HTML, several lines, otherwise short text.
 *    Serialized values (repeaters, galleries, relationships) are left out and counted, so the preview can say so.
 *
 * Pure functions, covered by tools/unit-tests.php; Core\WpImport does the writing.
 */
final class WpTypes
{
    /** Post types that are not content: WordPress internals and known plugins. */
    public const string EXCLUDED = '/^(post|page|attachment|nav_menu_item|revision|custom_css|customize_changeset|oembed_cache|user_request|wp_[a-z_]+|acf-[a-z-]+|wpcf7_contact_form|elementor_[a-z_]+|e-landing-page|breakdance_[a-z_]+|oxy_[a-z_]+|ct_template|wpforms|wpforms_log|forminator_[a-z_]+|shop_[a-z_]+|product_variation|scheduled-action|cptui_[a-z_]*|jet-[a-z-]+|astra-advanced-hook|wpcode|tablepress_table|feedback|amn_[a-z_]+|smartcrawl_[a-z_]+|wds-[a-z-]+|ditty_[a-z_]+|cookielawinfo|cmplz-[a-z-]+)$/';

    /** Meta keys of plugins and themes that are not the site's own fields. */
    private const string SYSTEM_META = '/^(rank_math_|_|inline_featured_image$|ekit_|site-|theme-|stick-|ast-|astra-|breakdance|ocean_|sbg_|slide_template|rs_|panels_data$|classic-editor|enclosure$|litespeed|cmplz|wpml|pll_|footnotes$|wds_|smartcrawl|jet_|elementor|wp_|_wp_|om_|essb_|pys_|tdm_|td_|fusion_|avada_|et_|divi_|uagb_|spectra_|kadence_|generate_|neve_|hestia_|cs_|_oembed|total_sales$|_edit_)/i';

    /** At most this many fields per collection (Builder\Collections::sanitizeFields keeps 30; two go to content and excerpt). */
    public const int MAX_FIELDS = 28;

    public static function isCustomType(string $type): bool
    {
        return $type !== '' && preg_match('/^[a-z0-9_\-]{1,40}$/', $type) === 1 && preg_match(self::EXCLUDED, $type) !== 1;
    }

    /**
     * The custom fields of one item: key => value. ACF / Secure Custom Fields keys (marked by "_<key>" = "field_…") always
     * count; other keys only when no plugin owns them.
     *
     * @param array<string, string> $meta all postmeta of the item
     * @return array<string, string>
     */
    public static function fields(array $meta): array
    {
        $fields = [];
        foreach ($meta as $key => $value) {
            $key = (string) $key;
            $acf = str_starts_with((string) ($meta['_' . $key] ?? ''), 'field_');
            if (!$acf && preg_match(self::SYSTEM_META, $key) === 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,60}$/', $key) !== 1) {
                continue;
            }
            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * The type one value suggests; null = left out (a serialized repeater, gallery or relationship).
     *
     * @param array<int, string> $attachments attachment number => address (from the export)
     */
    public static function guessType(string $key, string $value, array $attachments = []): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return 'text';
        }
        if (preg_match('/^(a|O|s|i|b):\d*[:;{]/', $v) === 1) {
            return null;
        }
        if (ctype_digit($v) && (isset($attachments[(int) $v]) || preg_match('/image|photo|foto|obrazek|logo|picture|thumbnail|portrait|icon|ikona|bild/i', $key) === 1)) {
            return 'image';
        }
        if (preg_match('#^https?://\S+\.(jpe?g|png|webp|gif|avif)(\?\S*)?$#i', $v) === 1) {
            return 'image';
        }
        if (preg_match('/^(\d{8}|\d{4}-\d{2}-\d{2})$/', $v) === 1 && self::date($v) !== '') {
            return 'datum';
        }
        if (preg_match('#^(https?://|mailto:|tel:)\S+$#i', $v) === 1) {
            return 'link';
        }
        if (preg_match('/^-?\d{1,12}([.,]\d{1,6})?$/', $v) === 1) {
            return 'number';
        }
        if (preg_match('/<(p|br|ul|ol|li|strong|em|a|h[1-6]|div|span|table)\b/i', $v) === 1) {
            return 'html';
        }

        return str_contains($v, "\n") ? 'radky' : 'text';
    }

    /**
     * The type of a field from the votes of its values: HTML wins over lines, lines over text; otherwise the most frequent
     * type, where an empty value votes for nothing.
     *
     * @param array<string, int> $votes type => count
     */
    public static function fieldType(array $votes): string
    {
        foreach (['html', 'radky'] as $wins) {
            if (($votes[$wins] ?? 0) > 0) {
                return $wins;
            }
        }
        arsort($votes);

        return (string) (array_key_first($votes) ?? 'text');
    }

    /** ACF dates are stored as Ymd; we keep Y-m-d. '' = not a date. */
    public static function date(string $value): string
    {
        $v = trim($value);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m) === 1 || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
        }

        return '';
    }

    /** A readable label from a type or field name: team_member → Team member, cena-od → Cena od. */
    public static function label(string $name): string
    {
        $text = trim((string) preg_replace('/[_\-]+/', ' ', $name));

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * The first segment of the item's old address, e.g. https://old.cz/reference/kitchen/ → reference. Used as the address
     * of the collection, so that the items keep their addresses and need no redirect.
     */
    public static function prefix(string $link): string
    {
        $path = trim((string) parse_url($link, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);

        return count($segments) >= 2 ? slugify(rawurldecode($segments[count($segments) - 2]), 100) : '';
    }
}
