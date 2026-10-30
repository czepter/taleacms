<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Course registration: an enquiry sent from the page of a calendar item (source "collection:<id>") becomes a registration with a
 * seat state (confirmed | waiting | waitlisted | declined | cancelled; NULL = not a registration), the number of seats, the
 * language it was sent in and the fields of a waiting-list offer. Existing enquiries from calendar item pages are linked to
 * their item; those of a non-repeating item (a course) count as confirmed registrations.
 */
final class AddCourseRegistrations extends AbstractMigration
{
    public function change(): void
    {
        $this->table('enquiries')
            ->addColumn('item_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the collection item the enquiry was sent from (a course); NULL = not from an item'])
            ->addColumn('seat_state', 'string', ['limit' => 12, 'null' => true, 'comment' => 'confirmed | waiting | waitlisted | declined | cancelled; NULL = not a course registration'])
            ->addColumn('seats', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 1])
            ->addColumn('language', 'string', ['limit' => 35, 'null' => false, 'default' => '', 'comment' => 'the language version it was sent from; empty = the default'])
            ->addColumn('state_changed_at', 'datetime', ['null' => true])
            ->addColumn('reminded_at', 'datetime', ['null' => true])
            ->addColumn('offer_token_hash', 'string', ['limit' => 64, 'null' => true, 'comment' => 'sha256 of the token of a waiting-list offer'])
            ->addColumn('offer_items', 'string', ['limit' => 255, 'null' => true, 'comment' => 'JSON list of the item ids the offer is for'])
            ->addColumn('offer_until', 'datetime', ['null' => true])
            ->addIndex(['item_id', 'seat_state'], ['name' => 'ix_enquiries_item_id_seat_state'])
            ->addIndex(['offer_token_hash'], ['name' => 'ix_enquiries_offer_token_hash'])
            ->update();

        if ($this->isMigratingUp()) {
            self::backfill($this->getAdapter()->getConnection(), (string) $this->getAdapter()->getOption('table_prefix'));
        }
    }

    /** Links enquiries from item pages to their item and confirms those of non-repeating calendar items; safe to run again. */
    public static function backfill(\PDO $pdo, string $prefix): void
    {
        $collections = [];
        foreach ($pdo->query("SELECT collection_id, slug, preset FROM {$prefix}collections")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $collections[(int) $c['collection_id']] = $c;
        }
        $presets = [];
        $find = $pdo->prepare("SELECT item_id, data, language FROM {$prefix}collection_items WHERE collection_id = ? AND slug = ?");
        $update = $pdo->prepare("UPDATE {$prefix}enquiries SET item_id = ?, language = ?, seat_state = ?, state_changed_at = ? WHERE enquiry_id = ?");

        $rows = $pdo->query("SELECT enquiry_id, source, page, created_at FROM {$prefix}enquiries WHERE source LIKE 'collection:%' AND item_id IS NULL")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $collection = $collections[(int) substr((string) $row['source'], 11)] ?? null;
            $segments = explode('/', trim((string) strtok((string) $row['page'], '?#'), '/'));
            $language = count($segments) === 3 ? strtolower($segments[0]) : '';
            if ($collection === null || count($segments) !== ($language === '' ? 2 : 3) || $segments[count($segments) - 2] !== $collection['slug']) {
                continue;
            }
            $find->execute([$collection['collection_id'], $segments[count($segments) - 1]]);
            $items = $find->fetchAll(\PDO::FETCH_ASSOC);
            $item = null;
            foreach ($items as $candidate) {
                if ($candidate['language'] === $language) {
                    $item = $candidate;
                    break;
                }
            }
            $item ??= $items[0] ?? null;
            if ($item === null) {
                continue;
            }
            $key = (string) $collection['preset'];
            $presets[$key] ??= preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) === 1 && is_file(__DIR__ . "/../../presets/$key.php") ? (require __DIR__ . "/../../presets/$key.php")['calendar'] ?? [] : [];
            $repeat = $presets[$key]['repeat'] ?? null;
            $data = json_decode((string) $item['data'], true);
            $repeats = $repeat !== null && is_array($data) && !empty($data[$repeat]);
            $confirmed = isset($presets[$key]['start']) && !$repeats;
            $update->execute([$item['item_id'], $language, $confirmed ? 'confirmed' : null, $confirmed ? $row['created_at'] : null, $row['enquiry_id']]);
        }
    }
}
