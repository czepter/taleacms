<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use Talea\Builder\Presets;
use Talea\Core\Calendar;
use Talea\Core\Registrations;
use Talea\Tests\Support\DatabaseTestCase;

final class RegistrationsTest extends DatabaseTestCase
{
    private function collection(string $preset, string $slug): array
    {
        $fields = Presets::fields($this->db(), Presets::all()[$preset]);
        $id = $this->db()->insert('collections', ['name' => $slug, 'slug' => $slug, 'fields' => json_encode($fields), 'preset' => $preset]);

        return ['collection_id' => $id, 'slug' => $slug, 'preset' => $preset, 'fields' => $fields, 'detail' => true];
    }

    private function item(array $collection, string $slug, array $data, string $language = ''): array
    {
        $id = $this->db()->insert('collection_items', ['collection_id' => $collection['collection_id'], 'name' => $slug, 'slug' => $slug, 'data' => json_encode($data), 'language' => $language, 'created_at' => date('Y-m-d H:i:s')]);

        return ['item_id' => $id, 'name' => $slug, 'slug' => $slug, 'data' => $data];
    }

    private function enquiry(int $itemId, string $state, int $seats = 1, string $category = '', ?string $deleted = null, string $changed = '2026-10-01 10:00:00'): int
    {
        return $this->db()->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'source' => 'x', 'page' => '/c/x', 'data' => '[]', 'item_id' => $itemId, 'seat_state' => $state,
            'seats' => $seats, 'category' => $category, 'deleted_at' => $deleted, 'state_changed_at' => $changed]);
    }

    public function testTakenSkipsSpamTrashAndNonHolding(): void
    {
        $c = $this->collection('courses', 'courses');
        $a = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00']);
        $b = $this->item($c, 'salsa-de', ['start' => '2030-01-01 10:00']);
        $this->db()->update('collection_items', ['slug' => 'salsa', 'language' => 'de'], ['item_id' => $b['item_id']]); // language version, same slug
        $this->enquiry($a['item_id'], 'confirmed', 2);
        $this->enquiry($b['item_id'], 'waiting', 1);
        $this->enquiry($a['item_id'], 'confirmed', 5, 'spam');
        $this->enquiry($a['item_id'], 'confirmed', 5, '', '2026-10-01 10:00:00');
        $this->enquiry($a['item_id'], 'waitlisted', 5);
        $this->enquiry($a['item_id'], 'cancelled', 5);
        $this->assertSame(3, Registrations::taken($this->db(), $a['item_id']));
        $this->assertSame(3, Registrations::taken($this->db(), $b['item_id']));
    }

    public function testRegisteredCountsSpamNoMore(): void
    {
        $c = $this->collection('events', 'events');
        $item = $this->item($c, 'yoga', ['start' => date('Y-m-d H:i', time() + 86400), 'repeat' => 'weekly']);
        $this->assertNull(Registrations::course($c, $item));
        foreach ([['', null], ['spam', null], ['', '2026-10-01 10:00:00']] as [$category, $deleted]) {
            $this->db()->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'source' => 'collection:' . $c['collection_id'], 'page' => '/events/yoga', 'data' => '[]', 'category' => $category, 'deleted_at' => $deleted]);
        }
        $this->assertSame(1, Calendar::registered($this->db(), $c, $item, Calendar::fields($c)));
    }

    public function testRegisteredOfACourseCountsSeats(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00']);
        $this->enquiry($item['item_id'], 'confirmed', 3);
        $this->assertSame(3, Calendar::registered($this->db(), $c, $item, Calendar::fields($c)));
    }

    public function testPromoteStopsAfterDeadline(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '1', 'registration_until' => '2029-12-01 10:00']);
        $id = $this->enquiry($item['item_id'], 'waitlisted');
        $this->assertSame(0, Registrations::promote($this->app(), $c, $item, '2029-12-02 00:00'));
        $this->assertSame('waitlisted', $this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$id]));
    }

    public function testPromoteInstantConfirmsOldestFitting(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '3']);
        $this->enquiry($item['item_id'], 'confirmed', 1);
        $pair = $this->enquiry($item['item_id'], 'waitlisted', 3, '', null, '2026-10-01 10:00:00');
        $single = $this->enquiry($item['item_id'], 'waitlisted', 1, '', null, '2026-10-02 10:00:00');
        $other = $this->enquiry($item['item_id'], 'waitlisted', 1, '', null, '2026-10-03 10:00:00');
        $this->assertSame(2, Registrations::promote($this->app(), $c, $item, '2029-12-02 00:00'));
        $state = fn (int $id): string => (string) $this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$id]);
        $this->assertSame(['waitlisted', 'confirmed', 'confirmed'], [$state($pair), $state($single), $state($other)]);
    }

    /** @return array{0: array, 1: array} the collection (with a registration form in its item template) and a course item */
    private function courseWithForm(string $mode): array
    {
        $c = $this->collection('courses', 'courses-' . $mode);
        $build = ['v' => 1, 'children' => [['id' => 'f1', 'type' => 'form', 'content' => ['name' => 'Reg', 'recipient' => 'holder@example.com', 'fields' => [
            ['label' => 'Name', 'type' => 'text', 'required' => true], ['label' => 'Email', 'type' => 'email', 'required' => true]]]]]];
        $this->db()->update('collections', ['build' => json_encode($build), 'detail' => true], ['collection_id' => $c['collection_id']]);
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '1', 'registration' => $mode]);

        return [$c, $item];
    }

    private function mails(): array
    {
        return array_map(fn (array $r): array => ['to' => $r['recipient'], 'subject' => $r['subject'], 'text' => (string) (json_decode((string) $r['body'], true)['text'] ?? '')],
            $this->db()->all('SELECT recipient, subject, body FROM {mail} ORDER BY mail_id'));
    }

    public function testPromoteSendsTheMessageOfTheMode(): void
    {
        foreach (['instant' => 'A seat for you', 'confirm' => 'We have your request'] as $mode => $subject) {
            [$c, $item] = $this->courseWithForm($mode);
            // the stored answers omit a field: the name is found by its label, not by position
            $id = $this->enquiry($item['item_id'], 'waitlisted');
            $this->db()->update('enquiries', ['email' => 'v@example.com', 'source' => 'collection:' . $c['collection_id'], 'element' => 'f1',
                'data' => json_encode([['Name', 'Anna'], ['Email', 'v@example.com']])], ['enquiry_id' => $id]);
            $before = count($this->mails());
            $this->assertSame(1, Registrations::promote($this->app(), $c, $item, '2029-12-02 00:00'));
            $mails = array_slice($this->mails(), $before);
            $this->assertStringContainsString($subject, $mails[0]['subject'], $mode);
            $this->assertSame('v@example.com', $mails[0]['to']);
            $this->assertStringContainsString('Anna', $mails[0]['text']);
            $this->assertCount($mode === 'confirm' ? 2 : 1, $mails, 'only a confirm-first course notifies the holder');
            if ($mode === 'confirm') {
                $this->assertSame('holder@example.com', $mails[1]['to']);
                $this->assertSame('waiting', $this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$id]));
            }
        }
    }

    public function testCourseWithAnUnknownRepeatIsStillACourse(): void
    {
        $c = $this->collection('events', 'events');
        $this->assertNotNull(Registrations::course($c, $this->item($c, 'a', ['start' => '2030-01-01 10:00', 'repeat' => 'sometimes'])));
        $this->assertNull(Registrations::course($c, $this->item($c, 'b', ['start' => '2030-01-01 10:00', 'repeat' => 'weekly'])));
    }

    public function testLockForIsTheSameForTheWholeGroup(): void
    {
        $c = $this->collection('courses', 'courses');
        $a = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00']);
        $b = $this->item($c, 'salsa-x', ['start' => '2030-01-01 10:00']);
        $this->db()->update('collection_items', ['slug' => 'salsa', 'language' => 'de'], ['item_id' => $b['item_id']]);
        $this->assertSame(Registrations::lockFor($this->db(), $a['item_id']), Registrations::lockFor($this->db(), $b['item_id']));
    }

    /** A real App that shares the test's connection (and so its transaction). */
    private function app(): \Talea\Core\App
    {
        $app = new \Talea\Core\App($this->dbConfig(), \Talea\Core\Request::fromGlobals());
        (new \ReflectionProperty($app, 'db'))->setValue($app, $this->db());

        return $app;
    }
}
