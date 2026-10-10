<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Core\Antispam;
use Talea\Core\Booking as Bookings;

/**
 * Online booking of an appointment (3.0, Core\Booking): the visitor picks a service, a person (or anyone), a day and a
 * free time, leaves a name, e-mail and phone and agrees to the processing. Sent to /_booking (Front\Booking).
 *
 * The server renders a plain form that works without image/web.js: the services and people as radio buttons and a
 * select of the next free times of the chosen service (?booking=<id>&service=<service> reloads it for another service).
 * With the script the select becomes a small month calendar of days with free times (/_booking/days) and the times of
 * the chosen day (/_booking/slots).
 */
final class Booking extends Element
{
    public const string TYPE = 'booking';
    public const string NAME = 'Booking';
    public const string DESCRIPTION = 'Online booking of an appointment: a service, a person, a day and a free time – the bookings are in Bookings and arrive by e-mail.';
    public const string ICON = 'form';
    public const string GROUP = 'Dynamic';
    public const string EXTENSION = 'bookings';
    public const array HTML_TAGS = ['form'];

    /** How many of the next free times the plain form offers. */
    private const int FALLBACK_SLOTS = 40;

    public static function properties(): array
    {
        return [
            'service' => ['type' => 'number', 'label' => 'Service (id from Bookings → Services; 0 = the visitor chooses)', 'default' => 0, 'min' => 0, 'max' => 1000000],
            'staff_member' => ['type' => 'number', 'label' => 'Person (id from Bookings → People; 0 = the visitor chooses, or anyone)', 'default' => 0, 'min' => 0, 'max' => 1000000],
            'button_text' => ['type' => 'text', 'label' => 'Button text', 'default' => t('Book the appointment'), 'max' => 80],
            'thank_you' => ['type' => 'text', 'label' => 'Thank-you message', 'default' => t('Thank you, your appointment is booked. A confirmation with the details and a cancel link is on its way to your e-mail.'), 'max' => 400],
            'consent' => ['type' => 'text', 'label' => 'Consent text (a required checkbox)', 'default' => t('I agree to the processing of my personal data for the purpose of this appointment.'), 'max' => 300],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-booking { display: grid; gap: var(--tl-space-m); }
.tl-booking, .tl-booking-done { scroll-margin-top: 6rem; }
.tl-booking fieldset { display: grid; gap: var(--tl-space-2xs); margin: 0; padding: 0; border: 0; }
.tl-booking legend { margin-block-end: var(--tl-space-2xs); padding: 0; font-weight: 600; }
.tl-booking-options { display: grid; gap: var(--tl-space-2xs); }
.tl-booking-options label { display: flex; gap: var(--tl-space-xs); align-items: flex-start; padding: 0.6em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); cursor: pointer; }
.tl-booking-options label:has(:checked) { border-color: var(--tl-color-primary); background: var(--tl-color-primary-soft); }
.tl-booking-options input { margin-block-start: 0.3em; accent-color: var(--tl-color-primary); }
.tl-booking-options small { display: block; color: var(--tl-color-muted); }
.tl-booking-calendar { display: grid; gap: var(--tl-space-xs); }
.tl-booking-month { display: flex; align-items: center; justify-content: space-between; gap: var(--tl-space-xs); font-weight: 600; }
.tl-booking-month button { padding: 0.3em 0.7em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: inherit; font: inherit; cursor: pointer; }
.tl-booking-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.tl-booking-days span, .tl-booking-days button { display: grid; place-items: center; min-height: 2.4em; border: 0; border-radius: var(--tl-radius); background: none; color: inherit; font: inherit; }
.tl-booking-days span { color: var(--tl-color-muted); font-size: var(--tl-step--1); }
.tl-booking-days button { background: var(--tl-color-surface); cursor: pointer; }
.tl-booking-days button:disabled { background: none; color: var(--tl-color-muted); cursor: default; text-decoration: line-through; }
.tl-booking-days button[aria-pressed="true"], .tl-booking-times button[aria-pressed="true"] { background: var(--tl-color-primary); color: var(--tl-color-on-primary); }
.tl-booking-times { display: flex; flex-wrap: wrap; gap: var(--tl-space-2xs); }
.tl-booking-times button { padding: 0.5em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: inherit; font: inherit; cursor: pointer; }
.tl-booking-selected { margin: 0; font-weight: 600; }
.tl-booking .tl-field { display: grid; gap: var(--tl-space-2xs); margin: 0; }
.tl-booking .tl-field > label { font-weight: 600; }
.tl-booking .tl-field input:not([type="checkbox"]):not([type="radio"]), .tl-booking .tl-field select, .tl-booking .tl-field textarea { box-sizing: border-box; width: 100%; padding: 0.7em 0.9em; border: 1px solid var(--tl-color-line); border-radius: var(--tl-radius); background: var(--tl-color-background); color: var(--tl-color-text); font: inherit; }
.tl-booking .tl-field-consent label { display: flex; gap: var(--tl-space-xs); align-items: flex-start; font-weight: 400; }
.tl-booking .tl-field-consent input { margin-block-start: 0.3em; accent-color: var(--tl-color-primary); }
.tl-booking .tl-required { color: var(--tl-color-primary); }
.tl-booking-error, .tl-booking-done { margin: 0; padding: var(--tl-space-m); border-radius: var(--tl-radius); }
.tl-booking-done { background: var(--tl-color-primary-soft); color: var(--tl-color-text); }
.tl-booking-error { background: color-mix(in oklch, #c4281c 12%, var(--tl-color-background)); color: color-mix(in oklch, #c4281c 80%, var(--tl-color-text)); }
.tl-booking-empty { margin: 0; color: var(--tl-color-muted); }
.tl-booking [hidden] { display: none !important; }'; // the display of the calendar, the times and the fallback field would otherwise beat the hidden attribute
    }

    /** The anchor the page returns to after sending: the same as the id the form gets when rendered. */
    public static function anchor(array $p): string
    {
        return $p['anchor'] ?? (!empty($p['style']) ? 's-' . $p['id'] : 'booking-' . $p['id']);
    }

    /** The message after sending by the code in the url (?booking=<id>&result=<code>) – the text never comes from the url. */
    public static function messages(string $code): string
    {
        return match ($code) {
            'taken' => t('Sorry, this time has just been taken. Please choose another one.'),
            'slot' => t('Please choose a day and a time.'),
            'service' => t('Please choose a service.'),
            'name' => t('Please fill in your name.'),
            'email' => t('Enter a valid e-mail address.'),
            'phone' => t('Phone number, for example +44 20 7946 0958.'),
            'consent' => t('Please tick the consent.'),
            'limit' => t('Too many bookings have come from your address in a short time. Please try again later.'),
            'too_fast' => t('The form was sent before we could check that a person is sending it. Please wait a moment and send it again.'),
            'captcha' => t('Please confirm that you are not a robot and send the form again.'),
            default => t('The form could not be verified. Reload the page and try again.'),
        };
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['content'];
        $r = $k->app->request;
        $db = $k->app->db();
        $result = $r->get('booking') === $p['id'] ? $r->get('result') : '';
        $id = str_contains($a, ' id="') ? '' : ' id="' . e(self::anchor($p)) . '"';
        if ($result === 'pending') { // a service that needs the provider's confirmation: a request, not a booking (3.3)
            return '<div' . Text::withClass($a, 'tl-booking-done') . $id . ' role="status" data-sent="' . e(t('Booking')) . '"><p>' . e(Bookings::pendingThanks($k->app->settings())) . '</p></div>';
        }
        if ($result === 'ok') {
            return '<div' . Text::withClass($a, 'tl-booking-done') . $id . ' role="status" data-sent="' . e(t('Booking')) . '"><p>' . e($o['thank_you']) . '</p></div>';
        }
        $services = Bookings::services($db);
        $staff = Bookings::staff($db);
        $fixedService = (int) $o['service'] > 0 ? Bookings::service($db, (int) $o['service'], true) : null;
        if ($fixedService !== null) {
            $services = [$fixedService];
        }
        $services = array_values(array_filter($services, fn (array $s): bool => Bookings::staffFor($db, $s['id']) !== []));
        if ($services === []) {
            return $k->editor ? '<div' . Text::withClass($a, 'tl-booking-empty') . '><p>' . e(t('Add a service and a person who offers it in Administration → Bookings; the form appears here.')) . '</p></div>' : '';
        }
        // the plain form (no script) shows the next free times of one service: the only one, the fixed one, or the one asked for
        $asked = $r->get('booking') === $p['id'] ? $db->internalId('booking_services', $r->get('service')) : 0;
        $chosen = $asked > 0 ? $asked : (count($services) === 1 ? $services[0]['id'] : 0);
        $serviceKey = fn (int $id): string => $db->publicId('booking_services', $id); // what the page shows: public ids, never the row numbers
        $staffKey = fn (int $id): string => $db->publicId('booking_staff', $id);
        $chosenService = null;
        foreach ($services as $s) {
            if ($s['id'] === $chosen) {
                $chosenService = $s;
            }
        }
        $fixedStaff = (int) $o['staff_member'] > 0 ? Bookings::member($db, (int) $o['staff_member'], true) : null;
        if (!$k->editor) {
            $k->withoutCache = true; // the free times change with every booking
        }
        $html = $result !== '' ? '<p class="tl-booking-error" role="alert">' . e(self::messages($result)) . '</p>' : '';
        $name = 'r-' . $p['id'];

        // 1. the service
        $html .= '<fieldset class="tl-booking-step" data-step="service"><legend>' . e(t('Service')) . '</legend><div class="tl-booking-options">';
        foreach ($services as $i => $s) {
            $meta = implode(' · ', array_filter([t('%d min', $s['duration_min']), $s['price_text']]));
            $html .= '<label><input type="radio" name="service" value="' . $serviceKey($s['id']) . '" required data-duration="' . $s['duration_min'] . '"' . (!empty($s['requires_confirmation']) ? ' data-confirmation="1"' : '') . ($s['id'] === ($chosenService['id'] ?? ($fixedService !== null || count($services) === 1 ? $s['id'] : 0)) ? ' checked' : '') . '>'
                . '<span>' . e($s['name']) . '<small>' . e($meta) . ($s['description'] !== '' ? ' – ' . e($s['description']) : '') . '</small></span></label>';
        }
        $html .= '</div></fieldset>';

        // 2. the person – only when there is a choice
        $offering = array_values(array_filter($staff, fn (array $m): bool => array_intersect($m['services'], array_column($services, 'id')) !== []));
        if ($fixedStaff !== null) {
            $html .= '<input type="hidden" name="staff" value="' . $staffKey($fixedStaff['id']) . '">';
        } elseif (count($offering) > 1) {
            $html .= '<fieldset class="tl-booking-step" data-step="person"><legend>' . e(t('Who')) . '</legend><div class="tl-booking-options">'
                . '<label><input type="radio" name="staff" value="0" checked><span>' . e(t('Anyone available')) . '</span></label>';
            foreach ($offering as $m) {
                $html .= '<label data-services="' . e(implode(',', array_map($serviceKey, $m['services']))) . '"><input type="radio" name="staff" value="' . $staffKey($m['id']) . '"><span>' . e($m['name']) . '</span></label>';
            }
            $html .= '</div></fieldset>';
        } else {
            $html .= '<input type="hidden" name="staff" value="0">';
        }

        // 3. the day and the time: the calendar (script) and the plain select
        $html .= '<fieldset class="tl-booking-step" data-step="time"><legend>' . e(t('Day and time')) . '</legend>'
            . '<div class="tl-booking-calendar" data-calendar hidden></div>'
            . '<div class="tl-booking-times" data-times hidden></div>'
            . '<p class="tl-booking-selected" data-selected hidden></p>'
            . '<div class="tl-field" data-no-script>';
        if ($chosenService !== null) {
            $options = '';
            $staffId = $fixedStaff !== null ? $fixedStaff['id'] : 0;
            foreach (self::nextSlots($k->app, $chosenService, $staffId) as $slot) {
                $options .= '<option value="' . e($slot) . '">' . e(format_date($slot, true)) . '</option>';
            }
            $html .= '<label for="' . $name . '-slot">' . e(t('Free times')) . ' <span class="tl-required" aria-hidden="true">*</span></label>'
                . ($options === '' ? '<p class="tl-booking-empty">' . e(t('There are no free times at the moment. Please contact us.')) . '</p><select id="' . $name . '-slot" name="slot" hidden></select>'
                    : '<select id="' . $name . '-slot" name="slot" required><option value="">' . e(t('— choose —')) . '</option>' . $options . '</select>');
        } else {
            $html .= '<select name="slot" hidden></select><button class="tl-button" type="submit" formmethod="get" formaction="' . e($k->app->url($r->path())) . '" name="booking" value="' . e($p['id']) . '">' . e(t('Show free times')) . '</button>';
        }
        $html .= '</div></fieldset>';

        // 4. the contact
        $html .= '<fieldset class="tl-booking-step" data-step="contact"><legend>' . e(t('Your details')) . '</legend>'
            . '<p class="tl-field"><label for="' . $name . '-name">' . e(t('Your name')) . ' <span class="tl-required" aria-hidden="true">*</span></label><input id="' . $name . '-name" name="name" type="text" autocomplete="name" maxlength="150" required></p>'
            . '<p class="tl-field"><label for="' . $name . '-email">' . e(t('Your e-mail')) . ' <span class="tl-required" aria-hidden="true">*</span></label><input id="' . $name . '-email" name="email" type="email" autocomplete="email" maxlength="190" required></p>'
            . '<p class="tl-field"><label for="' . $name . '-phone">' . e(t('Phone')) . '</label><input id="' . $name . '-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" pattern="' . Form::PHONE_PATTERN . '" title="' . e(t('Phone number, for example +44 20 7946 0958.')) . '"></p>'
            . '<p class="tl-field"><label for="' . $name . '-note">' . e(t('Note')) . '</label><textarea id="' . $name . '-note" name="note" rows="3" maxlength="1000"></textarea></p>'
            . '<p class="tl-field tl-field-consent"><label><input type="checkbox" name="consent" value="1" required> <span>' . e($o['consent']) . ' <span class="tl-required" aria-hidden="true">*</span></span></label>'
            . (($policy = \Talea\Core\Privacy::policyUrl($k->app->settings())) !== '' ? ' <a class="tl-field-policy" href="' . e($policy) . '" target="_blank">' . e(t('Privacy policy')) . '</a>' : '') . '</p>'
            . '</fieldset>';

        // a service that needs confirmation is requested, not booked: the default button says so (the script follows the chosen service)
        $buttonText = $o['button_text'];
        $buttonData = '';
        if ($o['button_text'] === t('Book the appointment') && array_filter($services, fn (array $s): bool => !empty($s['requires_confirmation'])) !== []) {
            $buttonData = ' data-request="' . e(t('Request this time')) . '" data-book="' . e($o['button_text']) . '"';
            $selected = $chosenService ?? ($fixedService !== null || count($services) === 1 ? $services[0] : null);
            $buttonText = $selected !== null && !empty($selected['requires_confirmation']) ? t('Request this time') : $buttonText;
        }
        $antispam = new Antispam($db, $k->app->settings());
        $k->types['button'] = true; // the button looks like the Button element

        return '<form' . Text::withClass($a, 'tl-booking') . $id . ' method="post" action="' . e($k->url('_booking')) . '" data-booking="' . e($p['id']) . '" data-days-url="' . e($k->url('_booking/days')) . '" data-slots="' . e($k->url('_booking/slots')) . '">'
            . '<input type="hidden" name="source" value="' . e($k->source) . '"><input type="hidden" name="element" value="' . e($p['id']) . '">'
            . '<input type="hidden" name="back" value="' . e($k->app->url($r->path())) . '">'
            . $antispam->fields('booking|' . $k->source . '|' . $p['id'])
            . $html
            . Form::captcha($k)
            . '<p class="tl-field"><button class="tl-button tl-button--primary" type="submit"' . $buttonData . '>' . e($buttonText) . '</button></p></form>';
    }

    /**
     * The next free times of a service for the plain form: day by day from today until enough are found or the horizon ends.
     *
     * @param array<string, mixed> $service
     * @return list<string> "YYYY-MM-DD HH:MM"
     */
    private static function nextSlots(\Talea\Core\App $app, array $service, int $staffId): array
    {
        $out = [];
        $now = new \DateTimeImmutable();
        $horizon = $app->settings()->int('booking_horizon_days');
        for ($i = 0; $i <= $horizon && count($out) < self::FALLBACK_SLOTS; $i++) {
            $day = $now->modify('+' . $i . ' days')->format('Y-m-d');
            foreach (array_keys(Bookings::availability($app, $service, $staffId, $day, $now)) as $time) {
                $out[] = $day . ' ' . $time;
                if (count($out) >= self::FALLBACK_SLOTS) {
                    break;
                }
            }
        }

        return $out;
    }
}
