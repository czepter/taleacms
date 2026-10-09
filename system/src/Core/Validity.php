<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;

/**
 * True until and review by (2.10): content that is only true for a while hides itself, content that should be checked
 * asks for it. Pages, news items, collection items and pop-ups have two optional dates:
 *
 *  - valid_until: the day after it, the content hides itself – a page or an item gets zobrazit = 0, a news item
 *    visible = 0, a pop-up aktivni = 0. Every change goes to the change log (ChangeLog::write) and is recorded as the
 *    event content.expired; the page cache is cleared when something changed. The date stays, so the admin sees why.
 *  - review_by: on that day (and until the date is changed or removed) the site audit lists the content under
 *    "Review by" (Core\Audit) and the event content.review is recorded once per content and date.
 *
 * run() is the hourly background job (Core\Scheduler, 'validity'). The selection helpers are pure and unit-tested.
 */
final class Validity
{
    /** kind => [table, id column, title column, visibility column, change log module]. */
    public const array KINDS = [
        'page' => ['pages', 'page_id', 'title', 'visible', 'pages'],
        'news' => ['news', 'news_id', 'title', 'visible', 'news'],
        'collection_item' => ['collection_items', 'item_id', 'name', 'visible', 'collections'],
        'popup' => ['popups', 'popup_id', 'name', 'active', 'popups'],
    ];

    /** The hourly run: hides what expired and asks for the reviews that are due. Returns a short result for System status. */
    public static function run(App $app): string
    {
        $db = $app->db();
        $today = date('Y-m-d');
        $hidden = 0;
        $reviews = 0;
        $asked = self::askedReviews($db);
        foreach (self::KINDS as $kind => [$table, $idColumn, $titleColumn, $visibleColumn, $module]) {
            $rows = $db->all('SELECT ' . $idColumn . ' AS id, ' . $titleColumn . ' AS title, ' . $visibleColumn . ' AS visible, valid_until, review_by FROM {' . $table . '}'
                . ' WHERE (valid_until IS NOT NULL OR review_by IS NOT NULL)' . ($kind === 'popup' ? '' : ' AND deleted_at IS NULL'));
            foreach (self::expired($rows, $today) as $row) {
                $db->update($table, [$visibleColumn => 0], [$idColumn => (int) $row['id']]);
                ChangeLog::write($app, $module, 'expired', sprintf('%s – true until %s', (string) $row['title'], (string) $row['valid_until']));
                Events::record($db, 'content.expired', 'warning', t('“%s” was true until %s and hid itself.', (string) $row['title'], format_date((string) $row['valid_until'])), ['kind' => $kind, 'id' => (int) $row['id']]);
                $hidden++;
            }
            foreach (self::dueForReview($rows, $today, $asked[$kind] ?? []) as $row) {
                Events::record($db, 'content.review', 'warning', t('“%s” asks for a review by %s.', (string) $row['title'], format_date((string) $row['review_by'])),
                    ['kind' => $kind, 'id' => (int) $row['id'], 'review_by' => (string) $row['review_by']]);
                $reviews++;
            }
        }
        if ($hidden > 0) {
            \Kaleta\Front\Cache::clear();
        }

        return 'hidden ' . $hidden . ', reviews ' . $reviews;
    }

    /* ---------- selection (pure, unit-tested) ---------- */

    /**
     * Rows that are still visible although their valid_until day has passed (today is already after it).
     *
     * @param list<array<string, mixed>> $rows with visible, valid_until (YYYY-MM-DD or null)
     * @return list<array<string, mixed>>
     */
    public static function expired(array $rows, string $today): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => !empty($r['visible']) && is_string($r['valid_until'] ?? null) && $r['valid_until'] !== '' && $r['valid_until'] < $today));
    }

    /**
     * Rows whose review_by is today or earlier and that have not been asked yet for this date.
     *
     * @param list<array<string, mixed>> $rows with id, review_by (YYYY-MM-DD or null)
     * @param array<string, true> $asked keys "<id>|<review_by>" of the reviews already recorded (see askedReviews)
     * @return list<array<string, mixed>>
     */
    public static function dueForReview(array $rows, string $today, array $asked): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => is_string($r['review_by'] ?? null) && $r['review_by'] !== '' && $r['review_by'] <= $today
            && !isset($asked[(int) $r['id'] . '|' . $r['review_by']])));
    }

    /**
     * A date from a form or from Claude: YYYY-MM-DD (a datetime is cut to its day), null when empty or not a date.
     * Claude's input is checked with isDate() first, so a typo is an error there and not a silently dropped date.
     */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = substr(trim($value), 0, 10);

        return self::isDate($value) ? $value : null;
    }

    /** Whether a value is a real YYYY-MM-DD date (the 30th of February is not). */
    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* ---------- reading ---------- */

    /**
     * Reviews already recorded as events, by kind: kind => ["<id>|<review_by>" => true]. A changed review date asks again.
     *
     * @return array<string, array<string, true>>
     */
    private static function askedReviews(Db $db): array
    {
        $asked = [];
        foreach ($db->all("SELECT data FROM {events} WHERE type = 'content.review'") as $r) {
            $data = json_decode((string) $r['data'], true);
            if (is_array($data) && isset($data['kind'], $data['id'])) {
                $asked[(string) $data['kind']][(int) $data['id'] . '|' . (string) ($data['review_by'] ?? '')] = true;
            }
        }

        return $asked;
    }
}
