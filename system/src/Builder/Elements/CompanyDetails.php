<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * A detail from Settings (address, phone, company ID, opening hours, copyright, social networks…) – filled in once and changed everywhere.
 * The company in „Nastavení → Firma“ (Business details), the site in „Nastavení → Základní“ (Settings → General).
 */
final class CompanyDetails extends Element
{
    public const string TYPE = 'company_details';
    public const string NAME = 'Company details';
    public const string DESCRIPTION = 'Address, phone, email, company ID, opening hours, map, copyright or social networks from Settings.';
    public const string ICON = 'company_details';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['p', 'div', 'span', 'address'];

    /** Marker of opening hours not filled in on the site: a container in which only a heading remains besides it is left out (Container::render). */
    public const string EMPTY_HOURS = '<!--ka-empty-hours-->';

    public static function properties(): array
    {
        return ['detail' => ['type' => 'choice', 'label' => 'Detail', 'default' => 'copyright', 'options' => [
            'address' => 'URL', 'phone' => 'Phone', 'email' => 'Email', 'hours' => 'Opening hours', 'open_now' => 'Open now (and until when)', 'map' => 'Map link',
            'company' => 'Registered name and company ID', 'imprint' => 'Imprint (all details of the operator)', 'copyright' => '© year and site name', 'name' => 'Site name', 'description' => 'Site description',
            'footer_text' => 'Footer text', 'social' => 'Follow us', 'rss' => 'RSS link',
        ]]];
    }

