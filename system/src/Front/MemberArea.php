<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Members;
use Talea\Core\Response;

/**
 * The public pages of the member login (/member, /member/verify, /member/signout; Core\Members) and the "sign in" / "no access" page
 * a gated page shows instead of its content. Every answer is built fresh for the visitor: Front\Kernel sends them as private,
 * no-store and noindex and never caches them.
 */
final class MemberArea
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array{0: string, 1: string, 2: int}|Response|null title, HTML content and status – or a redirect – or null for an address that is not here
     */
    public function handle(string $path): array|Response|null
    {
        $r = $this->app->request;
        $return = Members::safeReturn($r->isPost() ? $r->post('return') : $r->get('return'));

        return match ($path) {
            '/member' => $r->isPost() ? $this->request($return) : (Members::current($this->app) !== null ? $this->account() : [t('Sign in'), $this->signInForm($return, ''), 200]),
            '/member/verify' => $r->isPost() ? $this->verify($return) : $this->verifyPage($return),
            '/member/signout' => $r->isPost() ? $this->signOut() : Response::redirect($this->app->url('member'), 303),
            default => null,
        };
    }

    /** The page of a gated page for a visitor who may not read it. @return array{0: string, 1: string, 2: int} */
    public function gatePage(string $access, string $title): array
    {
        $member = Members::current($this->app);
        if ($access === 'denied' && $member !== null) {
            return [t('No access'), '<div class="tl-system-page"><h1>' . e(t('No access')) . '</h1><p>' . e(t('You are signed in as %s, but your account does not have access to “%s”.', (string) $member['email'], $title)) . '</p>'
                . self::signOutForm($this->app, t('Sign out')) . '</div>', 403];
        }
        if (!Members::enabled($this->app->settings())) {
            return [t('Members only'), '<div class="tl-system-page"><h1>' . e(t('Members only')) . '</h1><p>' . e(t('This page is only for members.')) . '</p></div>', 403];
        }

        return [t('Members only'), $this->signInForm(Members::safeReturn($this->app->request->path()), t('“%s” is only for members. Sign in to read it.', $title)), 403];
    }

    private function signInForm(string $return, string $intro, string $notice = '', bool $limit = false): string
    {
        $open = Members::openSignup($this->app->settings());

        return '<div class="tl-system-page"><h1>' . e(t('Sign in')) . '</h1>'
            . ($intro !== '' ? '<p>' . e($intro) . '</p>' : '')
            . '<p>' . e($open ? t('Enter your e-mail address and we send you a link that signs you in. If you are new, the same link creates your account. There is no password.')
                : t('Enter your e-mail address and we send you a link that signs you in. There is no password. Access is by invitation – if you have not been invited, ask the owner of the site.')) . '</p>'
            . ($notice !== '' ? '<p role="status">' . e($notice) . '</p>' : '')
            . ($limit ? '<p class="tl-form-error" role="alert">' . e(t('Too many attempts. Try again in a few minutes.')) . '</p>' : '')
            . '<form class="tl-form" method="post" action="' . e($this->app->url('member')) . '">'
            . '<div style="position:absolute;left:-9999px" aria-hidden="true"><label>' . e(t('Leave this field empty')) . ' <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="hidden" name="return" value="' . e($return) . '">'
            . '<p class="tl-field"><label for="tl-member-email">' . e(t('E-mail address')) . '</label><input type="email" id="tl-member-email" name="email" autocomplete="email" maxlength="190" required></p>'
            . '<p class="tl-field"><button class="tl-button tl-button--primary" type="submit">' . e(t('Send me the link')) . '</button></p></form></div>';
    }

    /** POST /member: the visitor asks for a link. The answer never depends on whether the address is known. @return array{0: string, 1: string, 2: int} */
    private function request(string $return): array
    {
        $r = $this->app->request;
        if (!Members::enabled($this->app->settings()) || Members::current($this->app) !== null) {
            return [t('Sign in'), $this->signInForm($return, ''), 200];
        }
        if (!Members::countRequest($this->app)) {
            return [t('Sign in'), $this->signInForm($return, '', '', true), 429];
        }
        if ($r->post('website') === '') { // a robot that fills the hidden field is told the same, and nothing is sent
            Members::requestLink($this->app, $r->post('email'), $return);
        }

        return [t('Check your e-mail'), '<div class="tl-system-page"><h1>' . e(t('Check your e-mail')) . '</h1><p>' . e(t('If this address can sign in, a link is on its way. It works once and for %d minutes.', Members::LINK_MINUTES)) . '</p></div>', 200];
    }

    /** GET of the link from the e-mail: only a button (mail scanners open links; the sign-in is the POST). @return array{0: string, 1: string, 2: int} */
    private function verifyPage(string $return): array
    {
        $token = $this->app->request->get('token');
        if (!Members::enabled($this->app->settings()) || !Members::linkWorks($this->app->db(), $token)) {
            return $this->expired();
        }

        return [t('Sign in'), '<div class="tl-system-page"><h1>' . e(t('Sign in')) . '</h1><p>' . e(t('Confirm to sign in on this device.')) . '</p>'
            . '<form class="tl-form" method="post" action="' . e($this->app->url('member/verify')) . '"><input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="return" value="' . e($return) . '">'
            . '<p class="tl-field"><button class="tl-button tl-button--primary" type="submit">' . e(t('Sign in')) . '</button></p></form></div>', 200];
    }

    /** POST of the button: uses the link up and signs the member in. @return array{0: string, 1: string, 2: int}|Response */
    private function verify(string $return): array|Response
    {
        if (!Members::enabled($this->app->settings())) {
            return $this->expired();
        }
        if (Members::overWrongLinkLimit($this->app)) {
            return [t('Sign in'), $this->signInForm('', '', '', true), 429];
        }
        $member = Members::useLink($this->app->db(), $this->app->request->post('token'));
        if ($member === null) {
            Members::countWrongLink($this->app);

            return $this->expired();
        }
        Members::startSession($this->app, (int) $member['member_id']);

        return Response::redirect($this->app->url($return === '' ? 'member' : ltrim($return, '/')), 303);
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function expired(): array
    {
        return [t('The link has expired'), '<div class="tl-system-page"><h1>' . e(t('The link has expired')) . '</h1><p>' . e(t('The link does not work any more – it works once and only for a short time. Ask for a new one.')) . '</p>'
            . '<p><a href="' . e($this->app->url('member')) . '">' . e(t('Sign in')) . '</a></p></div>', 400];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function account(): array
    {
        $member = Members::current($this->app) ?? [];
        $groups = array_column($this->app->db()->all('SELECT g.name FROM {member_group_links} l JOIN {member_groups} g ON g.group_id = l.group_id WHERE l.member_id = ? ORDER BY g.name', [(int) ($member['member_id'] ?? 0)]), 'name');

        return [t('Your account'), '<div class="tl-system-page"><h1>' . e(t('Your account')) . '</h1><p>' . e(t('You are signed in as %s.', (string) ($member['name'] ?? '') !== '' ? $member['name'] . ' (' . $member['email'] . ')' : (string) ($member['email'] ?? ''))) . '</p>'
            . ($groups !== [] ? '<p>' . e(t('Your groups: %s', implode(', ', $groups))) . '</p>' : '')
            . self::signOutForm($this->app, t('Sign out')) . '</div>', 200];
    }

    private function signOut(): Response
    {
        if (Members::csrfValid($this->app, $this->app->request->post('_csrf'))) {
            Members::signOut($this->app);
        }

        return Response::redirect($this->app->url(''), 303);
    }

    /** The sign-out button (a POST with a token bound to the member session). */
    public static function signOutForm(App $app, string $label): string
    {
        return '<form class="tl-form" method="post" action="' . e($app->url('member/signout')) . '"><input type="hidden" name="_csrf" value="' . e(Members::csrf($app)) . '">'
            . '<p class="tl-field"><button class="tl-button" type="submit">' . e($label) . '</button></p></form>';
    }
}
