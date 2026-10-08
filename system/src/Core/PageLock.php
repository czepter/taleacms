<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Password-protected pages (2.14): a price list for partners, documents for one client, a page for the members of a
 * club – behind one password the administrator gives to the people who should read it. Not an account system: whoever
 * knows the password reads the page.
 *
 *  - Only a password_hash() is stored (ka_stranky.heslo_hash). The visitor who enters the password gets a cookie for
 *    30 days bound to the page and to the hash, so a new password locks everyone out again.
 *  - A protected page is never in the page cache, the sitemap, llms.txt or the site search, and it is noindex; users who
 *    can edit pages see it without the password.
 *  - Wrong passwords are limited per visitor address – an IPv6 address by its /64 (ATTEMPTS per WINDOW seconds) – and
 *    per page across all addresses (PAGE_ATTEMPTS), counted without the database. Past the page's cap only wrong
 *    passwords are refused; the right one still opens the page (3.3.3).
 */
final class PageLock
{
    public const int ATTEMPTS = 10;

    /** Wrong passwords for one page from all addresses together per WINDOW (3.3.2): many addresses cannot share the guessing. */
    public const int PAGE_ATTEMPTS = 100;

    public const int WINDOW = 900;

    public const int DAYS = 30;

    public const int MIN_LENGTH = 6;

    /** @param array<string, mixed> $page */
    public static function isProtected(array $page): bool
    {
        return (string) ($page['heslo_hash'] ?? '') !== '';
    }

    /** @param array<string, mixed> $page */
    public static function isUnlocked(App $app, array $page): bool
    {
        $cookie = $_COOKIE[self::cookie((int) $page['ids'])] ?? '';

        return is_string($cookie) && hash_equals(self::token($app, $page), $cookie);
    }

    /**
     * Checks the password a visitor entered; on success sets the cookie. Returns '' when unlocked, otherwise the message
     * for the visitor.
     *
     * @param array<string, mixed> $page
     */
    public static function unlock(App $app, array $page, string $password): string
    {
        $ip = Firewall::visitorIp($app->request->serverValues(), $app->settings()->get('firewall_proxy'));
        $pageKey = 'page-' . (int) $page['ids'];
        if (Firewall::count(Antispam::network($ip !== '' ? $ip : 'unknown'), 'page-lock', self::WINDOW) > self::ATTEMPTS) {
            return t('Too many attempts. Try again in a few minutes.');
        }
        if ($password === '' || !password_verify($password, (string) $page['heslo_hash'])) {
            // only wrong passwords count for the page; past its cap they are refused as "too many attempts" (3.3.3, N58)
            return Firewall::count($pageKey, 'page-lock-all', self::WINDOW) > self::PAGE_ATTEMPTS
                ? t('Too many attempts. Try again in a few minutes.') : t('The password is not right.');
        }
        // the right password always opens the page, past the page's cap too (3.3.3, N58): wrong guesses from many addresses
        // must not lock out every reader of it
        if (!headers_sent()) {
            setcookie(self::cookie((int) $page['ids']), self::token($app, $page), ['expires' => time() + self::DAYS * 86400, 'path' => $app->request->basePath() . '/',
                'httponly' => true, 'samesite' => 'Lax', 'secure' => $app->request->isHttps()]);
        }

        return '';
    }

    /**
     * What the administrator typed into the password field: null = leave as it is, '' = remove the protection, otherwise
     * the new hash. A password shorter than MIN_LENGTH is an error.
     *
     * @return array{0: ?string, 1: string} [the value for heslo_hash or null to keep it, an error]
     */
    public static function fromForm(string $password, bool $remove): array
    {
        if ($remove) {
            return ['', ''];
        }
        if ($password === '') {
            return [null, ''];
        }
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return [null, t('The page password must have at least %d characters.', self::MIN_LENGTH)];
        }

        return [password_hash($password, PASSWORD_DEFAULT), ''];
    }

    /** The password form shown instead of the page content, in the site's form styles. @param array<string, mixed> $page */
    public static function form(array $page, string $error): string
    {
        return '<div class="ka-porovnani-stranka"><h1>' . e((string) $page['titulek']) . '</h1><p>' . e(t('This page is protected with a password.')) . '</p>'
            . ($error !== '' ? '<p class="ka-formular-chyba" role="alert">' . e($error) . '</p>' : '')
            . '<form class="ka-formular" method="post"><p class="ka-pole"><label for="ka-heslo-stranky">' . e(t('Password')) . '</label>'
            . '<input type="password" id="ka-heslo-stranky" name="ka_heslo_stranky" autocomplete="current-password" required></p>'
            . '<p class="ka-pole"><button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e(t('Open the page')) . '</button></p></form></div>';
    }

    private static function cookie(int $ids): string
    {
        return 'ka_stranka_' . $ids;
    }

    /** @param array<string, mixed> $page */
    private static function token(App $app, array $page): string
    {
        return hash_hmac('sha256', 'page|' . (int) $page['ids'] . '|' . (string) $page['heslo_hash'], (new Antispam($app->db(), $app->settings()))->key());
    }
}
