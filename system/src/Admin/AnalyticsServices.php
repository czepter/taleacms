<?php

declare(strict_types=1);

namespace Talea\Admin;

/**
 * The tracking services of Settings → Analytics. Each has a row in the overview, a page of its own (?service=<page>) and,
 * except the custom code, a card in the "Connect a service" wizard. The fields are settings of the analytics tab
 * (Modules\Settings::FIELDS); front-end output stays in Front\Seo.
 */
final class AnalyticsServices
{
    /**
     * key => [name, group, when it runs (RUNS), fields (setting => required for "connected"), page]; the advertising pixels
     * share one page, every other service has its own.
     */
    public const array SERVICES = [
        'google-analytics' => ['Google Analytics', 'count', 'analytics', ['ga4_id' => true], 'google-analytics'],
        'matomo' => ['Matomo', 'count', 'analytics', ['matomo_url' => true, 'matomo_id' => true], 'matomo'],
        'plausible' => ['Plausible', 'count', 'none', ['plausible_domain' => true, 'plausible_url' => false], 'plausible'],
        'umami' => ['Umami', 'count', 'none', ['umami_id' => true, 'umami_url' => false], 'umami'],
        'tag-manager' => ['Google Tag Manager', 'tags', 'either', ['gtm_id' => true], 'tag-manager'],
        'meta-pixel' => ['Meta Pixel', 'ads', 'marketing', ['meta_pixel_id' => true], 'pixels'],
        'google-ads' => ['Google Ads', 'ads', 'marketing', ['google_ads_id' => true], 'pixels'],
        'linkedin' => ['LinkedIn Insight Tag', 'ads', 'marketing', ['linkedin_partner_id' => true], 'pixels'],
        'tiktok' => ['TikTok Pixel', 'ads', 'marketing', ['tiktok_pixel_id' => true], 'pixels'],
        'microsoft-ads' => ['Microsoft Advertising (UET)', 'ads', 'marketing', ['microsoft_uet_id' => true], 'pixels'],
        'pinterest' => ['Pinterest Tag', 'ads', 'marketing', ['pinterest_tag_id' => true], 'pixels'],
        'custom-code' => ['Custom code in the head', 'code', 'always', ['head_code' => true], 'custom-code'],
    ];

    /** The pages in the settings menu, in its order: page => name. */
    public const array PAGES = ['google-analytics' => 'Google Analytics', 'tag-manager' => 'Google Tag Manager', 'matomo' => 'Matomo', 'plausible' => 'Plausible',
        'umami' => 'Umami', 'pixels' => 'Advertising pixels', 'custom-code' => 'Custom code'];

    /** group => [heading, note] of the overview and the wizard */
    public const array GROUPS = [
        'count' => ['Count visitors', 'Without cookies they run right away; with cookies only after the visitor allows analytics.'],
        'tags' => ['Manage tags', 'A container loads whatever its owner sets up in it.'],
        'ads' => ['Measure advertising (remarketing)', 'Pixels run only after the visitor allows marketing. Only the ID is needed.'],
        'code' => ['Custom code', 'For a service without a page of its own.'],
    ];

    /** When a service runs, as the overview and the wizard say it. */
    public const array RUNS = ['none' => 'Right away, without cookies', 'analytics' => 'After consent to analytics', 'marketing' => 'After consent to marketing',
        'either' => 'After consent to analytics or marketing', 'always' => 'On every page, regardless of consent'];

    /** The page or service named by ?service=, '' for the overview, 'connect' for the wizard's first step. */
    public static function resolve(string $key): string
    {
        return $key === 'connect' || isset(self::PAGES[$key]) || isset(self::SERVICES[$key]) ? $key : '';
    }

    /** The menu page a page or service belongs to. */
    public static function page(string $key): string
    {
        return self::SERVICES[$key][4] ?? (isset(self::PAGES[$key]) ? $key : '');
    }

    /** @return list<string> the services a page (or a single service) shows */
    public static function services(string $key): array
    {
        return isset(self::PAGES[$key]) ? array_keys(array_filter(self::SERVICES, fn (array $s): bool => $s[4] === $key)) : (isset(self::SERVICES[$key]) ? [$key] : []);
    }

    /** @param array<string, string> $values settings by key */
    public static function connected(string $service, array $values): bool
    {
        foreach (self::SERVICES[$service][3] as $field => $required) {
            if ($required && in_array(trim((string) ($values[$field] ?? '')), ['', '0'], true)) {
                return false;
            }
        }

        return true;
    }
}
