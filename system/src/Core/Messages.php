<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Front\FormMail;

/**
 * Every text a visitor or customer reads from courses and bookings is a message with a template the owner may change
 * (docs/superpowers/specs/2026-10-30-course-registration-design.md, "Customisable messages").
 *
 * - The template is built from t() in the language of the message, so a translation (and, for bookings, today's sentences)
 *   keeps working: nothing changes until someone edits a text.
 * - An override is a setting: `message_<id>_<part>` for the default language, `…_<language>` for a language version. Empty = the template.
 * - Placeholders use the syntax of Front\FormMail ({name}, {name|fallback}). Only the placeholders of the message are filled;
 *   a link whose placeholder the owner removed is added at the end.
 * - Callers pass only values that may stand in a mail to a visitor: a name that passed FormMail::isName, the holder's own
 *   {message}, and data of the site. Nothing else a visitor typed.
 */
final class Messages
{
    /** @var array<string, array{group: string, kind: string, placeholders: list<string>, links: list<string>}> */
    public const array CATALOGUE = [
        'course_registered' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'dates', 'schedule', 'place', 'seats', 'site name'], 'links' => []],
        'course_request' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'dates', 'schedule', 'place', 'seats', 'site name'], 'links' => []],
        'course_waitlisted' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'dates', 'schedule', 'place', 'seats', 'site name'], 'links' => []],
        'course_confirmed' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'dates', 'schedule', 'place', 'seats', 'site name'], 'links' => []],
        'course_declined' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'message', 'site name'], 'links' => []],
        'course_moved_up' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'dates', 'schedule', 'place', 'seats', 'site name'], 'links' => []],
        'course_cancelled' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'message', 'site name'], 'links' => []],
        'course_course_cancelled' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'dates', 'message', 'site name'], 'links' => ['take-over links']],
        'course_already' => ['group' => 'courses', 'kind' => 'mail', 'placeholders' => ['name', 'course', 'site name'], 'links' => []],
        'course_thanks_registered' => ['group' => 'courses', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        'course_thanks_request' => ['group' => 'courses', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        'course_thanks_waitlisted' => ['group' => 'courses', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        'course_full' => ['group' => 'courses', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        'course_closed' => ['group' => 'courses', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        
        'course_take_over' => ['group' => 'courses', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        // {date} is the start of the appointment ("2 Nov 2026 10:00"), {appointment} the service, person, time and place lines
        'booking_confirmation' => ['group' => 'bookings', 'kind' => 'mail', 'placeholders' => ['name', 'appointment', 'service', 'date', 'cancel deadline', 'site name'], 'links' => ['cancel link', 'calendar link']],
        'booking_pending' => ['group' => 'bookings', 'kind' => 'mail', 'placeholders' => ['name', 'appointment', 'service', 'date', 'site name'], 'links' => []],
        'booking_declined' => ['group' => 'bookings', 'kind' => 'mail', 'placeholders' => ['name', 'appointment', 'service', 'date', 'message', 'site name'], 'links' => []],
        'booking_proposal' => ['group' => 'bookings', 'kind' => 'mail', 'placeholders' => ['name', 'appointment', 'service', 'date', 'times', 'message', 'site name'], 'links' => ['choose link']],
        'booking_cancelled' => ['group' => 'bookings', 'kind' => 'mail', 'placeholders' => ['name', 'appointment', 'service', 'date', 'site name'], 'links' => []],
        'booking_reminder' => ['group' => 'bookings', 'kind' => 'mail', 'placeholders' => ['name', 'appointment', 'service', 'date', 'site name'], 'links' => []],
        'booking_thanks' => ['group' => 'bookings', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        'booking_pending_thanks' => ['group' => 'bookings', 'kind' => 'page', 'placeholders' => [], 'links' => []],
        'booking_cancel_page' => ['group' => 'bookings', 'kind' => 'page', 'placeholders' => [], 'links' => []],
    ];

    /** The built-in text of a part ('subject' | 'body') in the current language; '' for the subject of a page text. */
    public static function template(string $id, string $part): string
    {
        $hello = t('Hello {name|there},') . "\n\n";
        $subject = match ($id) {
            'course_registered' => t('You are registered: {course}'),
            'course_request' => t('We have your request: {course}'),
            'course_waitlisted' => t('Waiting list: {course}'),
            'course_confirmed' => t('Confirmed: {course}'),
            'course_already' => t('Already registered: {course}'),
            'course_declined' => t('Your request: {course}'),
            'course_moved_up' => t('A seat for you: {course}'),
            'course_cancelled' => t('Cancelled: your registration for {course}'),
            'course_course_cancelled' => t('Cancelled: {course}'),
            'booking_confirmation' => t('Your appointment on %s – %s', '{date}', '{site name}'),
            'booking_pending' => t('We received your request for %s – %s', '{date}', '{site name}'),
            'booking_declined' => t('Your request for %s – %s', '{date}', '{site name}'),
            'booking_proposal' => t('Other times for your request – %s', '{site name}'),
            'booking_cancelled' => t('Your appointment on %s was cancelled – %s', '{date}', '{site name}'),
            'booking_reminder' => t('Reminder: your appointment on %s – %s', '{date}', '{site name}'),
            default => '',
        };
        if ($part === 'subject') {
            return $subject;
        }

        return match ($id) {
            'course_registered' => $hello . t('you are registered for {course}: {seats} from {dates}, {schedule}, {place}.') . "\n\n" . t('Contract and payment are arranged on site.'),
            'course_request' => $hello . t('we have your request for {course}: {seats} from {dates}, {schedule}, {place}.') . "\n\n" . t('Your seat is held. We will confirm it by e-mail.'),
            'course_waitlisted' => $hello . t('you are on the waiting list for {course}: {seats} from {dates}, {schedule}, {place}.') . "\n\n" . t('We will e-mail you as soon as a seat is free.'),
            'course_confirmed' => $hello . t('your registration for {course} is confirmed: {seats} from {dates}, {schedule}, {place}.') . "\n\n" . t('Contract and payment are arranged on site.'),
            'course_declined' => $hello . t('unfortunately we cannot offer you a seat in {course}.') . "\n\n{message}",
            'course_moved_up' => $hello . t('a seat in {course} is free for you: {seats} from {dates}, {schedule}, {place}.') . "\n\n" . t('If you no longer want it, please tell us by replying to this e-mail.'),
            'course_cancelled' => $hello . t('your registration for {course} has been cancelled.') . "\n\n{message}",
            'course_course_cancelled' => $hello . t('we are sorry, {course} ({dates}) is cancelled.') . "\n\n{message}\n\n{take-over links}",
            'course_thanks_registered' => t('You are registered. We have sent the details to your e-mail address.'),
            'course_thanks_request' => t('We have your request. Your seat is held; we will confirm it by e-mail.'),
            'course_thanks_waitlisted' => t('You are on the waiting list. We will e-mail you as soon as a seat is free.'),
            'course_full' => t('This course is full. Join the waiting list and we will e-mail you when a seat is free.'),
            'course_closed' => t('Registration for this course has closed.'),
            'course_already' => $hello . t('we already have a registration with this e-mail address for {course}. If you want to change it, please contact us.'),
            'course_take_over' => t('We register you with the details of your cancelled registration.'),
            // the sentences of Core\Booking and Front\Booking, so their translations (and today's mails) carry over
            'booking_confirmation' => t('Thank you, your appointment is booked.') . "\n\n{appointment}"
                . "\n\n" . t('Add it to your calendar: %s', '{calendar link}')
                . "\n\n" . t('If you cannot come, cancel the appointment by %s with this link: %s', '{cancel deadline}', '{cancel link}'),
            'booking_pending' => t('Thank you, we have received your request for an appointment. We will check it and get back to you – it is not confirmed yet.') . "\n\n{appointment}",
            'booking_declined' => t('Unfortunately we cannot offer you the requested time. We are sorry.') . "\n\n{message}\n\n{appointment}",
            'booking_proposal' => t('Unfortunately we cannot offer you the requested time, but these times are free:') . "\n\n{times}\n\n{message}"
                . "\n\n" . t('Pick one with this link: %s', '{choose link}')
                . "\n\n" . t('Your original request:') . "\n{appointment}",
            'booking_cancelled' => t('We are sorry – we had to cancel your appointment. Please book another time on our site, or contact us.') . "\n\n{appointment}",
            'booking_reminder' => t('A reminder of your appointment with us.') . "\n\n{appointment}",
            'booking_thanks' => t('Thank you, your appointment is booked. A confirmation with the details and a cancel link is on its way to your e-mail.'),
            'booking_pending_thanks' => t('Thank you, we have received your request and will get back to you soon. A note is on its way to your e-mail.'),
            'booking_cancel_page' => t('Your appointment is cancelled. You are welcome to book another time.'),
            default => '',
        };
    }

    /** The setting that holds an own text: `message_<id>_<part>`, with `_<language>` for a language version. */
    public static function key(string $id, string $part, string $language = ''): string
    {
        return 'message_' . $id . '_' . $part . ($language !== '' ? '_' . $language : '');
    }

    /**
     * The text to send or show: the own text of that language, for the default language the base key, else the translated template.
     * `$language` '' = the default language of the site (so is its own code).
     */
    public static function text(Settings $s, string $id, string $part, string $language): string
    {
        $default = Language::defaults($s);
        $language = $language === $default ? '' : $language;
        $own = trim($s->get(self::key($id, $part, $language)));

        return $own !== '' ? $own : Language::runWith($language !== '' ? $language : $default, fn (): string => self::template($id, $part));
    }

    /**
     * Fills the placeholders of the message with `$values` (name => text; a missing or empty value takes the {name|fallback}),
     * leaves everything else as it is, and (for a body, not a subject) adds each link whose placeholder is not in the text at the end.
     *
     * @param array<string, string> $values
     */
    public static function fill(string $text, array $values, string $id, bool $appendLinks = true): string
    {
        $m = self::CATALOGUE[$id];
        $links = [];
        foreach ($m['links'] as $link) {
            $value = trim($values[$link] ?? '');
            if ($appendLinks && $value !== '' && !self::mentions($text, $link)) {
                $links[] = $value;
            }
        }
        $known = [];
        foreach ([...$m['placeholders'], ...$m['links']] as $name) {
            $known[$name] = ($values[$name] ?? '');
        }
        // a link that was not given keeps its placeholder visible instead of vanishing silently; one given as '' (nothing to link) vanishes
        $known = array_filter($known, fn (string $v, string $name): bool => $v !== '' || !in_array($name, $m['links'], true) || array_key_exists($name, $values), ARRAY_FILTER_USE_BOTH);
        $text = FormMail::fill($text, [], [], true, $known);
        $text = rtrim((string) preg_replace("/\n{3,}/", "\n\n", $text)); // an empty {message} or link leaves no gap
        foreach ($links as $link) {
            $text = rtrim($text) . "\n\n" . $link;
        }

        return $text;
    }

    /** An error text naming the first placeholder the message does not have, else null. */
    public static function check(string $id, string $value): ?string
    {
        $m = self::CATALOGUE[$id] ?? null;
        if ($m === null) {
            return t('Unknown message.');
        }
        $allowed = array_map('mb_strtolower', [...$m['placeholders'], ...$m['links']]);
        preg_match_all(FormMail::TOKEN, $value, $found);
        foreach ($found[1] as $name) {
            if (!in_array(mb_strtolower(trim($name)), $allowed, true)) {
                return t('Unknown placeholder {%s}. Available: %s', trim($name), $allowed === [] ? t('none') : '{' . implode('}, {', [...$m['placeholders'], ...$m['links']]) . '}');
            }
        }

        return null;
    }

    /**
     * Mails a message to a person in their language: subject and body filled, the site signature added.
     *
     * @param array<string, string> $values   see fill(); 'site name' is added here
     * @param array<string, string> $headers
     */
    public static function send(App $app, string $id, string $language, string $to, array $values, array $headers = []): bool
    {
        $s = $app->settings();
        $values += ['site name' => $s->get('site_name')];
        // ponytail: the signature address is the setting site_url, else the origin of the request (as Core\Booking::signature)
        $site = rtrim($s->get('site_url') !== '' ? $s->get('site_url') : $app->request->origin(), '/');
        $default = Language::defaults($s);

        return (bool) Language::runWith($language !== '' ? $language : $default, function () use ($s, $id, $language, $to, $values, $headers, $site): bool {
            $subject = FormMail::subject(self::fill(self::text($s, $id, 'subject', $language), $values, $id, false));
            $body = self::fill(self::text($s, $id, 'body', $language), $values, $id) . "\n\n—\n" . $values['site name'] . "\n" . $site;

            return Mail::send($s, $to, $subject, $body, '', $headers);
        });
    }

    private static function mentions(string $text, string $name): bool
    {
        preg_match_all(FormMail::TOKEN, $text, $found);

        return in_array($name, array_map(fn (string $n): string => mb_strtolower(trim($n)), $found[1]), true);
    }
}
