<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Menu;
use Talea\Core\Slug;

/**
 * Site templates: a finished design to start a site from, not only a theme. A template is a look (Builder\Looks: tokens, header and
 * footer) plus pages of its own – a home page composed for it (Compose sections that overlap, frames sized like the final photos) and
 * further pages from library sections. Applying one puts the look into the draft look and the pages in as hidden drafts: nothing
 * public changes until the owner publishes. Texts are placeholders or neutral guidance in square brackets (#54, no invented content);
 * image frames are tinted shapes until a photo is chosen, so no third-party picture is shipped ("assets": none-third-party).
 */
final class SiteTemplates
{
    /**
     * key => [name, description, look key, topics (Looks::TOPICS), pages]: a page is [title, library section keys] or [title, 'home'] for the
     * template's own home page (home()).
     */
    public const array TEMPLATES = [
        'portrait' => ['Portrait', 'For photographers, film makers and artists: a large title over a tall photo, a selection of work in mixed formats, three packages side by side.', 'atelier',
            ['photography', 'art', 'weddings', 'design'],
            [['Home', 'home'], ['About me', ['page-title', 'story', 'values']], ['Packages', ['page-title', 'services-list', 'faq']], ['Contact', ['page-title', 'contact-form']]]],
        'workshop' => ['Workshop', 'For trades and services on site: a bold band with the call to action, what you guarantee, services side by side with photos and the steps of a job.', 'studio',
            ['crafts', 'construction', 'services', 'automotive'],
            [['Home', 'home'], ['Services', ['page-title', 'services-list', 'process', 'faq']], ['About us', ['page-title', 'story', 'team']], ['Contact', ['page-title', 'contact-form']]]],
        'editorial' => ['Editorial', 'For consultants, studios and agencies: an oversized statement, a photo beside a short story, signposts to the work and the people behind it.', 'monograph',
            ['consulting', 'agency', 'legal', 'finance'],
            [['Home', 'home'], ['Work', ['page-title', 'portfolio', 'testimonials']], ['About us', ['page-title', 'story', 'history', 'team']], ['Contact', ['page-title', 'contact-form']]]],
    ];

