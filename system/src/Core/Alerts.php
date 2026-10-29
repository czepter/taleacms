<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Alert e-mails (2.8): when something breaks – a backup, an update, mail, a webhook, a background job – the owner hears about
 * it without opening the administration. A background job (Core\Scheduler) reads the error events (Core\Events) – and the
 * warnings in WARNINGS, which need the owner too – after the last one it reported and sends one e-mail with all of them, at
 * most one per alerts_interval minutes (later ones wait for the next one).
 * The address is alerts_email, otherwise the site e-mail; alerts_enabled switches it off, alerts_kinds picks what is worth it.
 */
final class Alerts
{
    public const int MAX_IN_ONE = 30;

    /** What the owner can choose to hear about: kind => [label, the event type prefixes]; every other type is 'other'. */
    public const array KINDS = [
        'backups' => ['Backups', ['backup']],
        'mail' => ['E-mails that could not be sent', ['mail']],
        'deliveries' => ['Webhooks and connected services', ['webhook', 'connector']],
        'jobs' => ['Background jobs, scheduled Claude runs and add-ons', ['task', 'agent_run', 'addon']],
        'content' => ['Content that expired or asks for a review', ['content']],
        'notfound' => ['Missing pages requested often', ['notfound']],
        'security' => ['Suspended accounts and revoked connections', ['security']],
        'fleet' => ['Sites of the fleet console', ['fleet']],
        'other' => ['Everything else (also add-ons\' warnings)', []],
    ];

    /** How often at most (alerts_interval): minutes => label; 0 = at the next run of the job. */
    public const array INTERVALS = [0 => 'right away', 60 => 'at most one an hour', 360 => 'at most one every 6 hours', 1440 => 'at most one a day'];

    /** The kind of an event type (a key of KINDS). */
    public static function kind(string $type): string
    {
        $prefix = strstr($type, '.', true) ?: $type;
        foreach (self::KINDS as $kind => [, $prefixes]) {
            if (in_array($prefix, $prefixes, true)) {
                return $kind;
            }
        }

        return 'other';
    }

    /** Warnings worth an e-mail (2.10); the rest of the warnings – e.g. a blocked address – stay in the events and System status. */
    public const array WARNINGS = ['notfound.spike', 'content.expired', 'content.review', 'fleet.site_silent', 'security.account_suspended', 'security.connection_revoked', 'agent_run.missed'];

    /** Talea's warnings worth an e-mail and those of the switched-on add-ons (Extension\Api::eventType with $alert). @return list<string> */
    public static function warnings(): array
    {
        return [...self::WARNINGS, ...\Talea\Extension\Registry::alertTypes()];
    }

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
        $read = Events::since($db, $cursor, [], 500, 'warning');
        $events = self::worth($read, explode(',', $s->get('alerts_kinds')));
        if ($events === []) {
            if ($read !== []) {
                $s->set('alerts_cursor', (string) end($read)['id']); // warnings that are not worth an e-mail are passed
            }

            return 'nothing new';
        }
        if (time() - $s->int('alerts_last_sent') < $s->int('alerts_interval') * 60) {
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
     * Events worth an e-mail: errors, and the warnings in WARNINGS – of the chosen kinds (null = all).
     *
     * @param list<array{id: int, created_at: string, type: string, severity: string, message: string, data: array<string, mixed>}> $events
     * @param list<string>|null $kinds
     * @return list<array{id: int, created_at: string, type: string, severity: string, message: string, data: array<string, mixed>}>
     */
    public static function worth(array $events, ?array $kinds = null): array
    {
        return array_values(array_filter($events, fn (array $e): bool => ($e['severity'] === 'error' || in_array($e['type'], self::warnings(), true))
            && ($kinds === null || in_array(self::kind($e['type']), $kinds, true))));
    }

    /**
     * The subject and the text, in the site's default language (the job also runs on the public site). Up to MAX_IN_ONE events
     * are listed, the rest are counted (a daily e-mail must not fall behind).
     *
     * @param list<array{id: int, created_at: string, type: string, severity: string, message: string, data: array<string, mixed>}> $events
     * @return array{0: string, 1: string}
     */
    public static function message(Settings $s, array $events, string $siteUrl): array
    {
        return Language::runWith(Language::defaults($s), function () use ($s, $events, $siteUrl): array {
            $subject = t('%s: %d problem(s) on the site', $s->get('site_name'), count($events));
            $lines = array_map(fn (array $e): string => '– ' . substr($e['created_at'], 0, 16) . ' · ' . $e['message'], array_slice($events, 0, self::MAX_IN_ONE));
            if (count($events) > self::MAX_IN_ONE) {
                $lines[] = t('… and %d more', count($events) - self::MAX_IN_ONE);
            }
            $often = t(self::INTERVALS[$s->int('alerts_interval')] ?? self::INTERVALS[60]);
            $text = t('Talea noticed something that needs your attention:') . "\n\n" . implode("\n", $lines) . "\n\n"
                . t('Details are in System status:') . ' ' . $siteUrl . '/admin/status' . "\n\n"
                . t('Alert e-mails: %s. How often, what about, the address and switching them off: System status.', $often);

            return [$subject, $text];
        }, 'admin-'); // the texts are in the admin dictionaries (the alert goes to the administrator)
    }
}
