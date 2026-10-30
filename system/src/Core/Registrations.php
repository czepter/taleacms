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
 * seats take Db::lock(lockName(...), 5) first.
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
        if ($get('repeat') !== '') {
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
            $rows = $db->all("SELECT * FROM {enquiries} WHERE deleted_at IS NULL AND category <> 'spam' AND seat_state = 'waitlisted' AND item_id IN ($in) ORDER BY state_changed_at, enquiry_id", $ids);
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

    /** The visitor's mail and, for a confirm-first course, the holder's. */
    private static function tell(App $app, array $collection, array $item, array $course, array $row): void
    {
        $data = (array) ($item['data'] ?? []);
        $element = Forms::findElement($app->db(), (string) $row['source'], (string) $row['element'], 'form');
        $name = '';
        $answers = json_decode((string) $row['data'], true) ?: [];
        foreach ((array) ($element['content']['fields'] ?? []) as $i => $field) {
            if (($field['type'] ?? '') === 'text' && isset($answers[$i][1]) && FormMail::isName((string) $answers[$i][1])) {
                $name = (string) $answers[$i][1];
                break;
            }
        }
        $fields = Calendar::fields($collection);
        $start = (string) ($data[$fields['start']] ?? '');
        if ((string) $row['email'] !== '') {
            Messages::send($app, 'course_moved_up', (string) $row['language'], (string) $row['email'], [
                'name' => $name, 'course' => (string) $item['name'], 'dates' => Calendar::when($start, (string) ($data[$fields['end']] ?? '')),
                'schedule' => (string) ($data['schedule'] ?? ''), 'place' => (string) ($data[$fields['place']] ?? ''), 'seats' => (string) $row['seats'],
            ]);
        }
        if ($course['mode'] === 'confirm' && $element !== null) {
            $s = $app->settings();
            [$to, $bcc] = Forms::recipients((string) $element['content']['recipient'], (string) ($element['content']['bcc'] ?? ''), $s->get('site_email'));
            if ($to !== []) {
                $text = Language::runWith(Language::defaults($s), fn (): string => t('A seat is free and a registration moved up from the waiting list. Please confirm or decline it in the administration.')
                    . "\n\n" . $item['name'] . "\n" . t('Seats') . ': ' . $row['seats']);
                Mail::send($s, implode(', ', $to), FormMail::subject(t('Request:') . ' ' . $item['name']), (string) $text, '', $bcc !== [] ? ['Bcc' => implode(', ', $bcc)] : []);
            }
        }
    }
}