    /** @return list<array{key: string, name: string, description: string, look: string, look_name: string, topics: list<string>, pages: list<string>}> the templates whose look exists */
    public static function all(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $key => [$name, $description, $look, $topics, $pages]) {
            if (($definition = Looks::get($look)) !== null) {
                $out[] = ['key' => $key, 'name' => t($name), 'description' => t($description), 'look' => $look, 'look_name' => (string) $definition['name'], 'topics' => $topics,
                    'pages' => array_map(fn (array $p): string => t($p[0]), $pages)];
            }
        }

        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::TEMPLATES[$key]) && Looks::get(self::TEMPLATES[$key][2]) !== null;
    }

    /**
     * The builds of a template's pages in a language: [title, build, classes] per page, the home page first. Every build went through
     * Build::sanitize (one validator for everything that is stored).
     *
     * @return list<array{title: string, home: bool, build: array<string, mixed>, classes: list<string>}>
     */
    public static function pages(string $key, string $language): array
    {
        $out = [];
        foreach (self::TEMPLATES[$key][4] ?? [] as [$title, $sections]) {
            $out[] = Language::runWith($language, function () use ($key, $title, $sections, $language): array {
                $name = t($title);
                if ($sections === 'home') {
                    [$build] = Build::sanitize(['v' => Build::VERSION, 'children' => self::home($key)]);

                    return ['title' => $name, 'home' => true, 'build' => $build, 'classes' => self::classesOf($build['children'])];
                }
                [$build, $classes] = Library::assemble($sections, $name, $language);

                return ['title' => $name, 'home' => false, 'build' => $build, 'classes' => $classes];
            });
        }

        return $out;
    }

    /**
     * Applies a template as drafts: the look goes into the draft look and every page into a draft. A page lands on the site's own page
     * where there is one – the home page, or the page with the same title – as its build draft (visitors keep seeing the published page
     * until it is published); a page that already has a draft is never overwritten, the template's page then comes in as a new hidden page
     * (in the menu once it is published). A site without a home page (a new, empty one) gets the template's home page as its home page.
     *
     * @return list<array{id: string, title: string, home: bool, existing: bool}> the pages that got a draft
     */
    public static function apply(App $app, string $key): array
    {
        if (!self::exists($key)) {
            throw new \InvalidArgumentException('Unknown site template.');
        }
        $db = $app->db();
        Looks::apply($app, self::TEMPLATES[$key][2]);
        $language = Language::column($app->settings(), '');
        $content = Language::ofContent($app->settings(), $language);
        $homeId = (int) $app->settings()->get('home_page');
        $changed = [];
        foreach (self::pages($key, $content) as $page) {
            Library::createClasses($db, $page['classes']);
            $json = Build::toJson($page['build']);
            $own = $page['home'] && $homeId > 0
                ? $db->one('SELECT page_id, title, build_draft FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$homeId])
                : $db->one('SELECT page_id, title, build_draft FROM {pages} WHERE LOWER(title) = LOWER(?) AND language = ? AND deleted_at IS NULL ORDER BY page_id LIMIT 1', [$page['title'], $language]);
            if ($own !== null && $own['build_draft'] === null) {
                $db->update('pages', ['build_draft' => $json, 'updated_at' => date('Y-m-d H:i:s')], ['page_id' => (int) $own['page_id']]);
                $changed[] = ['id' => $db->publicId('pages', (int) $own['page_id']), 'title' => (string) $own['title'], 'home' => $page['home'], 'existing' => true];
                continue;
            }
            // a site without a home page (a new, empty one) takes the template's as its home page; otherwise it is an extra draft beside it
            $becomesHome = $page['home'] && $own === null;
            $title = $page['home'] && !$becomesHome ? t('%s (%s)', $page['title'], t(self::TEMPLATES[$key][0])) : $page['title'];
            $slug = Slug::makeUnique(slugify($title, 90), fn (string $s): bool => Slug::taken($db, 'pages', $s, $language)
                || in_array($s, \Talea\Front\Kernel::reservedSlugs(), true) || isset(Language::AVAILABLE[$s]), 100);
            $id = $db->insert('pages', ['title' => $title, 'slug' => $slug, 'language' => $language, 'text' => '', 'visible' => 0, 'in_menu' => $page['home'] ? 0 : 1,
                'build_draft' => $json, 'updated_at' => date('Y-m-d H:i:s')]);
            Menu::setPage($db, $id, $language, !$page['home']);
            if ($becomesHome && $language === '') {
                $app->settings()->set('home_page', (string) $id);
            }
            $changed[] = ['id' => $db->publicId('pages', $id), 'title' => $title, 'home' => $page['home'], 'existing' => false];
        }

        return $changed;
    }

    /** @param list<array<string, mixed>> $elements @return list<string> */
    private static function classesOf(array $elements): array
    {
        $names = [];
        $walk = function (array $p) use (&$walk, &$names): void {
            foreach ($p['classes'] ?? [] as $c) {
                $names[$c] = true;
            }
            foreach ($p['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        foreach ($elements as $element) {
            $walk($element);
        }

        return array_keys($names);
    }

    /**
     * The template's own home page (in the current language): its sections as element trees.
     *
     * @return list<array<string, mixed>>
     */
    private static function home(string $key): array
    {
        $n = Build::fresh(...);
        $s = fn (array $p, array $style): array => ['style' => array_replace_recursive($p['style'] ?? [], $style)] + $p;
        $z = fn (array $p, string $tag): array => ['tag' => $tag] + $p;
        $c = fn (array $p, string ...$classes): array => ['classes' => $classes] + $p;
        $url = fn (string $page): string => '/' . slugify(t($page));
        // a place on the 12-column Compose grid: columns a–b (lines 1–13), rows c–d (lines 1–40), a layer
        $at = fn (array $p, int $a, int $b, int $r1, int $r2, string $layer = 'base'): array => $s($p, ['base' => ['grid_column_start' => (string) $a, 'grid_column_end' => (string) $b, 'grid_row_start' => (string) $r1, 'grid_row_end' => (string) $r2, 'layer' => $layer]]);
        // a photo frame: a tinted shape of the final format until a photo is chosen (the image inside fills it)
        $frame = fn (string $ratio = '', string $tint = 'surface'): array => $s($n('container', [], [
            $s($n('image', ['alt' => '']), ['base' => ['width' => '100%', 'height' => '100%', 'object_fit' => 'cover', 'radius' => '0']]),
        ]), ['base' => ['background' => $tint, 'overflow' => 'hidden'] + ($ratio !== '' ? ['aspect_ratio' => $ratio] : ['height' => '100%'])]);
        $compose = fn (array $children, array $style = []): array => $s($n('section', ['layout' => 'compose'], $children), ['base' => ['padding_y' => 'xl']] + $style);
        $heading = fn (string $text, string $tag, array $style = []): array => $s($z($n('heading', ['text' => $text]), $tag), $style === [] ? [] : ['base' => $style]);
        $text = fn (string $html): array => $n('text', ['html' => '<p>' . $html . '</p>']);
        $button = fn (string $label, string $link, string $variant = 'primary'): array => $n('button', ['text' => $label, 'link' => $link, 'variant' => $variant]);
        $grid = fn (array $children, string $columns, array $extra = []): array => $s($n('grid', [], $children), ['base' => ['display' => 'grid', 'columns' => $columns, 'gap' => 'l'] + $extra, 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]);
        $library = fn (string $section): ?array => Library::section($section, Language::code())['element'] ?? null;

        return array_values(array_filter(match ($key) {
            // a large title laid over a tall photo, the introduction under it on the left – then work in mixed formats and three packages
            'portrait' => [
                $compose([
                    $at($heading(t('Moments that stay'), 'h1', ['font_size' => 'clamp(3rem, 7.5vw, 6.5rem)', 'line_height' => '1.1', 'letter_spacing' => '-0.02em', 'font_weight' => '400']), 1, 13, 1, 5, 'above'),
                    $at($frame(), 7, 13, 2, 22),
                    $at($text(t('[Who you are and what you photograph – in two sentences, in your own words.]')), 1, 6, 15, 19),
                    $at($s($n('container', [], [$button(t('View the work'), '#work')]), ['base' => ['display' => 'flex', 'align_items' => 'start']]), 1, 6, 19, 22),
                ]),
                ['anchor' => 'work'] + $s($n('section', [], [
                    $heading(t('Selected work'), 'h2'),
                    $grid(array_map(fn (string $ratio): array => $frame($ratio), ['3/4', '4/3', '3/4', '4/3', '3/4', '4/3']), '3', ['align_items' => 'start']),
                ]), ['base' => ['padding_y' => '2xl']]),
                $s($n('section', [], [
                    $heading(t('Packages'), 'h2', ['font_size' => '4', 'font_weight' => '400', 'margin_bottom' => 'xl']),
                    $grid(array_map(fn (int $i): array => $s($n('container', [], [
                        $heading(t('[Package name]'), 'h3', ['font_weight' => '400']),
                        $text(t('[What is included, how long it takes and what the client receives.]')),
                        $text(t('[price]')),
                        $button(t('Check availability'), $url('Contact'), 'link'),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's']]), [1, 2, 3]), '3'),
                ]), ['base' => ['padding_y' => '2xl']]),
                $library('quote'),
                $library('cta-bar'),
            ],
            // a bold band in the primary colour with the call and a tall photo on the right, then numbers, services with photos and the steps
            'workshop' => [
                $compose([
                    $at($s($text(t('[Your trade] · [Your town]')), ['base' => ['text_transform' => 'uppercase', 'letter_spacing' => '0.12em', 'font_weight' => '700']]), 1, 8, 1, 3),
                    $at($heading(t('Craftsmanship you can rely on'), 'h1', ['font_size' => 'clamp(2.75rem, 6vw, 5.5rem)', 'line_height' => '1.1', 'text_transform' => 'uppercase', 'font_weight' => '800']), 1, 9, 3, 16, 'above'),
                    $at($text(t('Describe the main benefit for your customer. Briefly, specifically and in their words.')), 1, 7, 17, 20),
                    $at($s($n('container', [], [$button(t('Request a quote'), $url('Contact'), 'secondary'), $button(t('Our services'), $url('Services'), 'outline')]),
                        ['base' => ['display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => 's', 'align_items' => 'start']]), 1, 8, 21, 24),
                    $at($frame('', 'primary-soft'), 8, 13, 1, 25, 'below'),
                ], ['base' => ['padding_y' => '2xl', 'background' => 'primary', 'color' => 'on-primary']]),
                $library('guarantees'),
                $library('alternating'),
                $library('process'),
                $library('reviews'),
                $library('cta-bar'),
            ],
            // an oversized statement with a label and a rule, a photo beside a short story, then signposts, logos and the team
            'editorial' => [
                $s($n('section', [], [
                    $s($text(t('[Your field] · [Since year]')), ['base' => ['text_transform' => 'uppercase', 'letter_spacing' => '0.12em', 'font_size' => '-1']]),
                    $heading(t('We help businesses grow – quickly and hassle-free'), 'h1', ['font_size' => 'clamp(3rem, 8vw, 7.5rem)', 'line_height' => '1.1', 'letter_spacing' => '-0.02em', 'line_length' => '20ch']),
                    $s($grid([
                        $text(t('In one or two sentences, say what you do, who it is for and why you.')),
                        $s($n('container', [], [$button(t('Request a quote'), $url('Contact'))]), ['base' => ['display' => 'flex', 'direction' => 'row', 'justify_content' => 'end']]),
                    ], '2', ['align_items' => 'end']), ['base' => ['padding_y' => 'l', 'border_top' => '1px solid var(--tl-color-line)', 'margin_top' => 'xl']]),
                ]), ['base' => ['padding_y' => '3xl'], 'mobile' => ['padding_y' => '2xl']]),
                $compose([
                    $at($frame(), 1, 7, 1, 16),
                    $at($heading(t('Our story'), 'h2', ['font_size' => '4']), 8, 13, 2, 5),
                    $at($text(t('[How it started, what you do differently and who you do it for – three or four sentences.]')), 8, 12, 6, 11),
                    $at($s($n('container', [], [$button(t('About us'), $url('About us'), 'link')]), ['base' => ['display' => 'flex', 'align_items' => 'start']]), 8, 12, 12, 14),
                ]),
                $library('signpost'),
                $library('logos'),
                $library('team'),
                $library('cta-bar'),
            ],
            default => [],
        }));
    }
}
