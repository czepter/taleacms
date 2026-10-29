<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\ChangeLog;
use Talea\Builder\Build;
use Talea\Builder\ElementSchema;
use Talea\Builder\Library;
use Talea\Builder\Looks;

/**
 * Onboarding without AI (#46): what the site is about (a topic that re-ranks the looks gallery, Builder\Looks), what it should be
 * able to do (features that switch on built-in extensions and add starter pages) and a look tried on with one of its palettes and
 * font pairs. Using the look puts it into the draft look, switches the chosen extensions on and adds the starter pages the site does
 * not have yet as hidden drafts – nothing is published.
 */
final class Onboarding
{
    /**
     * What the site should be able to do: key => [English answer, the extension it switches on ('' = none), the starter page it adds
     * ('' = none)]. Only what Talea has – no shop, courses, donations or invoices.
     */
    public const array FEATURES = [
        'contact' => ['Offer a contact form', 'enquiries', 'contact'],
        'bookings' => ['Let visitors book appointments', 'bookings', 'bookings'],
        'services' => ['Present your services', '', 'services'],
        'work' => ['Show your work', '', 'work'],
        'blog' => ['Publish a blog or news', 'news', ''],
        'newsletter' => ['Send a newsletter', 'newsletter_signup', ''],
        'members' => ['Offer content for members', 'members', ''],
    ];

    /** Starter pages: key => [title (site dictionary), Library sections]. "About us" comes with every look. */
    public const array PAGES = [
        'about' => Library::PAGE_TEMPLATES['about-us'],
        'services' => Library::PAGE_TEMPLATES['services'],
        'work' => ['Our work', ['page-title', 'portfolio', 'gallery', 'call-to-action']],
        'bookings' => ['Bookings', ['page-title', 'booking']],
        'contact' => Library::PAGE_TEMPLATES['contact'],
    ];

    /** @return list<string> the known feature keys of a list from a form or a URL */
    public static function features(mixed $input): array
    {
        return array_values(array_intersect(array_keys(self::FEATURES), is_array($input) ? array_filter($input, is_string(...)) : []));
    }

    /**
     * Uses a look: the extensions of the features are switched on, the look (with the palette and font pair) goes to the draft look
     * and the starter pages the site does not have yet are added as hidden drafts. Null for an unknown look or variant.
     *
     * @param list<string> $features
     * @return array{look: string, extensions: list<string>, pages: list<array{title: string, url: string}>}|null
     */
    public static function apply(App $app, string $look, int $palette, int $fonts, array $features): ?array
    {
        if (Looks::variant($look, $palette, $fonts) === null) {
            return null;
        }
        $s = $app->settings();
        $before = Extensions::enabled($s);
        Extensions::save($s, array_values(array_unique([...$before, ...array_filter(array_map(fn (string $f): string => self::FEATURES[$f][1], $features))])));
        // after the extensions: the footer templates offer the newsletter sign-up only while it is on
        Looks::apply($app, $look, $palette, $fonts);
        $s->set('appearance_saved', '1');
        $pages = [];
        foreach (['about', ...array_filter(array_map(fn (string $f): string => self::FEATURES[$f][2], $features))] as $key) {
            if (($page = self::page($app, $key)) !== null) {
                $pages[] = $page;
            }
        }
        \Talea\Front\Cache::clear();
        ChangeLog::write($app, 'wizard', 'onboarding', mb_substr($look . ' ' . $palette . '/' . $fonts . ($features !== [] ? ', ' . implode(', ', $features) : ''), 0, 250));

        return ['look' => (string) Looks::get($look)['name'], 'extensions' => array_values(array_diff(Extensions::enabled($s), $before)), 'pages' => $pages];
    }

    /**
     * A starter page as a hidden draft in the default language, from Library sections; null when the site has a page of that address.
     *
     * @return array{title: string, url: string}|null
     */
    private static function page(App $app, string $key): ?array
    {
        $db = $app->db();
        $s = $app->settings();
        $code = Language::defaults($s);
        $title = Language::runWith($code, fn (): string => t(self::PAGES[$key][0]));
        $slug = slugify($title, 100);
        if (in_array($slug, \Talea\Front\Kernel::reservedSlugs(), true) || isset(Language::AVAILABLE[$slug]) || Routes::isNewsSlug($slug, $db) || Slug::taken($db, 'pages', $slug, '')) {
            return null;
        }
        $build = Library::page($db, self::PAGES[$key][1], $title, $code, ElementSchema::disabledTypes(Extensions::enabled($s)), true);
        $id = $db->insert('pages', ['title' => $title, 'slug' => $slug, 'language' => '', 'text' => '', 'visible' => 0, 'in_menu' => 1, 'build_draft' => Build::toJson($build), 'updated_at' => date('Y-m-d H:i:s')]);
        Slug::fromId($db, 'pages', $id);
        Menu::setPage($db, $id, '', true);

        return ['title' => $title, 'url' => $app->url('admin.php?module=pages&action=builder&id=' . $db->publicId('pages', $id))];
    }
}
