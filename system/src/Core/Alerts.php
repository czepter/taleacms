<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Alert e-mails (2.8): when something breaks – a backup, an update, mail, a webhook, a background job – the owner hears about
 * it without opening the administration. A background job (Core\Scheduler) reads the error events (Core\Events) after the
 * last one it reported and sends one e-mail with all of them, at most one per hour (later errors wait for the next one).
 * The address is alerts_email, otherwise the site e-mail; alerts_enabled switches it off.
 */
final class Alerts
{
    public const int MINUTES_BETWEEN = 60;
    public const int MAX_IN_ONE = 30;

    /** One run of the job: returns what it did, for System status. */
    public static function run(App $app): string
    {
        $s = $app->settings();
        $db = $app->db();
        if (!$s->bool('alerts_enabled') || Demo::active()) {
            return 'off';
        }
        if ($s->get('alerts_cursor') === '') {
            $s->set('alerts_cursor', (string) Events::lastId($db)); // start from now: old events are not sent on the first run

            return 'started';
        }
        $cursor = $s->int('alerts_cursor');
        $events = Events::since($db, $cursor, [], self::MAX_IN_ONE, 'error');
        if ($events === []) {
            return 'nothing new';
        }
        if (time() - $s->int('alerts_last_sent') < self::MINUTES_BETWEEN * 60) {
            return 'waiting';
        }
        $recipient = $s->get('alerts_email') !== '' ? $s->get('alerts_email') : $s->get('site_email');
        if ($recipient === '') {
            return 'no address';
        }
        [$subject, $text] = self::message($s, $events, rtrim($s->get('site_url') ?: $app->request->origin(), '/'));
        Mail::send($s, $recipient, $subject, $text);
        $s->set('alerts_cursor', (string) end($events)['id']);
        $s->set('alerts_last_sent', (string) time());

        return 'sent ' . count($events);
    }

    /**
     * The subject and the text, in the site's default language (the job also runs on the public site).
     *
     * @param list<array{id: int, created_at: string, type: string, severity: string, message: string, data: array<string, mixed>}> $events
     * @return array{0: string, 1: string}
     */
    public static function message(Settings $s, array $events, string $siteUrl): array
    {
        return Language::runWith(Language::defaults($s), function () use ($s, $events, $siteUrl): array {
            $subject = t('%s: %d problem(s) on the site', $s->get('site_name'), count($events));
            $lines = array_map(fn (array $e): string => '– ' . substr($e['created_at'], 0, 16) . ' · ' . $e['message'], $events);
            $text = t('Kaleta noticed something that needs your attention:') . "\n\n" . implode("\n", $lines) . "\n\n"
                . t('Details are in Settings → System status:') . ' ' . $siteUrl . '/admin.php?module=settings&tab=health' . "\n\n"
                . t('You get at most one such e-mail an hour. Change the address or switch the alerts off in System status.');

            return [$subject, $text];
        }, 'admin-'); // the texts are in the admin dictionaries (the alert goes to the administrator)
    }
}
