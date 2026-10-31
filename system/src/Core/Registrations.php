<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Front\FormMail;
use Talea\Front\Forms;

/**
 * Seats of a course (a non-repeating calendar item): who holds one, whether a new registration is placed, waits or goes on
 * the waiting list, and who moves up when a seat frees. The decisions are pure functions; taken() and promote() wrap them.
 *
 * The seats are shared by all items of the same collection with the same slug (language versions). Callers that change
 * seats lock with Db::lock(Registrations::lockFor($db, $itemId), 5) first; promote() runs under the caller's lock.
 */
final class Registrations
{
    public const array STATES = ['confirmed', 'waiting', 'waitlisted', 'declined', 'cancelled'];

    /** The states that hold seats. */
    public const array HOLDING = ['confirmed', 'waiting'];

    /**
     * The registration rules of a course item; null when the collection is not a calendar or the item repeats (then the
     * per-occurrence count of Calendar::registered() applies). Empty values mean defaults.
     *
     * @return array{mode: string, waiting_list: bool, max_seats: int, capacity: int, deadline: string, ends: string}|null
     */
    public static function course(array $collection, array $item): ?array
    {
        $fields = Calendar::fields($collection);
        if ($fields === null) {
            return null;
        }
        $data = (array) ($item['data'] ?? []);
        $get = fn (string $role): string => $fields[$role] !== '' ? trim((string) ($data[$fields[$role]] ?? '')) : '';
        if (isset(Calendar::REPEATS[$get('repeat')])) {
            return null;
        }
        $start = $get('start');

        return [
            'mode' => $get('registration') === 'confirm' ? 'confirm' : 'instant',
            'waiting_list' => $get('waiting_list') !== 'off',
            'max_seats' => max(1, (int) $get('seats_per_registration')),
            'capacity' => max(0, (int) $get('capacity')),
            'deadline' => $get('registration_until'),
            'ends' => $start !== '' ? Calendar::endsAt($start, $get('end')) : '9999-12-31 23:59',
        ];
    }

    /** confirmed | waiting | waitlisted | full | closed for a new registration of $seats seats ($now as "Y-m-d H:i"). */
    public static function decide(array $course, int $taken, int $seats, string $now): string
    {
        if (self::closed($course, $now)) {
            return 'closed';
        }
        $placed = $course['mode'] === 'confirm' ? 'waiting' : 'confirmed';
        if ($course['capacity'] <= 0 || $taken + $seats <= $course['capacity']) {
            return $placed;
        }

        return $course['waiting_list'] ? 'waitlisted' : 'full';
    }

    /** After the end, or after the deadline day (which lasts until 23:59). */
    public static function closed(array $course, string $now): bool
    {
        return $course['ends'] < $now || ($course['deadline'] !== '' && Calendar::endsAt($course['deadline'], '') < $now);
    }

