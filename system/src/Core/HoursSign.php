<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Front\Company;

/**
 * A door sign from an exception to the opening hours (2.10): a standalone printable page – A4 or A5 portrait – for the door
 * or the shop window. The site name and logo, a large headline (closed / changed opening hours), the dates in words, the note,
 * the hours when open differently, the regular week as the owner wrote it, and a QR code to the site with the address and the
 * phone under it. Written in the site's default language: the people reading it are visitors, not the owner.
 *
 * Nothing from elsewhere: the CSS is inline, the only image is the site's own logo, the Print button needs image/print.js
 * (the administration allows no inline scripts).
 */
final class HoursSign
{
    public const array FORMATS = ['a4', 'a5'];

    /**
     * The sign as a complete HTML document.
     *
     * @param array{from: string, to: string, closed: bool, hours: string, note: string} $exception
     * @param array<string, string> $formatUrls the sign in each format (the switch on screen), format => URL
     */
    public static function render(App $app, array $exception, string $format, array $formatUrls): string
    {
        $s = $app->settings();
        $siteUrl = rtrim($s->get('site_url') ?: $app->request->origin(), '/') . $app->url('');
        $logo = $s->get('logo');
        $logoUrl = $logo === '' ? '' : (preg_match('#^(https?:)?//|^/#', $logo) ? $logo : $app->request->basePath() . '/' . $logo);

        return Language::runWith(Language::defaults($s), fn (): string => $app->view->render('admin/settings/hours_sign', self::data($s, $exception, $siteUrl, $logoUrl) + [
            'format' => in_array($format, self::FORMATS, true) ? $format : 'a4',
            'formatUrls' => $formatUrls,
            'scriptUrl' => $app->url('image/print.js') . '?v=' . TALEA_VERSION,
        ]));
    }

    /**
     * What the sign says, in the language set when called (Language::runWith) – no database, so it is tested on its own.
     *
     * @param array{from: string, to: string, closed: bool, hours: string, note: string} $exception
     * @return array{language: string, siteName: string, logo: string, headline: string, dates: list<string>, note: string, hours: string, thanks: string, regular: list<string>, qr: string, address: string, phone: string}
     */
    public static function data(Settings $s, array $exception, string $siteUrl, string $logoUrl): array
    {
        $closed = (bool) $exception['closed'];
        $hours = $closed ? '' : Hours::rangesText((string) $exception['hours']);
        $address = (string) preg_replace('~^https?://~', '', rtrim($siteUrl, '/'));

        return [
            'language' => Language::code(),
            'siteName' => $s->get('site_name'),
            'logo' => $logoUrl,
            'headline' => $closed ? t('Closed') : t('Changed opening hours'),
            'dates' => $exception['from'] === $exception['to'] ? [format_date_long($exception['from'])] : [format_date_long($exception['from']), format_date_long($exception['to'])],
            'note' => (string) $exception['note'],
            'hours' => $hours !== '' ? t('Open %s', $hours) : '',
            'thanks' => $closed ? t('Thank you for your understanding.') : '',
            'regular' => Company::openingHoursLines($s),
            'qr' => Qr::svg($siteUrl, $address),
            'address' => $address,
            'phone' => $s->get('company_phone'),
        ];
    }
}
