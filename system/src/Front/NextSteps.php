<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\App;
use Kaleta\Core\Hours;

/**
 * What happens after a form is sent (2.12): the steps the site promised, by when it replies and who. The form carries the
 * steps (one per line), the number of working hours and the name; the deadline is counted in the opening hours from
 * Settings → Company with their exceptions (Core\Hours) – a site without opening hours counts plain hours on working days
 * 8–17. Shown in the thank-you in place of the form and sent in the confirmation e-mail to the visitor.
 */
final class NextSteps
{
    /** The week of a site without opening hours: working days 8–17. */
    public const array DEFAULT_WEEK = ['Monday' => [['08:00', '17:00']], 'Tuesday' => [['08:00', '17:00']], 'Wednesday' => [['08:00', '17:00']],
        'Thursday' => [['08:00', '17:00']], 'Friday' => [['08:00', '17:00']], 'Saturday' => [], 'Sunday' => []];

    /** The most working hours a form may promise (30 working days). */
    public const int MAX_HOURS = 240;

    /**
     * The steps of a form's content: one per line, empty lines dropped.
     *
     * @param array<string, mixed> $content
     * @return list<string>
     */
    public static function steps(array $content): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", (string) ($content['dalsi_kroky'] ?? '')))), fn (string $s): bool => $s !== ''));
    }

    /**
     * When a reply promised within the given working hours is due: only open hours count, closed days and the exceptions
     * to the hours (Hours::day) are skipped. Friday 16:00 + 4 hours with Mo–Fr 8–17 is Monday 11:00. When no day in the
     * next year is open at all, plain hours are added.
     *
     * @param array<string, list<array{0: string, 1: string}>> $week
     * @param list<array<string, mixed>> $exceptions
     */
    public static function deadline(array $week, array $exceptions, \DateTimeImmutable $from, int $workingHours): \DateTimeImmutable
    {
        $remaining = max(0, $workingHours) * 60;
        for ($i = 0; $i <= 366; $i++) {
            $date = $from->setTime(0, 0)->modify('+' . $i . ' days');
            $ranges = Hours::day($week, $exceptions, $date)['ranges'];
            usort($ranges, fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            foreach ($ranges as [$opens, $closes]) {
                $start = $date->setTime((int) substr($opens, 0, 2), (int) substr($opens, 3, 2));
                $start = $start < $from ? $from : $start;
                $end = $date->setTime((int) substr($closes, 0, 2), (int) substr($closes, 3, 2));
                if ($end <= $start) {
                    continue;
                }
                $available = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
                if ($remaining <= $available) {
                    return $start->modify('+' . $remaining . ' minutes');
                }
                $remaining -= $available;
            }
        }

        return $from->modify('+' . max(0, $workingHours) . ' hours');
    }

    /** "We will reply today by 16:00." / "… tomorrow …" / "… on Tuesday by 10:00." / further away with the date. */
    public static function deadlineText(\DateTimeImmutable $deadline, \DateTimeImmutable $now): string
    {
        $days = (int) $now->setTime(0, 0)->diff($deadline->setTime(0, 0))->days;
        $time = $deadline->format('G:i');

        return match (true) {
            $days === 0 => t('We will reply today by %s.', $time),
            $days === 1 => t('We will reply tomorrow by %s.', $time),
            $days < 7 => t('We will reply %s by %s.', t('on ' . $deadline->format('l')), $time),
            default => t('We will reply by %s.', format_date($deadline, true)),
        };
    }

    /**
     * The thank-you's next steps as HTML: the steps as an ordered list, the deadline and who replies; '' when the form
     * promises nothing.
     *
     * @param array<string, mixed> $content
     */
    public static function html(App $app, array $content, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $steps = self::steps($content);
        $html = $steps === [] ? '' : '<p class="ka-kroky-nadpis">' . e(t('What happens next')) . '</p><ol class="ka-kroky">' . implode('', array_map(fn (string $s): string => '<li>' . e($s) . '</li>', $steps)) . '</ol>';
        $due = self::due($app, $content, $now);
        $html .= $due === null ? '' : '<p class="ka-kroky-termin">' . e(self::deadlineText($due, $now)) . '</p>';
        $who = trim((string) ($content['odpovida'] ?? ''));

        return $html . ($who === '' ? '' : '<p class="ka-kroky-kdo">' . e(t('%s will reply.', $who)) . '</p>');
    }

    /**
     * The same for the confirmation e-mail (plain text, numbered steps); '' when the form promises nothing.
     *
     * @param array<string, mixed> $content
     */
    public static function text(App $app, array $content, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $lines = [];
        $steps = self::steps($content);
        if ($steps !== []) {
            $lines[] = t('What happens next') . ":\n" . implode("\n", array_map(fn (int $i, string $s): string => ($i + 1) . '. ' . $s, array_keys($steps), $steps));
        }
        $due = self::due($app, $content, $now);
        if ($due !== null) {
            $lines[] = self::deadlineText($due, $now);
        }
        $who = trim((string) ($content['odpovida'] ?? ''));
        if ($who !== '') {
            $lines[] = t('%s will reply.', $who);
        }

        return implode("\n\n", $lines);
    }

    /** The reply deadline of a form, null when it promises none (0 working hours). @param array<string, mixed> $content */
    private static function due(App $app, array $content, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $hours = max(0, min(self::MAX_HOURS, (int) ($content['odpovime_do'] ?? 0)));
        if ($hours === 0) {
            return null;
        }
        $week = Hours::week($app->settings());

        return self::deadline(array_merge(...array_values($week)) === [] ? self::DEFAULT_WEEK : $week, Hours::exceptions($app->db()), $now, $hours);
    }
}
