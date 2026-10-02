<?php

declare(strict_types=1);

namespace Kaleta\Admin;

/**
 * Links from each part of the administration to its article in the guide on kaletacms.com (2.4). The articles have the
 * same address in every language; the admin language picks the version (/guide, /cs/guide, /de/guide). The anchors
 * are the English headings – in another language the link simply opens the top of the article.
 */
final class Guide
{
    public const string BASE = 'https://kaletacms.com';

    /** Languages the guide is written in; other admin languages get the English one. */
    public const array LANGUAGES = ['en', 'cs', 'de'];

    /** module => article#anchor; '' is the Dashboard, 'account' is My account */
    public const array MODULES = [
        '' => 'first-steps#the-dashboard',
        'account' => 'claude-connect',
        'pages' => 'page-settings',
        'news' => 'news#writing',
        'categories' => 'news',
        'tags' => 'news',
        'collections' => 'collections',
        'facts' => 'company-details#business-facts',
        'blueprints' => 'industry-blueprints',
        'enquiries' => 'forms#enquiries',
        'subscribers' => 'newsletter',
        'newsletters' => 'newsletter#send-newsletters-from-kaleta',
        'media' => 'media',
        'appearance' => 'site-appearance',
        'parts' => 'site-parts',
        'menu' => 'menus',
        'components' => 'components',
        'popups' => 'popups',
        'users' => 'users-roles',
        'roles' => 'users-roles#custom-roles',
        'stats' => 'statistics',
        'redirects' => 'seo#redirects-and-404s',
        'audit' => 'seo#site-audit',
        'changelog' => 'backups-updates',
        'transfer' => 'wordpress-import',
        'extensions' => 'extensions',
        'fleet' => 'fleet-console',
    ];

    /** Settings tab => article#anchor */
    public const array SETTINGS = [
        'general' => 'languages#how-language-versions-work',
        'company' => 'company-details',
        'seo' => 'seo#settings-seo-and-geo',
        'analytics' => 'statistics#settings-analytics',
        'cookies' => 'privacy-cookies',
        'mail' => 'email',
        'webhooks' => 'forms#connecting-other-tools',
        'backups' => 'backups-updates',
        'firewall' => 'site-health#firewall',
        'console' => 'fleet-console#pair-a-site',
        'health' => 'site-health',
    ];

    /** The builder of a page, news item, collection template, site part, component or pop-up */
    public const array BUILDER = [
        'parts' => 'site-parts',
        'components' => 'components',
        'popups' => 'popups',
        'collections' => 'collection-lists',
    ];

    public static function url(string $article, string $language): string
    {
        return self::BASE . (in_array($language, self::LANGUAGES, true) && $language !== 'en' ? '/' . $language : '') . '/guide/' . $article;
    }

    /** The guide to the open screen, or null. $module '' = Dashboard, 'account' = My account. */
    public static function forScreen(string $module, string $action, string $tab, string $language): ?string
    {
        if ($action === 'builder') {
            return self::url(self::BUILDER[$module] ?? 'builder-basics', $language);
        }
        if ($module === 'settings') {
            return self::url(self::SETTINGS[$tab] ?? self::SETTINGS['general'], $language);
        }
        $article = self::MODULES[$module] ?? null;

        return $article === null ? null : self::url($article, $language);
    }
}
