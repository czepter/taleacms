<?php

declare(strict_types=1);

namespace Talea\Install;

use Talea\Builder\Build;
use Talea\Builder\ElementSchema;
use Talea\Builder\Library;
use Talea\Builder\PartTemplates;
use Talea\Core\Db;
use Talea\Core\Language;

/**
 * The first content of a new site: Home, About us, Services, Contact, the privacy policy, the header and footer and the settings of the chosen
 * look. The installer writes it into the real database and Preview into a scratch one, so the preview shows what will be installed.
 */
final class Starter
{
    /**
     * @param array<string, mixed> $look a look of Builder\Looks
     * @param list<string> $extensions enabled extensions (sections with elements of disabled ones are left out)
     * @param array<string, string> $settings further settings to store (site_url, site_email, time_zone …)
     */
    public static function create(Db $db, string $siteName, string $siteLanguage, array $look, array $extensions, string $basePath, array $settings): void
    {
        $x = fn (string $text): string => Language::runWith($siteLanguage, fn (): string => t($text));
        // skeleton of a typical company site – the home page comes from the look's sections, the texts are only a guide to what belongs on the page
        $pages = [
            [$x('Home'), 0, '<h1>' . e($siteName) . '</h1><p>' . e($x('In one sentence: what you do and for whom. Edit this page in the administration under Pages.')) . '</p>', $look['sections']],
            [$x('About us'), 1, '<p>' . e($x('Who you are, how long you have been doing it and why customers trust you.')) . '</p>', Library::STARTER_PAGES[0]],
            [$x('Services'), 1, '<p>' . e($x('What you offer – each service briefly and clearly.')) . '</p>', Library::STARTER_PAGES[1]],
            [$x('Contact'), 1, '<p>' . e($x('Address, phone, e-mail and opening hours.')) . '</p>', Library::STARTER_PAGES[2]],
        ];
        $home = 0;
        foreach ($pages as $i => [$title, $inMenu, $text, $sections]) {
            $row = ['title' => $title, 'slug' => slugify($title), 'text' => $text, 'in_menu' => $inMenu, 'sort_order' => ($i + 1) * 10];
            if ($sections !== []) {
                // sections with elements of disabled extensions (news list, form) are not put on the pages, neither are empty images
                $build = Library::page($db, $sections, $i === 0 ? $siteName : $title, $siteLanguage, ElementSchema::disabledTypes($extensions), true);
                $row['build'] = Build::toJson($build);
                $row['text'] = Build::asText($build);
            }
            $id = $db->insert('pages', $row);
            $home = $home ?: $id;
        }

        // privacy policy: a skeleton to fill in, in the site language, hidden until the administrator fills it in and publishes it (First steps
        // remind of it); outside the main menu, linked from the footer, the cookie bar and the consent in the form
        [$privacyPolicy, $privacyPolicyText] = Language::runWith($siteLanguage, fn (): array => [t('Privacy policy'), Library::privacyPolicyText()]);
        $privacyPolicyId = $db->insert('pages', ['title' => $privacyPolicy, 'slug' => slugify($privacyPolicy), 'text' => $privacyPolicyText, 'visible' => 0, 'in_menu' => 0, 'sort_order' => 90]);
        \Talea\Core\Menu::save($db, 'footer', '', [['type' => 'page', 'page_id' => $privacyPolicyId, 'text' => '']]);
        \Talea\Core\Search::complete($db);

        // the look: header and footer templates and the design system
        foreach (['header', 'footer'] as $type) {
            if (($build = PartTemplates::build($type, $look[$type], $siteLanguage, $extensions)) !== null) {
                $db->insert('site_parts', ['type' => $type, 'language' => '', 'build' => Build::toJson($build), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
        $settings += ['site_name' => $siteName, 'site_language' => $siteLanguage, 'design_system' => (string) json_encode($look['design_system'], JSON_UNESCAPED_SLASHES),
            'home_page' => (string) $home, 'extensions' => $extensions === [] ? '-' : implode(',', $extensions), 'cookies_policy_url' => $basePath . '/' . slugify($privacyPolicy)];
        foreach ($settings as $key => $value) {
            $db->insert('settings', ['name' => $key, 'value' => $value]);
        }
    }
}