    public static function baseCss(): string
    {
        return '.ka-hours { margin: 0; padding: 0; list-style: none; }
.ka-detail:is(address) { font-style: normal; }
.ka-site { display: flex; flex-wrap: wrap; gap: var(--ka-space-xs) var(--ka-space-s); margin: 0; padding: 0; list-style: none; }
.ka-site a, .ka-detail a { color: inherit; }
.ka-imprint { display: grid; grid-template-columns: max-content 1fr; gap: var(--ka-space-2xs) var(--ka-space-m); margin: 0; }
.ka-imprint dt { font-weight: 600; }
.ka-imprint dd { margin: 0; }
@media (max-width: 600px) { .ka-imprint { grid-template-columns: 1fr; } .ka-imprint dd { margin-block-end: var(--ka-space-xs); } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $siteSettings = $k->app->settings();
        $z = $p['tag'];
        $wrapper = fn (string $html): string => $html === '' && !$k->editor ? '' : '<' . $z . Text::withClass($a, 'ka-detail') . '>' . ($html !== '' ? $html : e(t('(fill in under Business details)'))) . '</' . $z . '>';

        return match ($p['content']['detail']) {
            'copyright' => $wrapper('&copy; ' . date('Y') . ' ' . e($siteSettings->get('site_name'))),
            'name' => $wrapper(e($siteSettings->get('site_name'))),
            'description' => $wrapper(e($siteSettings->get('site_description'))),
            'footer_text' => $wrapper(e($siteSettings->get('footer_text'))),
            'email' => $wrapper(($mail = $siteSettings->get('company_email')) !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : ''),
            'rss' => \Kaleta\Core\Extensions::isEnabled($siteSettings, 'news') ? $wrapper('<a href="' . e($k->url('rss.xml')) . '">RSS</a>') : '', // no RSS without news
            'address' => $wrapper(implode('<br>', array_map(e(...), \Kaleta\Front\Company::address($siteSettings)))),
            'phone' => $wrapper($siteSettings->get('company_phone') !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $siteSettings->get('company_phone'))) . '">' . e($siteSettings->get('company_phone')) . '</a>' : ''),
            'map' => $wrapper($siteSettings->get('company_map') !== '' ? '<a href="' . e($siteSettings->get('company_map')) . '" target="_blank" rel="noopener">' . e(t('Show on map')) . '</a>' : ''),
            'company' => $wrapper(implode('<br>', array_map(e(...), array_filter([
                $siteSettings->get('company_name'),
                trim(($siteSettings->get('company_id') !== '' ? t('Company ID') . ' ' . $siteSettings->get('company_id') : '') . ($siteSettings->get('company_vat_id') !== '' ? ', ' . t('VAT ID') . ' ' . $siteSettings->get('company_vat_id') : ''), ', '),
            ])))),
            'hours' => ($rows = [...\Kaleta\Front\Company::openingHoursLines($siteSettings), ...self::upcomingExceptions($k)]) !== []
                ? '<ul' . Text::withClass($a, 'ka-hours') . '>' . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $rows)) . '</ul>'
                : ($k->editor ? $wrapper('') : self::EMPTY_HOURS),
            // open now, until when / when it opens next – with the exceptions (2.10); the page must not be cached for long
            'open_now' => $wrapper(e(\Kaleta\Core\Hours::statusText($k->app))),
            'social' => self::networks($siteSettings, $a, $k),
            'imprint' => self::imprint($siteSettings, $a, $k),
            default => '',
        };
    }

    /**
     * Exceptions to the opening hours in the next 30 days, as lines under the regular hours (2.10).
     *
     * @return list<string>
     */
    private static function upcomingExceptions(Context $k): array
    {
        $limit = date('Y-m-d', strtotime('+30 days'));

        return array_map(\Kaleta\Core\Hours::describe(...), array_values(array_filter(\Kaleta\Core\Hours::exceptions($k->app->db()), fn (array $e): bool => $e['from'] <= $limit)));
    }

    /**
     * Imprint (Impressum): who operates the site – business name, registered office, identification numbers, registry entry,
     * representation and contact. Outputs only the details filled in under „Nastavení → Firma“ (Business details).
     */
    private static function imprint(\Kaleta\Core\Settings $siteSettings, string $a, Context $k): string
    {
        $phone = $siteSettings->get('company_phone');
        $mail = $siteSettings->get('company_email');
        $rows = array_filter([
            t('Operator') => e($siteSettings->get('company_name') !== '' ? $siteSettings->get('company_name') : $siteSettings->get('site_name')),
            t('Registered office') => implode('<br>', array_map(e(...), \Kaleta\Front\Company::address($siteSettings))),
            t('Company ID') => e($siteSettings->get('company_id')),
            t('VAT ID') => e($siteSettings->get('company_vat_id')),
            t('Commercial register') => e($siteSettings->get('company_register')),
            t('Represented by') => e($siteSettings->get('company_representative')),
            t('Phone') => $phone !== '' ? '<a href="tel:' . e((string) preg_replace('/[^\d+]/', '', $phone)) . '">' . e($phone) . '</a>' : '',
            t('Email') => $mail !== '' ? '<a href="mailto:' . e($mail) . '">' . e($mail) . '</a>' : '',
        ], fn (string $h): bool => $h !== '');
        if (count($rows) < 2 && $k->editor) {
            return '<p' . $a . '>' . e(t('(fill in under Business details)')) . '</p>';
        }

        return '<dl' . Text::withClass($a, 'ka-imprint') . '>' . implode('', array_map(fn (string $n, string $h): string => '<dt>' . e($n) . '</dt><dd>' . $h . '</dd>', array_keys($rows), $rows)) . '</dl>';
    }

    private static function networks(\Kaleta\Core\Settings $siteSettings, string $a, Context $k): string
    {
        $networks = array_filter(['LinkedIn' => $siteSettings->get('social_linkedin'), 'Facebook' => $siteSettings->get('social_facebook'), 'Instagram' => $siteSettings->get('social_instagram'), 'YouTube' => $siteSettings->get('social_youtube'), 'X' => $siteSettings->get('social_x')]);
        if ($networks === []) {
            return $k->editor ? '<p' . $a . '>' . e(t('Add social networks under Settings.')) . '</p>' : '';
        }

        return '<ul' . Text::withClass($a, 'ka-site') . '>' . implode('', array_map(fn (string $n, string $u): string => '<li><a href="' . e($u) . '" rel="me noopener" target="_blank">' . e($n) . '</a></li>', array_keys($networks), $networks)) . '</ul>';
    }
}
