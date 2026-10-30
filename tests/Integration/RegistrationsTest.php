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

    public function testLastSeatIsTakenOnce(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '1']);
        $a = $this->db()->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'source' => 'x', 'page' => '/c/x', 'data' => '[]', 'item_id' => $item['item_id'], 'seats' => 1]);
        $b = $this->db()->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'source' => 'x', 'page' => '/c/x', 'data' => '[]', 'item_id' => $item['item_id'], 'seats' => 1]);
        $this->assertSame('confirmed', Registrations::place($this->app(), $c, $item, $a, 1, ''));
        $this->assertSame('waitlisted', Registrations::place($this->app(), $c, $item, $b, 1, ''));
        $this->assertSame(['confirmed', 'waitlisted'], [$this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$a]), $this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$b])]);
    }

    public function testPlaceRefusesTheSameAddressAndWritesNothing(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '5']);
        $row = fn (): int => $this->db()->insert('enquiries', ['created_at' => date('Y-m-d H:i:s'), 'source' => 'x', 'page' => '/c/x', 'data' => '[]', 'email' => 'Eva@Example.com', 'item_id' => $item['item_id']]);
        $a = $row();
        $b = $row();
        $this->assertSame('confirmed', Registrations::place($this->app(), $c, $item, $a, 1, 'Eva@Example.com'));
        $this->assertSame('already', Registrations::place($this->app(), $c, $item, $b, 1, 'eva@example.com'));
        $this->assertSame('', (string) $this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$b]));
    }

    private function state(int $id): string
    {
        return (string) $this->db()->value('SELECT seat_state FROM {enquiries} WHERE enquiry_id = ?', [$id]);
    }

    /** A registration with a mailing address and the stored answers of the form of courseWithForm(). */
    private function registrant(array $c, array $item, string $state, string $email, string $name, int $seats = 1, string $changed = '2026-10-01 10:00:00'): int
    {
        $id = $this->enquiry($item['item_id'], $state, $seats, '', null, $changed);
        $this->db()->update('enquiries', ['email' => $email, 'source' => 'collection:' . $c['collection_id'], 'element' => 'f1',
            'data' => json_encode([['Name', $name], ['Email', $email]])], ['enquiry_id' => $id]);

        return $id;
    }

    public function testConfirmDeclineCancelPromote(): void
    {
        [$c, $item] = $this->courseWithForm('confirm');
        $this->db()->update('collection_items', ['data' => json_encode(['start' => '2030-01-01 10:00', 'capacity' => '2', 'registration' => 'confirm'])], ['item_id' => $item['item_id']]);
        $a = $this->registrant($c, $item, 'waiting', 'a@example.com', 'Anna');
        $b = $this->registrant($c, $item, 'waiting', 'b@example.com', 'Berta');
        $w = $this->registrant($c, $item, 'waitlisted', 'w@example.com', 'Wilma');
        $app = $this->app();

        $before = count($this->mails());
        Registrations::transition($app, $a, 'confirm');
        $mails = array_slice($this->mails(), $before);
        $this->assertSame('confirmed', $this->state($a));
        $this->assertCount(1, $mails);
        $this->assertSame('a@example.com', $mails[0]['to']);
        $this->assertStringContainsString('Confirmed', $mails[0]['subject']);
        $this->assertNotNull($this->db()->value('SELECT state_changed_at FROM {enquiries} WHERE enquiry_id = ?', [$a]));

        $before = count($this->mails());
        Registrations::transition($app, $b, 'decline', 'Sorry, the level is too high.');
        $mails = array_slice($this->mails(), $before);
        $this->assertSame('declined', $this->state($b));
        $this->assertSame('b@example.com', $mails[0]['to']);
        $this->assertStringContainsString('Sorry, the level is too high.', $mails[0]['text']);
        // the freed seat moved Wilma up (confirm-first: waiting) and told her
        $this->assertSame('waiting', $this->state($w));
        $this->assertContains('w@example.com', array_column($mails, 'to'));

        $before = count($this->mails());
        Registrations::transition($app, $a, 'cancel', 'See you next term.');
        $mails = array_slice($this->mails(), $before);
        $this->assertSame('cancelled', $this->state($a));
        $this->assertSame('a@example.com', $mails[0]['to']);
        $this->assertStringContainsString('See you next term.', $mails[0]['text']);

        // promote by hand: a waitlisted pair while one seat is taken and capacity is 2
        $x = $this->registrant($c, $item, 'waitlisted', 'x@example.com', 'Xenia');
        Registrations::transition($app, $x, 'promote');
        $this->assertSame('waiting', $this->state($x));
        Registrations::transition($app, $x, 'confirm');
        $this->assertSame('confirmed', $this->state($x));
        $this->expectException(\DomainException::class);
        Registrations::transition($app, $x, 'confirm'); // already confirmed
    }

    public function testPromoteRefusedWithoutSeat(): void
    {
        [$c, $item] = $this->courseWithForm('instant'); // capacity 1
        $this->registrant($c, $item, 'confirmed', 'a@example.com', 'Anna');
        $w = $this->registrant($c, $item, 'waitlisted', 'w@example.com', 'Wilma');
        try {
            Registrations::transition($this->app(), $w, 'promote');
            $this->fail('no free seat');
        } catch (\DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame('waitlisted', $this->state($w));
        $this->expectException(\DomainException::class);
        Registrations::transition($this->app(), $w, 'decline'); // only a waiting registration can be declined
    }

    public function testSpamFreesSeatAndPromotes(): void
    {
        [$c, $item] = $this->courseWithForm('instant');
        $a = $this->registrant($c, $item, 'confirmed', 'a@example.com', 'Anna');
        $w = $this->registrant($c, $item, 'waitlisted', 'w@example.com', 'Wilma');
        $this->db()->update('enquiries', ['category' => 'spam'], ['enquiry_id' => $a]);
        Registrations::sync($this->app(), [$a]);
        $this->assertSame('confirmed', $this->state($w));
        // un-spam: the seat is taken again, so Anna goes behind Wilma
        $this->db()->update('enquiries', ['category' => ''], ['enquiry_id' => $a]);
        Registrations::sync($this->app(), [$a]);
        $this->assertSame(['confirmed', 'waitlisted'], [$this->state($w), $this->state($a)]);
    }

    public function testRestoreIntoFullCourseWaitlists(): void
    {
        [$c, $item] = $this->courseWithForm('instant');
        $a = $this->enquiry($item['item_id'], 'confirmed', 1, '', date('Y-m-d H:i:s'));
        $b = $this->registrant($c, $item, 'confirmed', 'b@example.com', 'Berta');
        $before = count($this->mails());
        $this->db()->update('enquiries', ['deleted_at' => null], ['enquiry_id' => $a]);
        Registrations::sync($this->app(), [$a]);
        $this->assertSame(['waitlisted', 'confirmed'], [$this->state($a), $this->state($b)]);
        $this->assertNotSame('2026-10-01 10:00:00', (string) $this->db()->value('SELECT state_changed_at FROM {enquiries} WHERE enquiry_id = ?', [$a]));
        $this->assertCount($before, $this->mails(), 'no mail on restore');
    }

    public function testRestoreWithListOffCancels(): void
    {
        [$c, $item] = $this->courseWithForm('instant');
        $this->db()->update('collection_items', ['data' => json_encode(['start' => '2030-01-01 10:00', 'capacity' => '1', 'waiting_list' => 'off'])], ['item_id' => $item['item_id']]);
        $a = $this->enquiry($item['item_id'], 'confirmed', 1, '', date('Y-m-d H:i:s'));
        $this->registrant($c, $item, 'confirmed', 'b@example.com', 'Berta');
        $this->db()->update('enquiries', ['deleted_at' => null], ['enquiry_id' => $a]);
        Registrations::sync($this->app(), [$a]);
        $this->assertSame('cancelled', $this->state($a));
    }

    public function testRestoredWaitlistedRowStaysWaitlisted(): void
    {
        [$c, $item] = $this->courseWithForm('instant');
        $this->registrant($c, $item, 'confirmed', 'b@example.com', 'Berta');
        $w = $this->enquiry($item['item_id'], 'waitlisted', 1, '', null, '2026-10-05 10:00:00');
        Registrations::sync($this->app(), [$w]);
        $this->assertSame(['waitlisted', '2026-10-05 10:00:00'], [$this->state($w), (string) $this->db()->value('SELECT state_changed_at FROM {enquiries} WHERE enquiry_id = ?', [$w])]);
    }

    public function testCapacityLoweredBumpsNobody(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '3']);
        $a = $this->enquiry($item['item_id'], 'confirmed', 1);
        $b = $this->enquiry($item['item_id'], 'confirmed', 1);
        $w = $this->enquiry($item['item_id'], 'waitlisted', 1);
        $this->db()->update('collection_items', ['data' => json_encode(['start' => '2030-01-01 10:00', 'capacity' => '1'])], ['item_id' => $item['item_id']]);
        $item['data']['capacity'] = '1';
        $this->assertSame(0, Registrations::promote($this->app(), $c, $item, '2029-12-02 00:00'));
        $this->assertSame(2, Registrations::taken($this->db(), $item['item_id']));
        $this->assertSame(['confirmed', 'confirmed', 'waitlisted'], [$this->state($a), $this->state($b), $this->state($w)]);
    }

    public function testCallersDetectTheFlagChange(): void
    {
        [$c, $item] = $this->courseWithForm('instant');
        $a = $this->registrant($c, $item, 'confirmed', 'a@example.com', 'Anna');
        $w = $this->registrant($c, $item, 'waitlisted', 'w@example.com', 'Wilma');
        $app = $this->app();
        // trash: the seat frees, Wilma moves up; restore: Anna no longer fits and goes behind
        $this->assertSame(1, \Talea\Admin\Modules\Enquiries::trashAndSync($app, [$a]));
        $this->assertSame('confirmed', $this->state($w));
        $this->assertSame(1, \Talea\Admin\Modules\Enquiries::restoreAndSync($app, [$a]));
        $this->assertSame('waitlisted', $this->state($a));
        // spam by category: Wilma holds the seat, the spam mark on her frees it for Anna; a change of another kind does nothing
        $before = \Talea\Core\Triage::categories($this->db(), [$w]);
        $this->db()->update('enquiries', ['category' => 'spam'], ['enquiry_id' => $w]);
        \Talea\Core\Triage::afterCategories($app, $before);
        $this->assertSame('confirmed', $this->state($a));
        $before = \Talea\Core\Triage::categories($this->db(), [$a]);
        $this->db()->update('enquiries', ['category' => 'job'], ['enquiry_id' => $a]);
        \Talea\Core\Triage::afterCategories($app, $before);
        $this->assertSame('confirmed', $this->state($a));
    }

    public function testRestoredRowsAreFittedOldestFirst(): void
    {
        $c = $this->collection('courses', 'courses');
        $item = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00', 'capacity' => '2']);
        $young = $this->enquiry($item['item_id'], 'confirmed', 1, '', date('Y-m-d H:i:s'), '2026-10-03 10:00:00');
        $old = $this->enquiry($item['item_id'], 'confirmed', 1, '', date('Y-m-d H:i:s'), '2026-10-01 10:00:00');
        $this->enquiry($item['item_id'], 'confirmed', 1);
        $this->db()->update('enquiries', ['deleted_at' => null], ['enquiry_id' => $young]);
        $this->db()->update('enquiries', ['deleted_at' => null], ['enquiry_id' => $old]);
        Registrations::sync($this->app(), [$young, $old]);
        $this->assertSame(['confirmed', 'waitlisted'], [$this->state($old), $this->state($young)]);
    }

    public function testDeletingARegistrationMovesTheListUp(): void
    {
        [$c, $item] = $this->courseWithForm('instant');
        $a = $this->registrant($c, $item, 'confirmed', 'a@example.com', 'Anna');
        $w = $this->registrant($c, $item, 'waitlisted', 'w@example.com', 'Wilma');
        $items = Registrations::heldItems($this->db(), [$a, $w]);
        $this->assertSame([$item['item_id']], $items);
        $this->db()->delete('enquiries', ['enquiry_id' => $a]);
        Registrations::refill($this->app(), $items);
        $this->assertSame('confirmed', $this->state($w));
    }

    public function testReminderOnceAfterThreeDays(): void
    {
        [$c, $item] = $this->courseWithForm('confirm');
        $make = function (string $changed, string $category = '') use ($c, $item): int {
            $id = $this->enquiry($item['item_id'], 'waiting', 1, $category, null, $changed);
            $this->db()->update('enquiries', ['source' => 'collection:' . $c['collection_id'], 'element' => 'f1'], ['enquiry_id' => $id]);

            return $id;
        };
        $make(date('Y-m-d H:i:s', time() - 2 * 86400)); // too young
        $this->assertSame('0 reminders', Registrations::remind($this->app()));
        $this->assertSame([], $this->mails());
        $make(date('Y-m-d H:i:s', time() - 4 * 86400));
        $make(date('Y-m-d H:i:s', time() - 5 * 86400));
        $make(date('Y-m-d H:i:s', time() - 5 * 86400), 'spam');
        $this->assertSame('1 reminders', Registrations::remind($this->app()));
        $mails = $this->mails();
        $this->assertCount(1, $mails, 'one mail per course, not per request');
        $this->assertSame('holder@example.com', $mails[0]['to']);
        $this->assertStringContainsString('salsa', $mails[0]['subject']);
        $this->assertStringContainsString('2', $mails[0]['text']);
        $this->assertStringContainsString('registrations', $mails[0]['text']);
        $this->assertStringContainsString($this->db()->publicId('collections', $c['collection_id']), $mails[0]['text']);
        $this->assertStringContainsString($this->db()->publicId('collection_items', $item['item_id']), $mails[0]['text']);
        $this->assertSame('0 reminders', Registrations::remind($this->app()));
        $this->assertCount(1, $this->mails());
    }

    public function testNoReminderForAnEndedCourse(): void
    {
        [$c, $item] = $this->courseWithForm('confirm');
        $this->db()->update('collection_items', ['data' => json_encode(['start' => '2020-01-01 10:00', 'registration' => 'confirm'])], ['item_id' => $item['item_id']]);
        $id = $this->enquiry($item['item_id'], 'waiting', 1, '', null, date('Y-m-d H:i:s', time() - 4 * 86400));
        $this->db()->update('enquiries', ['source' => 'collection:' . $c['collection_id'], 'element' => 'f1'], ['enquiry_id' => $id]);
        $this->assertSame('0 reminders', Registrations::remind($this->app()));
        $this->assertSame([], $this->mails());
    }

    /** @var array<string, int>|null the highest keys when a test left the wrapping transaction (see leaveTransaction()) */
    private ?array $marks = null;

    /** Tables a cancelled course touches, with their keys. */
    private const array TOUCHED = ['enquiries' => 'enquiry_id', 'mail' => 'mail_id', 'redirects' => 'redirect_id', 'collection_items' => 'item_id', 'collections' => 'collection_id'];

    /** cancelCourse() commits and sends mails, so it refuses to run in the transaction every test is wrapped in: commit it, and delete what the test wrote afterwards. */
    private function leaveTransaction(): void
    {
        $this->marks = [];
        foreach (self::TOUCHED as $table => $key) {
            $this->marks[$table] = (int) $this->db()->value("SELECT COALESCE(MAX($key), 0) FROM {{$table}}");
        }
        $this->marks['settings'] = 0;
        $this->db()->pdo()->commit();
    }

    protected function tearDown(): void
    {
        if ($this->marks !== null) {
            foreach (self::TOUCHED as $table => $key) {
                $this->db()->run("DELETE FROM {{$table}} WHERE $key > ?", [$this->marks[$table]]);
            }
            $this->db()->run("DELETE FROM {settings} WHERE name LIKE 'message\\_%'");
            $this->marks = null;
            $this->db()->pdo()->beginTransaction();
        }
        parent::tearDown();
    }

    /** @return array{0: array, 1: array, 2: list<array>} a collection with a form, the course to cancel (with registrations in four states) and four other courses */
    private function cancellable(): array
    {
        [$c, $item] = $this->courseWithForm('instant');
        $this->db()->update('collections', ['hidden_redirect' => ''], ['collection_id' => $c['collection_id']]);
        $this->registrant($c, $item, 'confirmed', 'a@example.com', 'Anna');
        $this->registrant($c, $item, 'waiting', 'b@example.com', 'Berta');
        $this->registrant($c, $item, 'waitlisted', 'c@example.com', 'Cilla');
        $this->registrant($c, $item, 'declined', 'd@example.com', 'Dora');
        $others = [];
        foreach (['open-1', 'open-2', 'open-3', 'open-4'] as $slug) {
            $others[] = $this->item($c, $slug, ['start' => '2030-02-01 10:00', 'capacity' => '5', 'schedule' => 'Thursdays']);
            $this->db()->run('UPDATE {collection_items} SET visible = TRUE WHERE item_id = ?', [end($others)['item_id']]);
        }
        $this->db()->run('UPDATE {collection_items} SET visible = TRUE WHERE item_id = ?', [$item['item_id']]);

        return [$c, $item, $others];
    }

    public function testCancelCourseCancelsHidesRedirects(): void
    {
        $this->leaveTransaction();
        [$c, $item, $others] = $this->cancellable();
        $this->assertSame(3, Registrations::cancelCourse($this->app(), $c, $item, [$others[1]['item_id'], $others[0]['item_id']], 'The teacher is ill.'));
        $rows = $this->db()->all('SELECT email, seat_state, offer_token_hash, offer_items, offer_until FROM {enquiries} WHERE item_id = ? ORDER BY enquiry_id', [$item['item_id']]);
        $this->assertSame(['cancelled', 'cancelled', 'cancelled', 'declined'], array_column($rows, 'seat_state'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rows[0]['offer_token_hash']);
        $this->assertNotSame($rows[0]['offer_token_hash'], $rows[1]['offer_token_hash']);
        $this->assertSame([$others[1]['item_id'], $others[0]['item_id']], json_decode($rows[0]['offer_items']), 'in the order they were picked');
        $this->assertNull($rows[3]['offer_token_hash'], 'a declined registration is not told');
        $this->assertSame(0, Registrations::taken($this->db(), $item['item_id']));
        $this->assertSame('0', (string) (int) $this->db()->value('SELECT visible FROM {collection_items} WHERE item_id = ?', [$item['item_id']]));
        $this->assertSame('courses-instant/open-2', $this->db()->value("SELECT to_path FROM {redirects} WHERE from_path = 'courses-instant/salsa'"));

        $mails = $this->mails();
        $this->assertCount(3, $mails);
        $this->assertSame('Cancelled: salsa', $mails[0]['subject']);
        $this->assertStringContainsString('The teacher is ill.', $mails[0]['text']);
        $this->assertSame(2, preg_match_all('~Take a seat: \S+/_course/([a-f0-9]{64})/([12])~', $mails[0]['text'], $m));
        $this->assertSame(hash('sha256', $m[1][0]), $rows[0]['offer_token_hash'], 'the raw token is only in the mail');
        $this->assertStringNotContainsString('{', $mails[0]['text']);
    }

    public function testCancelCourseWithoutOffersRedirectsToTheListAndLeavesNoPlaceholder(): void
    {
        $this->leaveTransaction();
        [$c, $item] = $this->cancellable();
        $this->assertSame(3, Registrations::cancelCourse($this->app(), $c, $item, [], ''));
        $this->assertSame('courses-instant', $this->db()->value("SELECT to_path FROM {redirects} WHERE from_path = 'courses-instant/salsa'"));
        $this->assertNull($this->db()->value('SELECT offer_token_hash FROM {enquiries} WHERE item_id = ? ORDER BY enquiry_id LIMIT 1', [$item['item_id']]), 'nothing to take over: no token');
        $text = $this->mails()[0]['text'];
        $this->assertStringNotContainsString('{', $text);
        $this->assertStringNotContainsString('_course', $text);
        $this->assertDoesNotMatchRegularExpression("/\n\n\n/", $text);
    }

    public function testCancellationMailUsesRegistrationLanguage(): void
    {
        $this->leaveTransaction();
        [$c, $item] = $this->cancellable();
        $this->db()->update('enquiries', ['language' => 'de'], ['item_id' => $item['item_id'], 'email' => 'b@example.com']);
        $this->app()->settings()->set(\Talea\Core\Messages::key('course_course_cancelled', 'subject', 'de'), 'Abgesagt: {course}'); // the German template of the owner
        Registrations::cancelCourse($this->app(), $c, $item, [], '');
        $subjects = array_column($this->mails(), 'subject', 'to');
        $this->assertSame('Cancelled: salsa', $subjects['a@example.com']);
        $this->assertSame('Abgesagt: salsa', $subjects['b@example.com']);
    }

    public function testOffersLimitedToThreeOpenCourses(): void
    {
        $this->leaveTransaction();
        [$c, $item, $others] = $this->cancellable();
        $this->db()->run('UPDATE {collection_items} SET visible = FALSE WHERE item_id = ?', [$others[0]['item_id']]);
        $closed = $this->item($c, 'closed', ['start' => '2020-01-01 10:00']);
        $this->db()->run('UPDATE {collection_items} SET visible = TRUE WHERE item_id = ?', [$closed['item_id']]);
        $pick = [$item['item_id'], $others[0]['item_id'], $closed['item_id'], 999999, $others[1]['item_id'], $others[2]['item_id'], $others[3]['item_id'], $others[1]['item_id']];
        $this->assertSame([$others[1]['item_id'], $others[2]['item_id'], $others[3]['item_id']], array_map(fn (array $o): int => (int) $o['item_id'], Registrations::offerable($this->db(), $c, $item, $pick)),
            'not itself, not hidden, not closed, not unknown, no duplicate, at most three');
        Registrations::cancelCourse($this->app(), $c, $item, $pick, '');
        $this->assertSame([$others[1]['item_id'], $others[2]['item_id'], $others[3]['item_id']], json_decode((string) $this->db()->value('SELECT offer_items FROM {enquiries} WHERE item_id = ? ORDER BY enquiry_id LIMIT 1', [$item['item_id']])));
        $this->assertSame(3, preg_match_all('~/_course/[a-f0-9]{64}/[123]~', $this->mails()[0]['text']));
    }

    public function testCancelCourseRefusesAnOpenTransaction(): void
    {
        [$c, $item] = $this->cancellable();
        $this->expectException(\LogicException::class);
        Registrations::cancelCourse($this->app(), $c, $item, [], '');
    }

    public function testLanguageVersionsGetTheirOwnCounterparts(): void
    {
        $this->leaveTransaction();
        [$c, $item, $others] = $this->cancellable();
        $german = $this->item($c, 'salsa', ['start' => '2030-01-01 10:00'], 'de'); // the German version of the course (same slug, shared seats)
        $offerDe = $this->item($c, 'open-1', ['start' => '2030-02-01 10:00', 'capacity' => '5'], 'de');
        $this->db()->run('UPDATE {collection_items} SET visible = TRUE, name = ? WHERE item_id = ?', ['Offen 1', $offerDe['item_id']]);
        $this->db()->update('enquiries', ['language' => 'de'], ['item_id' => $item['item_id'], 'email' => 'b@example.com']);
        $listed = array_map(fn (array $o): int => (int) $o['item_id'], Registrations::offerable($this->db(), $c, $item));
        $this->assertNotContains($offerDe['item_id'], $listed, 'the admin offers the default-language courses');
        Registrations::cancelCourse($this->app(), $c, $item, [$others[0]['item_id']], '');
        $this->assertSame('courses-instant/open-1', $this->db()->value("SELECT to_path FROM {redirects} WHERE from_path = 'courses-instant/salsa'"));
        $mails = array_column($this->mails(), 'text', 'to');
        $this->assertStringContainsString('Offen 1', $mails['b@example.com'], 'the German registrant reads the German course');
        $this->assertMatchesRegularExpression('~/de/_course/[a-f0-9]{64}/1~', $mails['b@example.com']);
        $this->assertDoesNotMatchRegularExpression('~/de/_course~', $mails['a@example.com']);
        $this->assertSame('0', (string) (int) $this->db()->value('SELECT visible FROM {collection_items} WHERE item_id = ?', [$german['item_id']]));
    }
}
