<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use Talea\Tests\Support\DatabaseTestCase;

/** The registration columns of enquiries and the backfill that marks the existing registrations of non-repeating calendar items. */
final class CourseRegistrationMigrationTest extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once TALEA_SYSTEM . '/database/migrations/20261030110000_add_course_registrations.php';
    }

    private function enquiry(int $collectionId, string $page): int
    {
        return $this->db()->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'source' => 'collection:' . $collectionId, 'page' => $page, 'data' => '{}']);
    }

    private function item(int $collectionId, string $slug, array $data, string $language = ''): int
    {
        return $this->db()->insert('collection_items', ['collection_id' => $collectionId, 'name' => $slug, 'slug' => $slug, 'data' => json_encode($data), 'language' => $language, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testColumnsExistAndBackfillMarksCourseRegistrations(): void
    {
        $courses = $this->db()->insert('collections', ['name' => 'Courses', 'slug' => 'courses', 'fields' => '[]', 'preset' => 'courses']);
        $events = $this->db()->insert('collections', ['name' => 'Events', 'slug' => 'events', 'fields' => '[]', 'preset' => 'events']);
        $salsa = $this->item($courses, 'salsa', ['start' => '2026-11-02 09:00']);
        $yoga = $this->item($events, 'yoga', ['start' => '2026-11-02 09:00', 'repeat' => 'weekly']);
        $first = $this->enquiry($courses, '/courses/salsa');
        $second = $this->enquiry($courses, '/de/courses/salsa?x=1');
        $third = $this->enquiry($events, '/events/yoga');

        \AddCourseRegistrations::backfill($this->db()->pdo(), self::PREFIX);
        \AddCourseRegistrations::backfill($this->db()->pdo(), self::PREFIX); // idempotent

        $row = function (int $id): array {
            $r = $this->db()->one('SELECT item_id, seat_state, seats, language FROM {enquiries} WHERE enquiry_id = ?', [$id]);

            return [(int) $r['item_id'], $r['seat_state'], (int) $r['seats'], $r['language']];
        };
        $this->assertSame([$salsa, 'confirmed', 1, ''], $row($first));
        $this->assertSame([$salsa, 'confirmed', 1, 'de'], $row($second));
        $this->assertSame([$yoga, null, 1, ''], $row($third));
    }
}
