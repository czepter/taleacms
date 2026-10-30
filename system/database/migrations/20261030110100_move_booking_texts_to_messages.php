<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The three own texts of the bookings screen become messages of Settings → Messages (Core\Messages), so nothing changes for
 * the owner: a text that was set is copied to the new setting (unless that one is already set) in the default language and in
 * every further language of the site (the old text applied to all), and the old row is deleted. The old acknowledgement and decline
 * texts were only the opening of the e-mail, with the details (and the decline's personal message) after it, so those are appended
 * as placeholders. A bare {name} gets the fallback the course templates use, so a name that is not safe to print leaves "Hi there".
 */
final class MoveBookingTextsToMessages extends AbstractMigration
{
    /** old setting => [new setting, what the old code added after the text] */
    private const array MOVED = [
        'booking_pending_thanks' => ['message_booking_pending_thanks_body', ''],
        'booking_pending_mail' => ['message_booking_pending_body', "\n\n{appointment}"],
        'booking_declined_mail' => ['message_booking_declined_body', "\n\n{message}\n\n{appointment}"],
    ];

    public function up(): void
    {
        self::move($this->getAdapter()->getConnection(), (string) $this->getAdapter()->getOption('table_prefix'));
    }

    public function down(): void
    {
    }

    /** Copies the set texts to their messages and deletes the old rows; safe to run again. */
    public static function move(\PDO $pdo, string $prefix): void
    {
        $read = $pdo->prepare("SELECT value FROM {$prefix}settings WHERE name = ?");
        $insert = $pdo->prepare("INSERT INTO {$prefix}settings (name, value) VALUES (?, ?)");
        $delete = $pdo->prepare("DELETE FROM {$prefix}settings WHERE name = ?");
        $read->execute(['additional_languages']);
        $suffixes = ['', ...array_map(fn (string $code): string => '_' . $code, array_filter(explode(',', (string) $read->fetchColumn())))];
        foreach (self::MOVED as $old => [$new, $after]) {
            $read->execute([$old]);
            $text = trim((string) $read->fetchColumn());
            foreach ($suffixes as $suffix) {
                $read->execute([$new . $suffix]);
                if ($text !== '' && $read->fetchColumn() === false) {
                    $insert->execute([$new . $suffix, str_replace('{name}', '{name|there}', $text) . $after]);
                }
            }
            $delete->execute([$old]);
        }
    }
}
