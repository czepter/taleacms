<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The three own texts of the bookings screen become messages of Settings → Messages (Core\Messages): a text that was set is
 * copied to the new setting (unless that one is already set) and the old row is deleted. The old texts were only the opening of
 * the e-mail and the details followed; the new text is the whole body, so the owner's text now stands alone ({appointment} adds
 * the details where wanted) and {name} still works.
 */
final class MoveBookingTextsToMessages extends AbstractMigration
{
    /** old setting => new setting */
    private const array MOVED = [
        'booking_pending_thanks' => 'message_booking_pending_thanks_body',
        'booking_pending_mail' => 'message_booking_pending_body',
        'booking_declined_mail' => 'message_booking_declined_body',
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
        foreach (self::MOVED as $old => $new) {
            $read->execute([$old]);
            $text = trim((string) $read->fetchColumn());
            $read->execute([$new]);
            if ($text !== '' && $read->fetchColumn() === false) {
                $insert->execute([$new, $text]);
            }
            $delete->execute([$old]);
        }
    }
}
