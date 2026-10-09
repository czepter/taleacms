<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Builder\Collections;
use Kaleta\Core\Db;

/**
 * What an enquiry was about (2.12, ka_poptavky.tema): the collection and item of a service, product or event page, the
 * title of an ordinary page, the name of a pop-up – so whoever reads the enquiry knows without the visitor typing it.
 * It is looked up on the server from the form's source and the address the form came back to; nothing posted is trusted.
 */
final class EnquiryTopic
{
    /** Length of ka_poptavky.tema. */
    private const int MAX = 255;

    /** Looks the topic up for a submission; '' for a site part (header, footer) or when the source is unknown. */
    public static function find(Db $db, string $source, string $back): string
    {
        if (preg_match('/^page:(\d+)$/', $source, $m)) {
            return self::compose((string) $db->value('SELECT title FROM {pages} WHERE page_id = ?', [(int) $m[1]]));
        }
        if (preg_match('/^popup:(\d+)$/', $source, $m)) {
            return self::compose((string) $db->value('SELECT name FROM {popups} WHERE popup_id = ?', [(int) $m[1]]));
        }
        if (preg_match('/^collection:(\d+)$/', $source, $m)) {
            $collection = Collections::byId($db, (int) $m[1]);
            $slug = $collection === null ? null : self::itemSlug((string) $collection['slug'], $back);
            if ($collection === null || $slug === null) {
                return '';
            }
            // the item name, also of a hidden one: the form may have been sent a moment before the item was hidden
            $item = (string) $db->value('SELECT name FROM {collection_items} WHERE collection_id = ? AND slug = ? AND deleted_at IS NULL LIMIT 1', [(int) $collection['collection_id'], $slug]);

            return self::compose((string) $collection['name'], $item);
        }

        return '';
    }

    /**
     * The item slug from the address of a collection item page (/<collection>/<item>, with a language prefix or a query
     * string as well – the same reading as Core\Calendar::stateForSubmission); null when the address is not an item page
     * of that collection.
     */
    public static function itemSlug(string $collectionSlug, string $back): ?string
    {
        return $collectionSlug !== '' && preg_match('#/' . preg_quote($collectionSlug, '#') . '/([a-z0-9-]{1,160})/?(?:\?.*)?$#', $back, $m) === 1 ? $m[1] : null;
    }

    /** "Services – Bathroom renovation"; only the first part when the second is empty; cut to the column length. */
    public static function compose(string $where, string $what = ''): string
    {
        $parts = array_filter([trim($where), trim($what)], fn (string $p): bool => $p !== '');

        return mb_substr(implode(' – ', $parts), 0, self::MAX);
    }
}
