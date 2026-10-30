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
        $course = self::course($collection, $item);
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

    /** Ids of the items that share the seats of this one: same collection, same slug (language versions). @return list<int> */
    public static function group(Db $db, int $itemId): array
    {
        $item = $db->one('SELECT collection_id, slug FROM {collection_items} WHERE item_id = ?', [$itemId]);
        if ($item === null) {
            return [$itemId];
        }
        $ids = array_map('intval', array_column($db->all('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND slug = ? AND deleted_at IS NULL', [(int) $item['collection_id'], $item['slug']]), 'item_id'));

        return $ids !== [] ? $ids : [$itemId];
    }

    /** Seats held (confirmed or waiting) by registrations of the item's group; trashed and spam rows hold none. */
    public static function taken(Db $db, int $itemId): int
    {
        $ids = self::group($db, $itemId);

        return (int) $db->value("SELECT COALESCE(SUM(seats), 0) FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state IN ('confirmed', 'waiting') AND item_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
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
     * notified as for a new request. The caller holds the lock.
     */
    public static function promote(App $app, array $collection, array $item, string $now): int
    {
        $db = $app->db();
        $course = self::course($collection, $item);
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
            $db->update('enquiries', ['seat_state' => $course['mode'] === 'confirm' ? 'waiting' : 'confirmed', 'state_changed_at' => date('Y-m-d H:i:s')], ['enquiry_id' => $id]);
            $moved++;
            self::tell($app, $collection, $item, $course, $row);
        }

        return $moved;
    }

    /**
     * The holder's decision on one registration: confirm | decline (waiting), cancel (confirmed, waiting or waitlisted; this is also
     * "remove from the waiting list"), promote (waitlisted, by hand, when its seats are free). The visitor gets the mail of the
     * new state ($message is the holder's own text for decline and cancel); a freed seat moves the waiting list up.
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
        $course = self::course($collection, $item);
        $lock = self::lockFor($db, (int) $item['item_id']);
        if ($course === null || !$db->lock($lock, 5)) {
            throw new \DomainException(t('The seats are being changed right now. Please try again in a moment.'));
        }
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
            $db->update('enquiries', ['seat_state' => $to, 'state_changed_at' => date('Y-m-d H:i:s')], ['enquiry_id' => $enquiryId]);
            if ($action === 'promote') {
                self::tell($app, $collection, $item, $course, $row);
            } else {
                $id = ['confirm' => 'course_confirmed', 'decline' => 'course_declined', 'cancel' => 'course_cancelled'][$action];
                $element = Forms::findElement($db, (string) $row['source'], (string) $row['element'], 'form');
                $language = (string) $row['language'];
                if ((string) $row['email'] !== '') {
                    Language::runWith($language !== '' ? $language : Language::defaults($app->settings()), fn () => Messages::send($app, $id, $language, (string) $row['email'],
                        self::mailValues($collection, $item, self::nameOf($element, (string) $row['data']), (int) $row['seats']) + ['message' => $message]));
                }
            }
            self::promote($app, $collection, $item, date('Y-m-d H:i'));
        } finally {
            $db->unlock($lock);
        }
    }

    /**
     * After registrations were marked spam, un-spammed, trashed or restored (the caller passes only rows whose flag changed):
     * a seat that freed moves the waiting list up; a holding row that came back and no longer fits goes to the end of the
     * waiting list (cancelled when the list is off). Never overbooks; no mail except a promotion's.
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
            if (($found = self::load($db, $itemId)) === null || ($course = self::course($found[0], $found[1])) === null) {
                continue;
            }
            if (!$db->lock($lock, 5)) {
                throw new \RuntimeException('The seats of a course are being changed; try again.');
            }
            try {
                foreach (array_merge(...array_values($items)) as $id) {
                    $row = $db->one('SELECT seat_state, deleted_at, category FROM {enquiries} WHERE enquiry_id = ?', [$id]);
                    if ($row === null || $row['deleted_at'] !== null || $row['category'] === 'spam' || !in_array($row['seat_state'], self::HOLDING, true)) {
                        continue; // gone (its seat is free) or waiting for a seat: nothing to fit
                    }
                    if ($course['capacity'] > 0 && self::taken($db, $itemId) > $course['capacity']) {
                        $db->update('enquiries', ['seat_state' => $course['waiting_list'] ? 'waitlisted' : 'cancelled', 'state_changed_at' => date('Y-m-d H:i:s')], ['enquiry_id' => $id]);
                    }
                }
                self::promote($app, $found[0], $found[1], date('Y-m-d H:i'));
            } finally {
                $db->unlock($lock);
            }
        }
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

    /** The visitor's mail (request received for confirm-first, moved up for instant) and, for confirm-first, the holder's. */
    private static function tell(App $app, array $collection, array $item, array $course, array $row): void
    {
        $element = Forms::findElement($app->db(), (string) $row['source'], (string) $row['element'], 'form');
        $name = self::nameOf($element, (string) $row['data']);
        $s = $app->settings();
        $language = (string) $row['language'];
        if ((string) $row['email'] !== '') {
            Language::runWith($language !== '' ? $language : Language::defaults($s), fn () => Messages::send($app, $course['mode'] === 'confirm' ? 'course_request' : 'course_moved_up', $language, (string) $row['email'],
                self::mailValues($collection, $item, $name, (int) $row['seats'])));
        }
        if ($course['mode'] === 'confirm' && $element !== null) {
            [$to, $bcc] = Forms::recipients((string) $element['content']['recipient'], (string) ($element['content']['bcc'] ?? ''), $s->get('site_email'));
            if ($to !== []) {
                Language::runWith(Language::defaults($s), function () use ($s, $item, $row, $to, $bcc): void {
                    $text = t('A seat is free and a registration moved up from the waiting list. Please confirm or decline it in the administration.')
                        . "\n\n" . $item['name'] . "\n" . t('Seats') . ': ' . $row['seats'];
                    Mail::send($s, implode(', ', $to), FormMail::subject(t('Request:') . ' ' . $item['name']), $text, '', $bcc !== [] ? ['Bcc' => implode(', ', $bcc)] : []);
                });
            }
        }
    }
}