    /**
     * What happens to a registration of $seats seats from $email: already (the address holds or waits for a seat in this course),
     * else the decision of decide(). $exclude is the enquiry being placed (it does not count against itself). Call under the lock.
     */
    public static function verdict(Db $db, array $course, int $itemId, string $email, int $seats, string $now, int $exclude = 0): string
    {
        if ($email !== '') {
            $ids = self::group($db, $itemId);
            $same = (int) $db->value("SELECT COUNT(*) FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state IN ('confirmed', 'waiting', 'waitlisted') AND enquiry_id <> ? AND LOWER(email) = ? AND item_id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')', [$exclude, strtolower($email), ...$ids]);
            if ($same > 0) {
                return 'already';
            }
        }

        return self::decide($course, self::taken($db, $itemId), $seats, $now);
    }

    /**
     * Gives a stored registration its state: confirmed | waiting | waitlisted is written; already | full | closed is returned and
     * nothing is written. The caller holds the lock and inserted the enquiry (with item_id and seats).
     */
    public static function place(App $app, array $collection, array $item, int $enquiryId, int $seats, string $email): string
    {
        $db = $app->db();
        $course = self::rules($db, $collection, $item);
        if ($course === null) {
            return 'closed';
        }
        $state = self::verdict($db, $course, (int) $item['item_id'], $email, $seats, date('Y-m-d H:i'), $enquiryId);
        if (in_array($state, ['confirmed', 'waiting', 'waitlisted'], true)) {
            $db->update('enquiries', ['seat_state' => $state, 'state_changed_at' => date('Y-m-d H:i:s')], ['enquiry_id' => $enquiryId]);
        }

        return $state;
    }

    /** The mail a new registration gets: registered (instant), request (confirm first) or waitlisted, in the language of the page. */
    public static function welcome(App $app, array $collection, array $item, string $state, string $to, string $language, string $name, int $seats): void
    {
        $id = ['confirmed' => 'course_registered', 'waiting' => 'course_request', 'waitlisted' => 'course_waitlisted'][$state] ?? null;
        if ($id === null || $to === '') {
            return;
        }
        Language::runWith($language !== '' ? $language : Language::defaults($app->settings()), fn () => Messages::send($app, $id, $language, $to, self::mailValues($collection, $item, $name, $seats)));
    }

    /**
     * The placeholders of the course mails, in the current language. The name is the registrant's, found by nameOf().
     *
     * @return array<string, string>
     */
    public static function mailValues(array $collection, array $item, string $name, int $seats): array
    {
        $data = (array) ($item['data'] ?? []);
        $fields = Calendar::fields($collection);

        return ['name' => $name, 'course' => (string) $item['name'], 'dates' => Calendar::when((string) ($data[$fields['start']] ?? ''), (string) ($data[$fields['end']] ?? '')),
            'schedule' => (string) ($data['schedule'] ?? ''), 'place' => (string) ($data[$fields['place']] ?? ''), 'seats' => (string) $seats];
    }

    /** The registrant's name: the stored answer (JSON) of a text field of the form that passes FormMail::isName; '' when there is none. */
    public static function nameOf(?array $element, string $answers): string
    {
        // the stored answers skip some fields, so the name is found by label, not by position
        $labels = [];
        foreach ((array) ($element['content']['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') === 'text') {
                $labels[] = (string) $field['label'];
            }
        }
        foreach ((json_decode($answers, true) ?: []) as $answer) {
            if (in_array((string) ($answer[0] ?? ''), $labels, true) && FormMail::isName((string) ($answer[1] ?? ''))) {
                return (string) $answer[1];
            }
        }

        return '';
    }

    /** The rules for a seat decision: those of the seat group's lowest item (the one lockFor() locks), so language versions cannot disagree. */
    public static function rules(Db $db, array $collection, array $item): ?array
    {
        $ids = self::group($db, (int) $item['item_id']);
        // the rules come from the lowest version that is not trashed (all trashed: the lowest of all)
        $live = $db->all('SELECT item_id FROM {collection_items} WHERE deleted_at IS NULL AND item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $low = $live !== [] ? min(array_map('intval', array_column($live, 'item_id'))) : min($ids);
        if ($low !== (int) $item['item_id'] && ($found = self::load($db, $low)) !== null) {
            $item = $found[1];
        }

        return self::course($collection, $item);
    }

    /** Ids of the items that share the seats of this one: same collection, same slug (language versions), trashed ones too (their registrations still hold seats). @return list<int> */
    public static function group(Db $db, int $itemId): array
    {
        $item = $db->one('SELECT collection_id, slug FROM {collection_items} WHERE item_id = ?', [$itemId]);
        if ($item === null) {
            return [$itemId];
        }
        $ids = array_map('intval', array_column($db->all('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND slug = ?', [(int) $item['collection_id'], $item['slug']]), 'item_id'));

        return $ids !== [] ? $ids : [$itemId];
    }

    /** Seats held (confirmed or waiting) by registrations of the item's group; trashed and spam rows hold none. */
    public static function taken(Db $db, int $itemId): int
    {
        $ids = self::group($db, $itemId);

        return (int) $db->value("SELECT COALESCE(SUM(seats), 0) FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state IN ('confirmed', 'waiting') AND item_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    /** Registrations of the item's group that count (not trashed, not spam), by state; the waiting list in the order it moves up. @return array<string, list<array<string, mixed>>> */
    public static function list(Db $db, int $itemId): array
    {
        $ids = self::group($db, $itemId);
        $rows = $db->all("SELECT * FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state IS NOT NULL AND seat_state <> '' AND item_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY state_changed_at IS NULL, state_changed_at, enquiry_id', $ids);
        $out = ['waiting' => [], 'confirmed' => [], 'waitlisted' => [], 'declined' => [], 'cancelled' => []];
        foreach ($rows as $row) {
            $out[(string) $row['seat_state']][] = $row;
        }
        // the others are newest first (waiting and confirmed by when they registered)
        foreach (['waiting', 'confirmed', 'declined', 'cancelled'] as $state) {
            usort($out[$state], fn (array $a, array $b): int => [$b['created_at'], $b['enquiry_id']] <=> [$a['created_at'], $a['enquiry_id']]);
        }

        return $out;
    }

    /** Seats taken, seats held by requests that wait for the holder, and registrations on the waiting list. @return array{taken: int, requested: int, waitlisted: int} */
    public static function counts(Db $db, int $itemId): array
    {
        $ids = self::group($db, $itemId);
        $row = $db->one("SELECT COALESCE(SUM(CASE WHEN seat_state IN ('confirmed', 'waiting') THEN seats ELSE 0 END), 0) AS taken, COALESCE(SUM(CASE WHEN seat_state = 'waiting' THEN seats ELSE 0 END), 0) AS requested,
            COALESCE(SUM(CASE WHEN seat_state = 'waitlisted' THEN 1 ELSE 0 END), 0) AS waitlisted FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND item_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);

        return ['taken' => (int) $row['taken'], 'requested' => (int) $row['requested'], 'waitlisted' => (int) $row['waitlisted']];
    }

    /** The status kind (admin/list/status) and label of a registration state. @return array{0: string, 1: string} */
    public static function stateBadge(string $state): array
    {
        return match ($state) {
            'confirmed' => ['live', t('Confirmed')],
            'waiting' => ['review', t('Waiting')],
            'waitlisted' => ['planned', t('Waiting list')],
            'declined' => ['off', t('Declined')],
            'cancelled' => ['off', t('Cancelled')],
            default => ['off', ''],
        };
    }

    /**
     * The waitlisted registration that moves up: the first in order whose seats fit into $free, null when none does.
     *
     * @param list<array{enquiry_id: int, seats: int}> $waitlist
     */
    public static function nextToPromote(array $waitlist, int $free): ?int
    {
        foreach ($waitlist as $w) {
            if ($w['seats'] <= $free) {
                return $w['enquiry_id'];
            }
        }

        return null;
    }

    public static function lockName(int $itemId): string
    {
        return 'talea-seat-' . $itemId;
    }

    /** The lock name for the whole seat group of an item (all language versions share it). */
    public static function lockFor(Db $db, int $itemId): string
    {
        return self::lockName(min(self::group($db, $itemId)));
    }

    /**
     * Moves waitlisted registrations up while seats are free and registration is open; returns how many. Instant: confirmed
     * and the visitor is told (course_moved_up). Confirm-first: waiting, the visitor is told and the form's recipients are
     * notified as for a new request. The caller holds the lock. With $mails the mails are not sent but collected as closures:
     * send them with send() after unlocking (SMTP is slow and the lock must not wait for it).
     *
     * @param list<\Closure>|null $mails
     */
    public static function promote(App $app, array $collection, array $item, string $now, ?array &$mails = null): int
    {
        $db = $app->db();
        $course = self::rules($db, $collection, $item);
        if ($course === null || self::closed($course, $now)) {
            return 0;
        }
        $ids = self::group($db, (int) $item['item_id']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $moved = 0;
        while (true) {
            $free = $course['capacity'] > 0 ? $course['capacity'] - self::taken($db, (int) $item['item_id']) : PHP_INT_MAX;
            if ($free <= 0) {
                break;
            }
            $rows = $db->all("SELECT * FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state = 'waitlisted' AND item_id IN ($in) ORDER BY state_changed_at IS NULL, state_changed_at, enquiry_id", $ids);
            $id = self::nextToPromote(array_map(fn (array $r): array => ['enquiry_id' => (int) $r['enquiry_id'], 'seats' => (int) $r['seats']], $rows), $free);
            if ($id === null) {
                break;
            }
            $row = $rows[array_search($id, array_column($rows, 'enquiry_id'), false)];
            $db->update('enquiries', ['seat_state' => $course['mode'] === 'confirm' ? 'waiting' : 'confirmed', 'state_changed_at' => date('Y-m-d H:i:s')]
                + ($course['mode'] === 'confirm' ? ['reminded_at' => null] : []), ['enquiry_id' => $id]);
            $moved++;
            self::tell($app, $collection, $item, $course, $row, true, $mails);
        }

        return $moved;
    }

    /**
     * The holder's decision on one registration: confirm | decline (waiting), cancel (confirmed, waiting or waitlisted; this is also
     * "remove from the waiting list"), promote (waitlisted, by hand, when its seats are free). The visitor gets the mail of the
     * new state ($message is the holder's own text for decline and cancel); a freed seat moves the waiting list up. Mails are sent
     * after the seat lock is released.
     *
     * @throws \DomainException with a translated reason when the action does not apply
     */
    public static function transition(App $app, int $enquiryId, string $action, string $message = ''): void
    {
        $db = $app->db();
        $row = $db->one('SELECT * FROM {enquiries} WHERE enquiry_id = ?', [$enquiryId]);
        if ($row === null || (int) $row['item_id'] <= 0 || ($found = self::load($db, (int) $row['item_id'])) === null) {
            throw new \DomainException(t('This enquiry is not a course registration.'));
        }
        [$collection, $item] = $found;
        if (($course = self::rules($db, $collection, $item)) === null) {
            throw new \DomainException(t('This is not a course with seats.'));
        }
        $lock = self::lockFor($db, (int) $item['item_id']);
        if (!$db->lock($lock, 5)) {
            throw new \DomainException(t('The seats are being changed right now. Please try again in a moment.'));
        }
        $mails = [];
        try {
            $row = $db->one('SELECT * FROM {enquiries} WHERE enquiry_id = ?', [$enquiryId]);
            $from = (string) ($row['seat_state'] ?? '');
            if ($row === null || $row['deleted_at'] !== null || $row['category'] === 'spam') {
                throw new \DomainException(t('This registration is in the trash or marked as spam.'));
            }
            $to = match ($action) {
                'confirm' => $from === 'waiting' ? 'confirmed' : null,
                'decline' => $from === 'waiting' ? 'declined' : null,
                'cancel' => in_array($from, ['confirmed', 'waiting', 'waitlisted'], true) ? 'cancelled' : null,
                'promote' => $from === 'waitlisted' ? ($course['mode'] === 'confirm' ? 'waiting' : 'confirmed') : null,
                default => null,
            };
            if ($to === null) {
                throw new \DomainException(t('This action does not apply to a registration in its current state.'));
            }
            if ($action === 'promote' && $course['capacity'] > 0 && self::taken($db, (int) $item['item_id']) + (int) $row['seats'] > $course['capacity']) {
                throw new \DomainException(t('There is no free seat for this registration.'));
            }
            $db->update('enquiries', ['seat_state' => $to, 'state_changed_at' => date('Y-m-d H:i:s')] + ($to === 'waiting' ? ['reminded_at' => null] : []), ['enquiry_id' => $enquiryId]);
            if ($action === 'promote') {
                self::tell($app, $collection, $item, $course, $row, false, $mails); // the holder did it by hand: no notification to the holder
            } elseif ((string) $row['email'] !== '') {
                $id = ['confirm' => 'course_confirmed', 'decline' => 'course_declined', 'cancel' => 'course_cancelled'][$action];
                $name = self::nameOf(Forms::findElement($db, (string) $row['source'], (string) $row['element'], 'form'), (string) $row['data']);
                $language = (string) $row['language'];
                $mails[] = fn () => Language::runWith($language !== '' ? $language : Language::defaults($app->settings()), fn () => Messages::send($app, $id, $language, (string) $row['email'],
                    self::mailValues($collection, $item, $name, (int) $row['seats']) + ['message' => $message]));
            }
            self::promote($app, $collection, $item, date('Y-m-d H:i'), $mails);
        } finally {
            $db->unlock($lock);
        }
        self::send($mails);
    }

    /**
     * After registrations were marked spam, un-spammed, trashed or restored (the caller passes only rows whose flag changed):
     * a seat that freed moves the waiting list up; holding rows that came back and no longer fit (oldest first) go to the end
     * of the waiting list (cancelled when the list is off). Never overbooks; no mail except a promotion's. Never throws: a
     * group whose lock cannot be had is skipped and logged (the flag change is already stored).
     *
     * @param list<int> $ids
     */
    public static function sync(App $app, array $ids): void
    {
        $db = $app->db();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $groups = [];
        foreach ($db->all("SELECT enquiry_id, item_id FROM {enquiries} WHERE item_id IS NOT NULL AND seat_state IN ('confirmed', 'waiting', 'waitlisted') AND enquiry_id IN (" . implode(',', $ids) . ')') as $r) {
            $groups[self::lockFor($db, (int) $r['item_id'])][(int) $r['item_id']][] = (int) $r['enquiry_id'];
        }
        foreach ($groups as $lock => $items) {
            $itemId = (int) array_key_first($items);
            if (($found = self::load($db, $itemId)) === null || ($course = self::rules($db, $found[0], $found[1])) === null) {
                continue;
            }
            if (!$db->lock($lock, 5)) {
                error_log('Talea: seats of course item ' . $itemId . ' were not settled after a registration changed (lock busy)');
                continue;
            }
            $mails = [];
            try {
                $back = $db->all("SELECT enquiry_id, seats FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state IN ('confirmed', 'waiting') AND enquiry_id IN ("
                    . implode(',', array_merge(...array_values($items))) . ') ORDER BY state_changed_at IS NULL, state_changed_at, enquiry_id');
                $held = self::taken($db, $itemId) - (int) array_sum(array_column($back, 'seats')); // what stays, without the rows being fitted
                foreach ($back as $r) {
                    if ($course['capacity'] > 0 && $held + (int) $r['seats'] > $course['capacity']) {
                        $db->update('enquiries', ['seat_state' => $course['waiting_list'] ? 'waitlisted' : 'cancelled', 'state_changed_at' => date('Y-m-d H:i:s')], ['enquiry_id' => (int) $r['enquiry_id']]);
                    } else {
                        $held += (int) $r['seats'];
                    }
                }
                self::promote($app, $found[0], $found[1], date('Y-m-d H:i'), $mails);
            } catch (\Throwable $e) {
                error_log('Talea: seats of course item ' . $itemId . ' were not settled: ' . $e->getMessage());
            } finally {
                $db->unlock($lock);
            }
            self::send($mails);
        }
    }

    /** The items whose seats these enquiries hold now (live, not spam, confirmed or waiting): collect before deleting them, hand to refill() after. @param list<int> $ids @return list<int> */
    public static function heldItems(Db $db, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_column($db->all("SELECT item_id FROM {enquiries} WHERE item_id IS NOT NULL AND deleted_at IS NULL AND category <> 'spam' AND seat_state IN ('confirmed', 'waiting') AND enquiry_id IN ("
            . implode(',', $ids) . ')'), 'item_id'))));
    }

    /** Moves the waiting list up in the seat groups of these items after registrations were deleted for good. Never throws. @param list<int> $itemIds */
    public static function refill(App $app, array $itemIds): void
    {
        $db = $app->db();
        $groups = [];
        foreach ($itemIds as $itemId) {
            $groups[self::lockFor($db, (int) $itemId)] ??= (int) $itemId;
        }
        foreach ($groups as $lock => $itemId) {
            if (($found = self::load($db, $itemId)) === null) {
                continue;
            }
            if (!$db->lock($lock, 5)) {
                error_log('Talea: the waiting list of course item ' . $itemId . ' was not moved up (lock busy)');
                continue;
            }
            $mails = [];
            try {
                self::promote($app, $found[0], $found[1], date('Y-m-d H:i'), $mails);
            } catch (\Throwable $e) {
                error_log('Talea: the waiting list of course item ' . $itemId . ' was not moved up: ' . $e->getMessage());
            } finally {
                $db->unlock($lock);
            }
            self::send($mails);
        }
    }

    /** Sends mails collected under a lock (after it was released); a failing mail is logged and does not stop the others. @param list<\Closure> $mails */
    public static function send(array $mails): void
    {
        foreach ($mails as $mail) {
            try {
                $mail();
            } catch (\Throwable $e) {
                error_log('Talea: a course mail was not sent: ' . $e->getMessage());
            }
        }
    }

    /** How many other courses a cancelled course may offer. */
    public const int MAX_OFFERS = 3;

    /** How long the take-over links of a cancelled course work. */
    private const int OFFER_DAYS = 30;

    /**
     * The other courses of the collection (default language: a registrant gets the counterpart in their own language, see counterpart())
     * that are visible and still open for registration (the language versions of the course itself are left out). With $ids: those of them that were picked, in that order, at most MAX_OFFERS.
     *
     * @param list<int>|null $ids
     * @return list<array<string, mixed>> items, data decoded
     */
    public static function offerable(Db $db, array $collection, array $item, ?array $ids = null): array
    {
        $group = self::group($db, (int) $item['item_id']);
        $now = date('Y-m-d H:i');
        $open = [];
        foreach ($db->all('SELECT * FROM {collection_items} WHERE collection_id = ? AND language = \'\' AND visible = TRUE AND deleted_at IS NULL ORDER BY name, item_id', [(int) $collection['collection_id']]) as $row) {
            $row['data'] = json_decode((string) $row['data'], true) ?: [];
            $course = in_array((int) $row['item_id'], $group, true) ? null : self::course($collection, $row);
            if ($course !== null && !self::closed($course, $now)) {
                $open[(int) $row['item_id']] = $row;
            }
        }
        if ($ids === null) {
            return array_values($open);
        }
        $picked = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (isset($open[$id])) {
                $picked[] = $open[$id];
            }
        }

        return array_slice($picked, 0, self::MAX_OFFERS);
    }

    /**
     * The version of an offered course in a language: the item of the same collection and slug (its seat group) in that language,
     * else the offered item itself. Data decoded.
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private static function counterpart(Db $db, array $offer, string $language): array
    {
        if ($language !== '' && (string) $offer['language'] !== $language) {
            $row = $db->one('SELECT * FROM {collection_items} WHERE collection_id = ? AND slug = ? AND language = ? AND visible = TRUE AND deleted_at IS NULL', [(int) $offer['collection_id'], $offer['slug'], $language]);
            if ($row !== null) {
                $row['data'] = json_decode((string) $row['data'], true) ?: [];

                return $row;
            }
        }

        return $offer;
    }

    /**
     * Cancels a whole course: every confirmed, waiting or waitlisted registration of it (all language versions) becomes
     * cancelled and carries a single-use take-over token for each offered course, the course is hidden and its addresses
     * redirect to the first offered course (else to the collection list), and each registration with an address gets the mail
     * course_course_cancelled. The state changes are one transaction; mails go out after it and after the seat lock. Returns
     * the number of mails. Cannot be undone.
     *
     * @param list<int> $offerItemIds other courses of the collection (offerable() keeps the open ones, at most 3)
     * @throws \DomainException with a translated reason
     */
    public static function cancelCourse(App $app, array $collection, array $item, array $offerItemIds, string $message): int
    {
        $db = $app->db();
        if ($db->pdo()->inTransaction()) {
            throw new \LogicException('cancelCourse() sends mails and must not run inside an open transaction.');
        }
        if (self::course($collection, $item) === null) {
            throw new \DomainException(t('This is not a course with seats.'));
        }
        $group = self::group($db, (int) $item['item_id']);
        $in = implode(',', array_fill(0, count($group), '?'));
        $offers = self::offerable($db, $collection, $item, $offerItemIds);
        $lock = self::lockFor($db, (int) $item['item_id']);
        if (!$db->lock($lock, 5)) {
            throw new \DomainException(t('The seats are being changed right now. Please try again in a moment.'));
        }
        $mails = [];
        try {
            $rows = $db->all("SELECT * FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state IN ('confirmed', 'waiting', 'waitlisted') AND item_id IN ($in) ORDER BY enquiry_id", $group);
            $offerJson = $offers !== [] ? (string) json_encode(array_map(fn (array $o): int => (int) $o['item_id'], $offers)) : null;
            $until = date('Y-m-d H:i:s', time() + self::OFFER_DAYS * 86400);
            $tokens = [];
            $write = function (Db $db) use ($rows, $offerJson, $until, $group, $in, $collection, $offers, &$tokens): void {
                foreach ($rows as $row) {
                    $tokens[(int) $row['enquiry_id']] = bin2hex(random_bytes(32)); // only its sha256 is stored
                    $db->update('enquiries', ['seat_state' => 'cancelled', 'state_changed_at' => date('Y-m-d H:i:s'), 'offer_token_hash' => $offerJson !== null ? hash('sha256', $tokens[(int) $row['enquiry_id']]) : null,
                        'offer_items' => $offerJson, 'offer_until' => $offerJson !== null ? $until : null], ['enquiry_id' => $row['enquiry_id']]);
                }
                $db->run("UPDATE {collection_items} SET visible = FALSE WHERE item_id IN ($in)", $group);
                if (!empty($collection['detail'])) {
                    foreach ($db->all("SELECT slug, language FROM {collection_items} WHERE item_id IN ($in)", $group) as $version) {
                        $first = $offers !== [] ? self::counterpart($db, $offers[0], (string) $version['language']) : null; // the first offer in the language of this version
                        $to = $first !== null ? [$collection['slug'] . '/' . $first['slug'], (string) $first['language']] : [(string) $collection['slug'], (string) $version['language']];
                        \Talea\Admin\Modules\Redirects::add($db, Slug::redirectPath($collection['slug'] . '/' . $version['slug'], (string) $version['language']), Slug::redirectPath($to[0], $to[1]));
                    }
                }
            };
            $db->transaction($write);
            foreach ($rows as $row) {
                if ((string) $row['email'] === '') {
                    continue;
                }
                $name = self::nameOf(Forms::findElement($db, (string) $row['source'], (string) $row['element'], 'form'), (string) $row['data']);
                $language = (string) $row['language'];
                $token = $tokens[(int) $row['enquiry_id']];
                $mails[] = fn () => Language::runWith($language !== '' ? $language : Language::defaults($app->settings()), fn () => Messages::send($app, 'course_course_cancelled', $language, (string) $row['email'],
                    self::mailValues($collection, $item, $name, (int) $row['seats']) + ['message' => $message, 'take-over links' => self::offerLines($app, $collection, $offers, $token, $language)]));
            }
        } finally {
            $db->unlock($lock);
        }
        self::send($mails);

        return count($mails);
    }

    /** The {take-over links} of the cancellation mail: per offered course its name, schedule and start, then the personal link. '' without offers. @param list<array<string, mixed>> $offers */
    private static function offerLines(App $app, array $collection, array $offers, string $token, string $language): string
    {
        $fields = Calendar::fields($collection);
        $s = $app->settings();
        $site = rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/');
        $lines = [];
        $prefix = $language !== '' && $language !== Language::defaults($s) ? $language . '/' : ''; // the registrant's language version of the site
        foreach ($offers as $n => $offer) {
            $offer = self::counterpart($app->db(), $offer, $language);
            $start = trim((string) ($offer['data'][$fields['start']] ?? ''));
            $title = implode(' · ', array_filter([(string) $offer['name'], trim((string) ($offer['data']['schedule'] ?? '')), $start !== '' ? t('from %s', \Talea\Builder\Collections::formatDateTime($start)) : '']));
            $lines[] = $title . "\n" . t('Take a seat: %s', $site . $app->url($prefix . '_course/' . $token . '/' . ($n + 1)));
        }

        return implode("\n\n", $lines);
    }

    /**
     * The page behind a take-over link of a cancelled course: GET shows the offered course and a button (a mail client that
     * prefetches links must not register anyone), POST registers a new participant there with the details of the cancelled
     * registration, under that course's rules and lock (no priority over its waiting list). The link works once for one of its
     * offered courses. A link that no longer works says so with status 200: it comes from a mail.
     *
     * @return array{0: string, 1: string, 2: int} heading, content HTML, HTTP status
     */
    public static function takeOverPage(App $app, string $token, int $n, bool $post): array
    {
        $db = $app->db();
        $hash = hash('sha256', $token);
        $row = $db->one('SELECT * FROM {enquiries} WHERE offer_token_hash = ?', [$hash]);
        $offered = $row !== null ? self::load($db, (int) $row['item_id']) : null; // also for a dead link: the page leads to the course list
        $live = $row !== null && $row['offer_until'] !== null && (string) $row['offer_until'] > date('Y-m-d H:i:s');
        $gone = function () use ($app, $offered): array {
            $list = $offered !== null ? $app->url((string) $offered[0]['slug']) : $app->url('');

            return [t('Take a seat'), '<p>' . e(t('This link no longer works: registration for this course has closed, or you have already used one of the links.')) . '</p><p><a href="' . e($list) . '">' . e(t('See all courses')) . '</a></p>', 200];
        };
        $ids = $row !== null ? (array) json_decode((string) $row['offer_items'], true) : [];
        $target = isset($ids[$n - 1]) && $offered !== null ? self::load($db, (int) $ids[$n - 1]) : null;
        if (!$live || $target === null || !self::open($target[0], $target[1], (int) $offered[1]['item_id'])) {
            return $gone();
        }
        [$collection, $item] = $target;
        $element = Forms::findElement($db, (string) $row['source'], (string) $row['element'], 'form');
        $name = self::nameOf($element, (string) $row['data']);
        $seats = max(1, (int) $row['seats']);
        $language = (string) $row['language'];
        $item = self::counterpart($db, $item, $language); // the offered course in the language of the cancelled registration
        $page = fn (string $id): string => Messages::text($app->settings(), $id, 'body', Language::siteColumn());
        if (!$post) {
            $values = self::mailValues($collection, $item, $name, $seats);
            $who = implode(' · ', array_filter([$name, $seats === 1 ? t('1 person') : t('%d people', $seats)]));

            return [t('Take a seat'), '<p><strong>' . e($item['name']) . '</strong><br>' . e(implode(' · ', array_filter([$values['dates'], $values['schedule']]))) . '</p><p>' . e($who) . '</p><p>' . e($page('course_take_over')) . '</p>'
                . '<form method="post" action="' . e($app->url('_course/' . $token . '/' . $n)) . '"><p><button class="tl-button tl-button--primary" type="submit">' . e($seats === 1 ? t('Take a seat') : t('Take %d seats', $seats)) . '</button></p></form>', 200];
        }
        $lock = self::lockFor($db, (int) $item['item_id']);
        if (!$db->lock($lock, 5)) {
            return [t('Take a seat'), '<p>' . e(t('Many people are registering right now. Please send the form again.')) . '</p>', 200];
        }
        $mails = [];
        $state = '';
        try {
            $course = self::rules($db, $collection, $item);
            $verdict = self::verdict($db, $course, (int) $item['item_id'], (string) $row['email'], $seats, date('Y-m-d H:i'));
            // an address that is already registered gets the answer a new one would get (no oracle): its state is decided here, nothing is stored
            $decision = $verdict === 'already' ? self::decide($course, self::taken($db, (int) $item['item_id']), $seats, date('Y-m-d H:i')) : $verdict;
            if (in_array($decision, ['full', 'closed'], true)) {
                return $decision === 'closed' ? $gone() : [t('Take a seat'), '<p>' . e($page('course_full')) . '</p><p><a href="' . e($app->url((string) $collection['slug'])) . '">' . e(t('See all courses')) . '</a></p>', 200]; // nothing claimed: another course may still be taken
            }
            $values = self::mailValues($collection, $item, $name, $seats);
            $claimed = $db->transaction(function (Db $db) use ($hash, $verdict, $row, $collection, $item, $seats, $language, $app, &$state, &$mails, $name, $element): bool {
                // the first press wins, and it kills the links for every other offered course (the hash stays: a dead link still finds its course list)
                if ($db->run('UPDATE {enquiries} SET offer_until = NULL WHERE offer_token_hash = ? AND offer_until > NOW()', [$hash])->rowCount() !== 1) {
                    return false;
                }
                if ($verdict === 'already') {
                    return true;
                }
                $id = $db->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'form' => (string) $row['form'], 'source' => (string) $row['source'], 'element' => (string) $row['element'], 'page' => self::pathOf($collection, $item),
                    'email' => (string) $row['email'], 'data' => (string) $row['data'], 'status' => 0, 'item_id' => (int) $item['item_id'], 'seats' => $seats, 'language' => $language]);
                $state = self::place($app, $collection, $item, $id, $seats, (string) $row['email']);
                $mails[] = fn () => self::welcome($app, $collection, $item, $state, (string) $row['email'], $language, $name, $seats);
                if ($element !== null) { // the holder hears of it as of any registration; a request says so
                    $mails[] = fn () => Language::runWith(Language::defaults($app->settings()), fn () => Forms::notifyHolder($app, $id, $element, (array) json_decode((string) $row['data'], true), (string) $row['email'], '', '', [],
                        $state === 'waiting' ? t('Request:') . ' ' : ''));
                }

                return true;
            });
            if (!$claimed) {
                return $gone();
            }
            if ($verdict === 'already') {
                $state = $decision;
                $mails[] = fn () => Language::runWith($language !== '' ? $language : Language::defaults($app->settings()), fn () => Messages::send($app, 'course_already', $language, (string) $row['email'], $values));
            }
        } finally {
            $db->unlock($lock);
        }
        self::send($mails);
        [$heading, $id] = match ($state) {
            'confirmed' => [t('You are registered'), 'course_thanks_registered'],
            'waiting' => [t('We have your request'), 'course_thanks_request'],
            'waitlisted' => [t('You are on the waiting list'), 'course_thanks_waitlisted'],
            default => [null, ''],
        };

        return $heading !== null ? [$heading, '<div role="status"><p>' . e($page($id)) . '</p></div>', 200] : $gone();
    }

