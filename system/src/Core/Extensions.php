<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Extensions - optional parts of the system that the administrator turns on and off in Settings.
 *
 * The system is closed on purpose: all extensions are part of the package and are made in the Kaleta project.
 * Code of other developers comes as add-ons (Extension\Registry, 3.0) – not through this list. A disabled extension disappears from the administration menu and from the site, the data stays.
 */
final class Extensions
{
    /** key => [name, description, enabled by default] */
    public const array CATALOG = [
        'novinky' => ['Novinky', 'News and blog: the /novinky listing with categories and tags, RSS, the News element in the builder and a link in the automatic menu.', true],
        'poptavky' => ['Forms and enquiries', 'The Form element in the builder and the Enquiries inbox: submitted enquiries are stored, arrive by e-mail and can be passed to a colleague or a CRM.', true],
        'newsletter' => ['Newsletter', 'The Newsletter sign-up element in the builder: visitors enter an e-mail and confirm it by a link (double opt-in). Send them your latest news in an e-mail styled by the design system (through an SMTP server, while cron runs), pass confirmed subscribers to your mailing service (Brevo, MailerLite, Mailchimp, Ecomail, SmartEmailing, webhook), or export them to CSV.', false],
        'statistika' => ['Statistics', 'Your own cookie-free traffic analytics.', true],
        'presmerovani' => ['Redirects', '301 redirects from old addresses – essential after moving from another site.', true],
        'jazyky' => ['Language versions of the site', 'A site in several languages: each further version (e.g. /cs/…) has its own pages, categories and news, a language switcher and hreflang tags. Pick the languages in Settings → General.', false],
        'asistent' => ['AI assistant', 'In the builder, new sections from a description and text rewrites; in news, headlines, intro, SEO description, tags, proofreading, image descriptions and translation. Needs your own Claude, OpenAI, Google or Mistral key (below); text is sent only when you click an assistant button.', false],
        'fleet' => ['Fleet console', 'Makes this installation the console of your other Kaleta sites: they report to it every hour, it shows all of them on one screen sorted by what needs attention, checks that they are up and decides when they install a new version – test sites first, the rest two days later. It never gets into the sites.', false],
        'claude' => ['Claude connection', 'MCP server at /mcp: Claude builds pages in the builder with your account\'s permissions, edits the header, footer, collections and look, and writes news. Pages and news are saved as drafts that you publish, the menu and look go into the draft look; settings apply straight away. Everyone creates their access token under My account.', true],
    ];

    /** @return list<string> */
    public static function enabled(Settings $settings): array
    {
        $stored = $settings->get('extensions');
        if ($stored === '') {
            return array_keys(array_filter(self::CATALOG, fn (array $r): bool => $r[2]));
        }

        return array_values(array_intersect(explode(',', $stored), array_keys(self::CATALOG)));
    }

    public static function isEnabled(Settings $settings, string $key): bool
    {
        return $key === '' || in_array($key, self::enabled($settings), true);
    }

    /** @param list<string> $keys */
    public static function save(Settings $settings, array $keys): void
    {
        $keys = array_values(array_intersect($keys, array_keys(self::CATALOG)));
        // an empty string means "default state", so an empty selection is saved as "-"
        $settings->set('extensions', $keys === [] ? '-' : implode(',', $keys));
    }
}
