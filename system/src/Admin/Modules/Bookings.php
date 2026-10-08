<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Booking;
use Kaleta\Core\Response;

/**
 * Online booking of appointments (3.0, Core\Booking): the upcoming bookings by day with filters, the detail with done /
 * did not come / cancel, a manual booking (a customer who phoned), and – for administrators – the set-up: services, people
 * with their weekly hours and days off, and the settings (lead time, horizon, cancel deadline, reminder).
 *
 * Bookings hold personal data: a user needs the permission for this section (like Enquiries); they follow the enquiry
 * retention and Core\PersonalData finds and erases them.
 */
final class Bookings extends Module
{
    public const string IDENT = 'bookings';
    public const string NAME = 'Bookings';
    public const string GROUP = 'Company';
    public const string ICON = 'rezervace';
    public const string EXTENSION = 'bookings'; // a feature (3.2): off on new installs, switched on by 0073 where a site uses it

    protected function actionList(): Response
    {
        Booking::purge($this->app);
        $view = in_array($this->request->get('pohled'), ['dnes', 'minule', 'vse'], true) ? $this->request->get('pohled') : 'nadchazejici';
        $filter = match ($view) {
            'dnes' => ['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'status' => ''],
            'minule' => ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => date('Y-m-d', strtotime('-1 day')), 'status' => ''],
            'vse' => ['from' => '', 'to' => '', 'status' => ''],
            default => ['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+30 days')), 'status' => 'active'],
        };
        $filter['staff'] = $this->request->getInt('osoba');
        $filter['service'] = $this->request->getInt('sluzba');
        if (isset(Booking::STATUSES[$this->request->get('stav')])) {
            $filter['status'] = $this->request->get('stav');
        }
        $byDay = [];
        foreach (Booking::list($this->db, $filter + ['limit' => 500]) as $b) {
            $byDay[substr((string) $b['starts_at'], 0, 10)][] = $b;
        }
        if ($view === 'minule' || $view === 'vse') {
            krsort($byDay);
        }
        $s = $this->app->settings();

        return $this->view('list', 'Bookings', ['byDay' => $byDay, 'shown' => $view, 'filter' => $filter, 'services' => Booking::services($this->db, false), 'staff' => Booking::staff($this->db, false),
            'settings' => ['lead' => $s->int('booking_lead_hours'), 'horizon' => $s->int('booking_horizon_days'), 'cancel' => $s->int('booking_cancel_hours'), 'reminder' => $s->int('booking_reminder_hours'), 'hold' => $s->int('booking_hold_hours'),
                'pendingThanks' => $s->get('booking_pending_thanks'), 'pendingMail' => $s->get('booking_pending_mail'), 'declinedMail' => $s->get('booking_declined_mail')],
            'waiting' => (int) $this->db->value("SELECT COUNT(*) FROM {bookings} WHERE status = 'pending' AND ends_at > NOW()"),
            'months' => $s->int('enquiries_months'), 'expiry' => $s->get('enquiries_expiry') === 'anonymise' ? 'anonymise' : 'delete', 'isAdmin' => $this->app->auth()->isAdmin()]);
    }

    protected function actionDetail(): Response
    {
        $b = Booking::find($this->db, $this->request->getInt('id'));
        if ($b === null) {
            return $this->error('The booking does not exist.', 404);
        }

        $free = [];
        if ($b['status'] === 'pending' && ($service = Booking::service($this->db, (int) $b['service_id'])) !== null) {
            // the free times of the next days for this person, to propose from (no lead time, the booking's own hold ignored)
            $now = new \DateTimeImmutable();
            for ($i = 0; $i < 21 && count($free) < 30; $i++) {
                $day = $now->modify('+' . $i . ' days')->format('Y-m-d');
                foreach (array_keys(Booking::availability($this->app, $service, (int) $b['staff_id'], $day, $now, true, (int) $b['id'])) as $time) {
                    $free[] = $day . ' ' . $time;
                }
            }
        }

        return $this->view('detail', t('Booking') . ' #' . $b['id'], ['b' => $b, 'deadline' => Booking::cancelDeadline($this->app->settings(), $b),
            'proposals' => Booking::proposals($this->db, (int) $b['id']), 'free' => array_slice($free, 0, 60)]);
    }

    /** Accept a pending booking: it becomes confirmed and the customer gets the confirmation. */
    protected function actionConfirm(): Response
    {
        $id = $this->request->postInt('id');
        $b = $this->request->isPost() ? Booking::find($this->db, $id) : null;
        if ($b === null) {
            return $this->back();
        }
        $error = Booking::confirm($this->app, $b, 'admin');

        return $this->back($error ?? ((string) $b['email'] !== '' ? 'The booking is accepted and the customer got the confirmation.' : 'The booking is accepted.'), 'detail', ['id' => $id], $error === null ? 'ok' : 'chyba');
    }

    /** Decline a pending booking: the time is free again, the customer is told (with the optional message). */
    protected function actionDecline(): Response
    {
        $id = $this->request->postInt('id');
        $b = $this->request->isPost() ? Booking::find($this->db, $id) : null;
        if ($b === null) {
            return $this->back();
        }
        $done = Booking::decline($this->app, $b, $this->request->post('message'), 'admin');

        return $this->back($done ? 'The request is declined and the customer was told by e-mail.' : 'Only a booking waiting for confirmation can be declined.', 'detail', ['id' => $id], $done ? 'ok' : 'chyba');
    }

    /** Propose one to three other times: the customer picks one with the link in the e-mail. */
    protected function actionPropose(): Response
    {
        $id = $this->request->postInt('id');
        $b = $this->request->isPost() ? Booking::find($this->db, $id) : null;
        if ($b === null) {
            return $this->back();
        }
        $error = Booking::propose($this->app, $b, array_map('strval', $this->request->postList('slots')), $this->request->post('message'), 'admin');

        return $this->back($error ?? 'The other times were sent to the customer.', 'detail', ['id' => $id], $error === null ? 'ok' : 'chyba');
    }

    /** Done, did not come, or cancel (the customer gets an e-mail). */
    protected function actionStatus(): Response
    {
        $id = $this->request->postInt('id');
        $b = $this->request->isPost() ? Booking::find($this->db, $id) : null;
        if ($b === null) {
            return $this->back();
        }
        $status = $this->request->post('stav');
        if ($status === 'cancelled') {
            return $this->back(Booking::cancel($this->app, $b, 'admin') ? ((string) $b['email'] !== '' ? 'The booking is cancelled and the customer was told by e-mail.' : 'The booking is cancelled.') : 'Only a confirmed booking can be cancelled.', 'detail', ['id' => $id], 'ok');
        }

        return $this->back(Booking::setStatus($this->app, $b, $status) ? 'Saved.' : 'Only a confirmed booking can be marked.', 'detail', ['id' => $id]);
    }

    /** Blanks the person in one booking and keeps the row (Core\Booking::anonymise). */
    protected function actionAnonymise(): Response
    {
        $id = $this->request->postInt('id');
        if ($this->request->isPost()) {
            Booking::anonymise($this->app, $id);
        }

        return $this->back('The booking was anonymised – the row stays for statistics without the person.', 'detail', ['id' => $id]);
    }

    /** A manual booking: a customer who phoned or walked in. */
    protected function actionNew(): Response
    {
        return $this->view('new', 'New booking', ['services' => Booking::services($this->db), 'staff' => Booking::staff($this->db), 'old' => $this->app->session->get('booking_form') ?? []]);
    }

    protected function actionCreate(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $r = $this->request;
        $input = ['service_id' => $r->postInt('sluzba'), 'staff_id' => $r->postInt('osoba'), 'slot' => trim($r->post('den')) . ' ' . trim($r->post('cas')), 'name' => $r->post('jmeno'), 'email' => $r->post('email'),
            'phone' => $r->post('telefon'), 'note' => $r->post('poznamka'), 'source' => 'admin', 'language' => '', 'by' => 'admin'];
        [$booking, $error] = Booking::book($this->app, $input);
        if ($booking === null) {
            $this->app->session->set('booking_form', ['sluzba' => $input['service_id'], 'osoba' => $input['staff_id'], 'den' => $r->post('den'), 'cas' => $r->post('cas'), 'jmeno' => $input['name'], 'email' => $input['email'], 'telefon' => $input['phone'], 'poznamka' => $input['note']]);

            return $this->back(match ($error) {
                'taken' => 'This time is not free for the chosen person – pick another time.',
                'service' => 'Choose a service.',
                'slot' => 'Enter the day as YYYY-MM-DD and the time as HH:MM.',
                'email' => 'Enter a valid e-mail address, or leave it empty.',
                'phone' => 'Enter a valid phone number, or leave it empty.',
                default => 'Enter the name of the customer.',
            }, 'new', [], 'chyba');
        }
        $this->app->session->set('booking_form', null);

        return $this->back((string) $booking['email'] !== '' ? 'The booking is saved and the confirmation was sent to the customer.' : 'The booking is saved.', 'detail', ['id' => (int) $booking['id']]);
    }

    /* ---------- the set-up (administrators) ---------- */

    private function adminOnly(): ?Response
    {
        return $this->app->auth()->isAdmin() ? null : $this->error('Only an administrator changes the booking set-up.', 403);
    }

    protected function actionServices(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        $id = $this->request->getInt('id');

        return $this->view('services', 'Services', ['services' => Booking::services($this->db, false), 'staff' => Booking::staff($this->db, false), 'edit' => $id > 0 ? Booking::service($this->db, $id) : ($this->request->get('nova') !== '' ? [] : null)]);
    }

    protected function actionServiceSave(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'services');
        }
        $r = $this->request;
        $result = Booking::saveService($this->app, ['name' => $r->post('name'), 'duration_min' => $r->postInt('duration_min'), 'buffer_min' => $r->postInt('buffer_min'), 'price_text' => $r->post('price_text'),
            'description' => $r->post('description'), 'active' => $r->postBool('active'), 'requires_confirmation' => $r->postBool('requires_confirmation'), 'sort_order' => $r->postInt('sort_order'), 'staff' => array_map('intval', $r->postList('staff'))], $r->postInt('id'));

        return is_string($result) ? $this->back($result, 'services', $r->postInt('id') > 0 ? ['id' => $r->postInt('id')] : ['nova' => 1], 'chyba') : $this->back('The service is saved.', 'services');
    }

    protected function actionServiceDelete(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        $error = $this->request->isPost() ? Booking::deleteService($this->app, $this->request->postInt('id')) : null;

        return $this->back($error ?? 'The service is deleted.', 'services', [], $error === null ? 'ok' : 'chyba');
    }

    protected function actionStaff(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }

        return $this->view('staff', 'People', ['staff' => Booking::staff($this->db, false), 'services' => Booking::services($this->db, false), 'offs' => Booking::offs($this->db, null)]);
    }

    protected function actionStaffEdit(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        $id = $this->request->getInt('id');
        $member = $id > 0 ? Booking::member($this->db, $id) : [];
        if ($member === null) {
            return $this->error('The person does not exist.', 404);
        }
        $hours = $id > 0 ? Booking::hours($this->db, $id) : [];

        return $this->view('staff_edit', $member === [] ? 'New person' : (string) $member['name'], ['m' => $member, 'services' => Booking::services($this->db, false),
            'hours' => array_map(fn (int $d): string => \Kaleta\Core\Hours::rangesText($hours[$d] ?? []), array_combine(array_keys(Booking::WEEKDAYS), array_keys(Booking::WEEKDAYS))),
            'offs' => $id > 0 ? Booking::offs($this->db, $id) : [], 'siteWeek' => \Kaleta\Core\Hours::week($this->app->settings()),
            'users' => $this->db->pairs("SELECT idu, IF(jmeno = '', user, jmeno) FROM {uzivatele} WHERE blokovat = 0 ORDER BY 2")]);
    }

    protected function actionStaffSave(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        if (!$this->request->isPost()) {
            return $this->back('', 'staff');
        }
        $r = $this->request;
        $hours = [];
        foreach (array_keys(Booking::WEEKDAYS) as $d) {
            $hours[$d] = $r->post('hours_' . $d);
        }
        $id = $r->postInt('id');
        $result = Booking::saveStaff($this->app, ['name' => $r->post('name'), 'email' => $r->post('email'), 'active' => $r->postBool('active'), 'user_id' => $r->postInt('user_id'), 'sort_order' => $r->postInt('sort_order'),
            'services' => array_map('intval', $r->postList('services')), 'hours' => $hours], $id);
        if (is_string($result)) {
            return $this->back($result, 'staff_edit', $id > 0 ? ['id' => $id] : [], 'chyba');
        }

        return $this->back('The person is saved.', 'staff_edit', ['id' => (int) $result['id']]);
    }

    protected function actionStaffDelete(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        $error = $this->request->isPost() ? Booking::deleteStaff($this->app, $this->request->postInt('id')) : null;

        return $this->back($error ?? 'The person is removed.', 'staff', [], $error === null ? 'ok' : 'chyba');
    }

    /** A day off for one person (from their form) or for everyone (from the People list). */
    protected function actionOffSave(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        $staffId = $this->request->postInt('staff_id');
        $error = $this->request->isPost() ? Booking::saveOff($this->app, $staffId, $this->request->post('off_from'), $this->request->post('off_to'), $this->request->post('note')) : null;

        return $this->back($error ?? 'The day off is saved.', $staffId > 0 ? 'staff_edit' : 'staff', $staffId > 0 ? ['id' => $staffId] : [], $error === null ? 'ok' : 'chyba');
    }

    protected function actionOffDelete(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        $staffId = $this->request->postInt('staff_id');
        if ($this->request->isPost()) {
            Booking::deleteOff($this->app, $this->request->postInt('id'));
        }

        return $this->back('The day off is removed.', $staffId > 0 ? 'staff_edit' : 'staff', $staffId > 0 ? ['id' => $staffId] : []);
    }

    /** Lead time, horizon, cancel deadline and the reminder. */
    protected function actionSettings(): Response
    {
        if (($refused = $this->adminOnly()) !== null) {
            return $refused;
        }
        if ($this->request->isPost()) {
            $s = $this->app->settings();
            $s->set('booking_lead_hours', (string) max(0, min(720, $this->request->postInt('lead'))));
            $s->set('booking_horizon_days', (string) max(1, min(365, $this->request->postInt('horizon'))));
            $s->set('booking_cancel_hours', (string) max(0, min(720, $this->request->postInt('cancel'))));
            $s->set('booking_reminder_hours', (string) max(0, min(168, $this->request->postInt('reminder'))));
            $s->set('booking_hold_hours', (string) max(1, min(720, $this->request->postInt('hold'))));
            // own texts: empty = the built-in one; {name} is the customer's name (form of address and tone are the site's)
            $s->set('booking_pending_thanks', mb_substr(trim(strip_tags($this->request->post('pending_thanks'))), 0, 400));
            $s->set('booking_pending_mail', mb_substr(trim(strip_tags($this->request->post('pending_mail'))), 0, 1000));
            $s->set('booking_declined_mail', mb_substr(trim(strip_tags($this->request->post('declined_mail'))), 0, 1000));
            \Kaleta\Admin\ChangeLog::write($this->app, 'bookings', 'settings', '');
        }

        return $this->back('Booking settings saved.');
    }
}