    /** Is the offered course visible, with seats and still open, and not the cancelled one? @param array<string, mixed> $collection @param array<string, mixed> $item */
    private static function open(array $collection, array $item, int $cancelledItemId): bool
    {
        $course = self::course($collection, $item);

        return $course !== null && (bool) $item['visible'] && $item['deleted_at'] === null && (int) $item['item_id'] !== $cancelledItemId && !self::closed($course, date('Y-m-d H:i'));
    }

    /** The public path of a course item, as the registration form stores it in `page`. */
    private static function pathOf(array $collection, array $item): string
    {
        return mb_substr('/' . $collection['slug'] . '/' . $item['slug'], 0, 255);
    }

    /** The collection and item (data decoded) of an item id; null when either is gone. @return array{0: array, 1: array}|null */
    private static function load(Db $db, int $itemId): ?array
    {
        $item = $db->one('SELECT * FROM {collection_items} WHERE item_id = ?', [$itemId]);
        $collection = $item !== null ? \Talea\Builder\Collections::byId($db, (int) $item['collection_id']) : null;
        if ($collection === null) {
            return null;
        }
        $item['data'] = json_decode((string) $item['data'], true) ?: [];

        return [$collection, $item];
    }

    /**
     * The visitor's mail (request received for confirm-first, moved up for instant) and, for confirm-first, the holder's.
     *
     * @param list<\Closure>|null $mails
     */
    private static function tell(App $app, array $collection, array $item, array $course, array $row, bool $holder, ?array &$mails): void
    {
        $element = Forms::findElement($app->db(), (string) $row['source'], (string) $row['element'], 'form');
        $name = self::nameOf($element, (string) $row['data']);
        $s = $app->settings();
        $language = (string) $row['language'];
        $send = function (\Closure $mail) use (&$mails): void {
            if ($mails === null) {
                $mail();
            } else {
                $mails[] = $mail;
            }
        };
        if ((string) $row['email'] !== '') {
            $send(fn () => Language::runWith($language !== '' ? $language : Language::defaults($s), fn () => Messages::send($app, $course['mode'] === 'confirm' ? 'course_request' : 'course_moved_up', $language, (string) $row['email'],
                self::mailValues($collection, $item, $name, (int) $row['seats']))));
        }
        $recipients = $holder && $course['mode'] === 'confirm' ? self::recipients($app, $element) : null;
        if ($recipients !== null) {
            [$to, $bcc] = $recipients;
            $send(fn () => Language::runWith(Language::defaults($s), function () use ($s, $item, $row, $to, $bcc): void {
                $text = t('A seat is free and a registration moved up from the waiting list. Please confirm or decline it in the administration.')
                    . "\n\n" . $item['name'] . "\n" . t('Seats') . ': ' . $row['seats'];
                Mail::send($s, implode(', ', $to), FormMail::subject(t('Request:') . ' ' . $item['name']), $text, '', $bcc !== [] ? ['Bcc' => implode(', ', $bcc)] : []);
            }));
        }
    }

