<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Mail;

/**
 * News subscription (Newsletter extension): sign-up from the News subscription element, confirmation and unsubscribe by
 * a link from the e-mail. An address counts as a subscriber only after confirmation (double opt-in). Whoever signs up
 * a second time only gets a new link – the site does not reveal to anyone whether the address is already on the list.
 */
final class Subscription
{
    private const int LIMIT = 5; // sign-ups from one address per 10 minutes

    public function __construct(private readonly App $app)
    {
    }

    /**
     * POST from the element: saves or renews an unconfirmed address and sends the link.
     *
     * @return string result for the element's message (ok | chyba | limit)
     */
    public function subscribe(): string
    {
        $r = $this->app->request;
        $antispam = new Antispam($this->app->db(), $this->app->settings());
        $reason = $antispam->verify($r, 'odber');
        if ($reason === 'robot') {
            return 'ok';
        }
        if ($reason !== null) {
            return 'error';
        }
        if ($antispam->count($r->ip(), 'odber', 0, 10) >= self::LIMIT) {
            return 'limit';
        }
        if (!\Kaleta\Core\Captcha::accepted($this->app->settings(), \Kaleta\Core\Captcha::verify($this->app->settings(), $r))) {
            return 'captcha';
        }
        $antispam->write($r->ip(), 'odber', 0);
        $email = mb_strtolower(trim($r->post('email')));
        if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'error';
        }
        $db = $this->app->db();
        $subscriber = $db->one('SELECT * FROM {subscribers} WHERE email = ?', [$email]);
        if ($subscriber !== null && (int) $subscriber['status'] === 1) {
            return 'ok'; // already subscribed – we send nothing more
        }
        $token = bin2hex(random_bytes(16));
        if ($subscriber === null) {
            [$landing, $visitCampaign] = Forms::attribution($r); // with the visitor's consent to marketing (2.3)
            $db->insert('subscribers', ['email' => $email, 'token' => $token, 'created_at' => date('Y-m-d H:i:s'), 'source' => mb_substr($r->post('zpet'), 0, 255),
                'campaign' => Forms::campaign($r->referer(), $r->origin()) ?: $visitCampaign, 'landing_page' => $landing]);
        } else {
            $db->update('subscribers', ['token' => $token, 'created_at' => date('Y-m-d H:i:s')], ['subscriber_id' => (int) $subscriber['subscriber_id']]);
        }
        $siteSettings = $this->app->settings();
        $link = $this->address('odber?confirm=' . $token);
        Mail::send($siteSettings, $email, t('Confirm your subscription – %s', $siteSettings->get('site_name')),
            t('Hello,') . "\n\n" . t('to confirm your subscription to news from %s, click the link:', $siteSettings->get('site_name')) . "\n" . $link . "\n\n"
            . t('If you did not ask to subscribe, ignore this e-mail – without confirmation we will not send you anything.') . "\n");

        return 'ok';
    }

    /**
     * Link from the e-mail (?confirm= / ?unsubscribe=). Opening the link (GET) only shows a button – mail link scanners
     * (Safe Links etc.) would otherwise confirm the subscription or unsubscribe the subscriber on their own. The change
     * happens only on submission (POST), unsubscribing also with one click from the mail client (List-Unsubscribe-Post).
     *
     * @return array{0: string, 1: string} page title and content (HTML)
     */
    public function link(): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $action = preg_match('/^[a-f0-9]{32}$/', $r->get('confirm')) ? 'confirm' : (preg_match('/^[a-f0-9]{32}$/', $r->get('unsubscribe')) ? 'unsubscribe' : '');
        $o = $action !== '' ? $db->one('SELECT * FROM {subscribers} WHERE token = ?', [$r->get($action)]) : null;
        if ($o === null) {
            return [t('The link is no longer valid'), '<p>' . e(t('The link is invalid or has already been used. If you want to receive news, please subscribe again.')) . '</p>'];
        }
        if (!$r->isPost()) {
            [$heading, $text, $button] = $action === 'confirm'
                ? [t('Confirm subscription'), t('Please confirm that you want to receive news at %s.', $o['email']), t('Confirm subscription')]
                : [t('Unsubscribe'), t('Do you really no longer want to receive news at %s?', $o['email']), t('Unsubscribe')];

            return [$heading, '<p>' . e($text) . '</p><form method="post" action="' . e($this->app->url('odber') . '?' . $action . '=' . $o['token']) . '"><p><button class="tlacitko" type="submit">' . e($button) . '</button></p></form>'];
        }
        if ($action === 'unsubscribe') {
            $db->delete('subscribers', ['subscriber_id' => (int) $o['subscriber_id']]);
            if ((int) $o['status'] === 1) {
                \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'remove'); // from the mailing service too
            }

            return [t('Unsubscribed'), '<p>' . e(t('We have removed %s from the subscriber list.', $o['email'])) . '</p>'];
        }
        if ((int) $o['status'] === 0) {
            $db->update('subscribers', ['status' => 1, 'confirmed_at' => date('Y-m-d H:i:s')], ['subscriber_id' => (int) $o['subscriber_id']]);
            \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'add'); // to the mailing service, sent by the background cleanup
        }

        return [t('Subscription confirmed'), '<p>' . e(t('Thank you, we will send news to %s. You can unsubscribe using the link in every e-mail.', $o['email'])) . '</p>'];
    }

    /** Unsubscribe link for the mailing tool (subscriber export). */
    public static function unsubscribeLink(App $app, string $token): string
    {
        return (new self($app))->address('odber?unsubscribe=' . $token);
    }

    private function address(string $path): string
    {
        return rtrim($this->app->settings()->get('site_url') ?: $this->app->request->origin(), '/') . $this->app->url($path);
    }
}
