<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Core\Registrations;

/**
 * MCP tools for course registrations: what the registrations screen and its buttons do in the administration
 * (list_registrations, update_registration, cancel_course). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait RegistrationTools
{
    /** The course item, its collection and rules; the same access as the screens (Collections section and Enquiries right). @return array{0: array, 1: array, 2: array} */
    private function courseOf(int $itemId): array
    {
        $auth = $this->app->auth();
        if (!$auth->hasModule('collections') || !$auth->hasModule('enquiries')) {
            throw new \DomainException('Registrations are for users with the Collections and Enquiries sections.');
        }
        $db = $this->app->db();
        $item = $itemId > 0 ? $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND deleted_at IS NULL', [$itemId]) : null;
        $k = $item !== null ? \Talea\Builder\Collections::byId($db, (int) $item['collection_id']) : null;
        if ($item === null || $k === null) {
            throw new \InvalidArgumentException('The item does not exist. Use list_collection_items.');
        }
        $item['data'] = json_decode((string) $item['data'], true) ?: [];

        return [$k, $item, Registrations::course($k, $item) ?? throw new \InvalidArgumentException('This item is not a course with seats.')];
    }

    /** list_registrations */
    private function toolListRegistrations(string $name, array $a): mixed
    {
        [, $item] = $this->courseOf((int) ($a['item'] ?? 0));
        $db = $this->app->db();
        $forms = [];
        $out = [];
        foreach (Registrations::list($db, (int) $item['item_id']) as $state => $rows) {
            foreach ($rows as $n => $row) {
                $key = $row['source'] . "\0" . $row['element'];
                $forms[$key] ??= \Talea\Front\Forms::findElement($db, (string) $row['source'], (string) $row['element'], 'form');
                $out[] = ['id' => (int) $row['enquiry_id'], 'state' => $state, 'seats' => (int) $row['seats'], 'name' => Registrations::nameOf($forms[$key], (string) $row['data']),
                    'email' => (string) $row['email'], 'created_at' => (string) $row['created_at']] + ($state === 'waitlisted' ? ['position' => $n + 1] : []);
            }
        }

        return ['registrations' => $out];
    }

    /** update_registration */
    private function toolUpdateRegistration(string $name, array $a): mixed
    {
        $action = (string) ($a['action'] ?? '');
        if (!in_array($action, ['confirm', 'decline', 'cancel', 'promote'], true)) {
            throw new \InvalidArgumentException('action is confirm, decline, cancel or promote.');
        }
        $row = $this->app->db()->one('SELECT item_id FROM {enquiries} WHERE enquiry_id = ?', [(int) ($a['registration'] ?? 0)]);
        $this->courseOf($row !== null ? (int) $row['item_id'] : 0);
        Registrations::transition($this->app, (int) $a['registration'], $action, mb_substr(trim((string) ($a['message'] ?? '')), 0, 2000));

        return ['updated' => true, 'registration' => (int) $a['registration'], 'action' => $action];
    }

    /** cancel_course */
    private function toolCancelCourse(string $name, array $a): mixed
    {
        [$k, $item] = $this->courseOf((int) ($a['item'] ?? 0));
        $offer = array_values(array_filter(array_map('intval', (array) ($a['offer'] ?? [])), fn (int $i): bool => $i > 0));
        // offerable() keeps the open courses of the collection (at most 3): the rest of the ones asked for is dropped
        $accepted = array_map(fn (array $o): int => (int) $o['item_id'], Registrations::offerable($this->app->db(), $k, $item, $offer));
        $mails = Registrations::cancelCourse($this->app, $k, $item, $offer, mb_substr(trim((string) ($a['message'] ?? '')), 0, 2000));

        return ['cancelled' => (int) $item['item_id'], 'mails' => $mails, 'offers' => $accepted];
    }
}