    /** The recipients of a registration form (To, Bcc); null when the form is gone or has none. @return array{0: list<string>, 1: list<string>}|null */
    private static function recipients(App $app, ?array $element): ?array
    {
        if ($element === null) {
            return null;
        }
        [$to, $bcc] = Forms::recipients((string) $element['content']['recipient'], (string) ($element['content']['bcc'] ?? ''), $app->settings()->get('site_email'));

        return $to !== [] ? [$to, $bcc] : null;
    }

    /**
     * The hourly job (Core\Scheduler): a request nobody answered for 3 days reminds the course holder once. One mail per course
     * lists how many wait; nothing is declined. Rows are marked first (reminded_at), so two runs never send twice.
     */
    public static function remind(App $app): string
    {
        $db = $app->db();
        $rows = $db->all("SELECT enquiry_id, item_id, source, element FROM {enquiries} WHERE seat_state = 'waiting' AND item_id IS NOT NULL AND reminded_at IS NULL"
            . " AND state_changed_at < NOW() - INTERVAL 3 DAY AND deleted_at IS NULL AND category <> 'spam' ORDER BY item_id, state_changed_at LIMIT 50");
        $marked = [];
        foreach ($rows as $r) {
            if ($db->run('UPDATE {enquiries} SET reminded_at = NOW() WHERE enquiry_id = ? AND reminded_at IS NULL', [(int) $r['enquiry_id']])->rowCount() > 0) {
                $marked[(int) $r['item_id']][] = $r;
            }
        }
        $sent = 0;
        $s = $app->settings();
        foreach ($marked as $itemId => $waiting) {
            $loaded = self::load($db, $itemId);
            $course = $loaded !== null ? self::course(...$loaded) : null;
            if ($course === null || $course['ends'] < date('Y-m-d H:i')) {
                continue;
            }
            [$collection, $item] = $loaded;
            $recipients = self::recipients($app, Forms::findElement($db, (string) $waiting[0]['source'], (string) $waiting[0]['element'], 'form'));
            if ($recipients === null) {
                continue;
            }
            [$to, $bcc] = $recipients;
            $url = rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/') . $app->url('admin.php?module=collections&action=registrations&id='
                . $db->publicId('collections', (int) $collection['collection_id']) . '&item=' . $db->publicId('collection_items', $itemId));
            Language::runWith(Language::defaults($s), function () use ($s, $item, $waiting, $to, $bcc, $url): void {
                $text = t('%d requests for this course have not been answered for 3 days. Please confirm or decline them in the administration.', count($waiting))
                    . "\n\n" . $item['name'] . "\n\n—\n" . t('Registrations in the administration: %s', $url);
                Mail::send($s, implode(', ', $to), FormMail::subject(t('Unanswered requests: %s', (string) $item['name'])), $text, '', $bcc !== [] ? ['Bcc' => implode(', ', $bcc)] : []);
            });
            $sent++;
        }

        return $sent . ' reminders';
    }
}
