<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Core\Extensions;
use Talea\Core\View;

/**
 * The page frame of the public site (Front\Kernel answers through it): page() wraps what an address shows in the site layout – header,
 * navigation, footer, site parts, popups, the language switcher – adds SEO and saves the page to the cache. The address tells the frame
 * what it shows through the public properties below (the counterpart for the language switcher, the "Edit here" link, gated content).
 */
final class PageFrame
{
    public readonly View $view;

    /** Category or page the request shows - the language switcher uses it to find the counterpart in another version. */
    public ?array $counterpart = null;

    /** Shown collection item [idk, collection slug, item slug]: counterparts in other languages have the same slug. */
    public ?array $collectionItem = null;

    /** The shown page is the home page: in every language version its URL is the root (/, /en/), not its slug (that redirects). */
    public bool $isHome = false;

    /** Shared builder state for the whole page (page build, header, footer, wrapper) – one CSS without repetition. */
    private ?\Talea\Builder\Context $context = null;

    /** The requested listing page is past its end - the response is 404. */
    public bool $pastEnd = false;

    /** The "Edit here" link for the shown page or news item; page() prints it for a signed-in user allowed to. */
    public string $editHereUrl = '';

    /** Collection of the shown item page (popup rules „jen v kolekci“, only in collection). */
    public ?string $pageCollection = null;

    /** Popup whose draft the signed preview /_popup/<id> shows (it opens immediately). */
    public int $previewPopup = 0;

    /** Restricted content or a member page is being answered (Core\Members): private, no-store, noindex, never cached. */
    public bool $gated = false;

    public function __construct(private readonly App $app, private readonly Kernel $kernel, private readonly bool $sitePreview)
    {
        // themeless: the front templates are the system's own, the look comes from the design system and the builder
        $this->view = new View([TALEA_SYSTEM . '/views/front']);
    }

    public function page(string $title, string $content, array $meta = [], int $status = 200): Response
    {
        if ($this->pastEnd) {
            $this->pastEnd = false;

            return $this->kernel->notFound();
        }
        $siteSettings = $this->app->settings();
        if ($this->gated) {
            $meta['noindex'] = true; // restricted content and the member pages: never indexed, never cached
        }
        // business facts (2.10) in the content outside the builder (news, text pages), the title and the description
        $content = \Talea\Core\Privacy::fillCookieTable($content, $this->app); // {{cookie_table}} on the cookie policy page (2.14)
        $content = \Talea\Core\Facts::fill($content, $this->app);
        // add-on tokens (3.0) are not filled here: the content already holds what a visitor sent (the search query) – they are
        // filled in what editors wrote, in Renderer::html() and authored() (3.3.2, N38)
        $title = \Talea\Core\Facts::fillText($title, $this->app);
        if (is_string($meta['description'] ?? null)) {
            $meta['description'] = \Talea\Core\Facts::fillText($meta['description'], $this->app);
        }
        $seo = new Seo($this->app);
        $newsItem = $meta['article'] ?? null;
        unset($meta['article']);
        if ($status === 200 && empty($meta['noindex'])) {
            Stats::record($this->app, $newsItem === null ? null : (int) $newsItem['news_id']);
            // real-user speed (2.8) is measured on the same page views the statistics count – never in previews or the
            // builder (noindex), and not for signed-in users, whose pages carry the editing bar
            $meta['vitals'] = Stats::isOn($this->app) && $this->app->request->get('preview') === '' && $this->app->auth()->user() === null;
        }

        if (($meta['image'] ?? '') !== '' && !preg_match('#^https?://#', $meta['image'])) {
            // social networks accept only a full image URL
            $meta['image'] = $this->app->request->origin() . $this->app->url(ltrim((string) preg_replace('#^' . preg_quote($this->app->request->basePath(), '#') . '/#', '', $meta['image']), '/'));
        }
        $meta['breadcrumbs'] ??= $this->context()->breadcrumbs;
        $languages = $this->languages($newsItem);
        $languageSwitcher = $languages === [] ? '' : $this->view->render('language-switcher', ['languages' => $languages]);
        // light / dark color scheme switcher for visitors – next to the languages (template, Navigation element)
        $colorScheme = in_array($this->app->settings()->get('dark_mode'), ['auto', 'dark'], true) && $this->app->settings()->bool('theme_switcher')
            ? $this->view->render('color-scheme', ['selected' => $this->app->settings()->get('dark_mode') === 'dark' ? 'dark' : 'auto']) : '';
        $languagesHtml = $languageSwitcher . $colorScheme;
        // builder elements: Navigation adds the language switcher (optionally) and the color scheme switcher, Language
        // switcher builds from this list
        $this->context()->languageList = $languages;
        $this->context()->colorScheme = $colorScheme;
        // canonical URL: the path without parameters, with the page number for pagination (page 2 is not a copy of page 1)
        $listPageNumber = $this->app->request->getInt('page', 1);
        $canonicalUrl = $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')) . ($listPageNumber > 1 ? '?page=' . $listPageNumber : '');
        // %excerpt%: the start of the description the row ends up with; a description template fills a row without its own one
        $meta['title_vars']['excerpt'] = mb_substr((string) ($meta['description'] ?? ''), 0, 160);
        $descriptionTemplate = in_array($meta['seo_kind'] ?? '', ['page', 'news', 'item'], true) ? $siteSettings->get('seo_description_' . $meta['seo_kind']) : '';
        if ($descriptionTemplate !== '' && empty($meta['own_description'])) {
            $rendered = \Talea\Core\SeoTitle::render($descriptionTemplate, ($meta['title_vars'] ?? []) + ['title' => $title, 'sep' => $siteSettings->get('seo_separator'), 'sitename' => $siteSettings->get('site_name'), 'tagline' => $siteSettings->get('site_description'), 'year' => date('Y')]);
            $meta['description'] = $rendered !== $title || $title === '' ? $rendered : $meta['description'];
        }
        $meta['title_vars']['page'] = $listPageNumber > 1 ? (string) $listPageNumber : '';
        if ($this->sitePreview) {
            $meta['noindex'] = true; // the preview of drafts is never indexed nor cached
        }
        [$content, $parts, $meta] = $this->siteParts($content, $meta, $languageSwitcher, (string) parse_url($canonicalUrl, PHP_URL_PATH));
        $popups = (string) ($meta['popups'] ?? '');
        $commentWidget = (string) ($meta['comments'] ?? ''); // the comment widget of a shared preview (2.15)
        $partMarks = (array) ($meta['part_marks'] ?? []);
        unset($meta['popups'], $meta['comments'], $meta['part_marks']);
        $html = $this->view->render('base', [
            'web' => $siteSettings,
            'title' => $title,
            'meta' => $meta + ['main' => false, 'description' => '', 'keywords' => $siteSettings->get('keywords'), 'image' => '', 'type' => 'website', 'noindex' => false],
            'content' => $content,
            // add-ons (3.0) may add to <head> and the end of <body>
            'head' => $seo->head($title, $meta + ['languages' => $languages], $newsItem) . \Talea\Extension\Registry::applyFilter('head', ''),
            // the accessibility toolbar for visitors (2.14) is off by default and tracks nothing
            'foot' => $seo->foot() . $popups . ($siteSettings->bool('accessibility_toolbar') ? $this->view->render('accessibility') : '')
                . ($this->editHereUrl !== '' ? '<a class="tl-edit-here" href="' . e($this->editHereUrl) . '">' . e(t('Edit here')) . '</a>' : '')
                . $commentWidget . \Talea\Extension\Registry::applyFilter('footer', ''),
            'pages' => $this->menuPages(),
            'menu' => $this->menu('main'),
            'menu_footer' => $this->menu('footer'),
            'menu_html' => \Talea\Core\Menu::html(...),
            'language' => Language::tag(Language::code()),
            'languages_html' => $languagesHtml,
            'with_news' => Extensions::isEnabled($siteSettings, 'news'), // News extension enabled (RSS links in the template)
            'parts' => $parts,
            'part_marks' => $partMarks,
            'url' => $this->app->url(...),
            'canonical' => $canonicalUrl,
            'notice' => \Talea\Core\Hours::noticeBar($this->app), // exceptions to the opening hours, a few days ahead (2.10)
        ]);
        $html = ImageHtml::complete($this->app->db(), $html); // image dimensions and background color – less page jumping
        if ($this->sitePreview) {
            $html = (string) preg_replace('/<body[^>]*>/', '$0' . $this->previewBar(), $html, 1);
        }
        $html = $this->localizeSystemLinks($html);
        // image/web.js only on pages that need it (gallery and photos in text, video, sharing, tabs, carousel, before and after, modal, form,
        // counter, countdown, submenu – Esc closes it, popups, language versions – browser language on the first visit,
        // collection lists with filters or pages – swapped without a reload, the store locator – search, nearest, map, the
        // enquiry basket and comparing products). Contact clicks (2.12, Core\Conversions): a phone number, an e-mail address
        // or a WhatsApp link anywhere on the page – a footer with the phone number is enough – keeps the script too, but only
        // when a click would be counted (the statistics are on, a visitor, no preview: the same switch as the speed beacon)
        $countsClicks = !empty($meta['vitals']) && preg_match(\Talea\Core\Conversions::LINK_PATTERN, $html);
        if (!$countsClicks && !preg_match('/data-(insert|share|copy|tabs|carousel|before-after|form|booking|sent|counter|countdown|theme-option|collection|locator|product|basket|recaptcha)|popover role="dialog"|gallery|class="(?:text|lead)[" ][\s\S]*?<img|cookies-|<li class="submenu|data-popup=|id="tl-experiments"|rel="alternate" hreflang=/', $html)) {
            $html = (string) preg_replace('#<script src="[^"]*/image/web\.js[^"]*"[^>]*></script>\n?#', '', $html);
        }
        $html = \Talea\Extension\Registry::applyFilter('page.html', $html); // add-ons (3.0), before the page is cached
        // elements with a display condition (date, sign-in) are assembled anew every time – the cache would show them as they
        // were at the moment of saving
        if ($status === 200 && empty($meta['noindex']) && $this->app->request->get('preview') === '' && !($this->context?->withoutCache ?? false)) {
            Cache::save($this->app, $html, $newsItem === null ? null : (int) $newsItem['news_id']);
        }

        return $this->gated
            ? new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'])
            : Response::html($html, $status);
    }

    /**
     * Site parts from the builder: the wrapper around the content (news item, listing, 404), header and footer. A part
     * without a published build returns null and the layout renders its own. In the editor the administrator sees the
     * part's draft (?part=<type>&build=draft&editor=1).
     *
     * @param array<string, mixed> $meta
     * @return array{0: string, 1: array{header: ?string, footer: ?string}, 2: array<string, mixed>}
     */
    private function siteParts(string $content, array $meta, string $languageSwitcher, string $path): array
    {
        $r = $this->app->request;
        $db = $this->app->db();
        $k = $this->context();
        $k->menu = ['main' => $this->menu('main'), 'footer' => $this->menu('footer')];
        $k->path = $path;
        $k->languages = $languageSwitcher;
        $preview = isset(\Talea\Builder\SiteParts::TYPES[$r->get('part')]) && $r->get('build') === 'draft'
            && ($this->app->auth()->isAdmin() || $this->kernel->canSeeDraft('part:' . $r->get('part') . ':' . Language::siteColumn() . ($r->get('variant') !== '' ? ':' . $r->get('variant') : ''))) ? $r->get('part') : '';
        $editor = $r->get('editor') === '1' && ($preview !== '' || ($r->get('build') === 'draft' && $r->get('part') === '' && $r->get('component') === ''));
        $language = Language::siteColumn();
        // a site page can have its own header and footer variant; in the variant editor the ?variant= parameter decides
        $ids = ($this->counterpart[0] ?? '') === 'pages' ? (int) $this->counterpart[2]['page_id'] : null;
        $previewVariant = preg_match(\Talea\Builder\SiteParts::VARIANT_PATTERN, $r->get('variant')) ? $r->get('variant') : '';
        $allDrafts = $this->sitePreview;
        $render = function (string $type) use ($db, $k, $preview, $editor, $language, $ids, $previewVariant, $allDrafts): ?string {
            try {
                $variant = $preview === $type ? $previewVariant : \Talea\Builder\SiteParts::pageVariant($db, $type, $language, $ids);
                $tried = \Talea\Core\Look::triedPart($type);
                $build = $tried !== null ? \Talea\Builder\PartTemplates::build($type, $tried, Language::ofContent($this->app->settings(), $language), \Talea\Core\Extensions::enabled($this->app->settings()))
                    : \Talea\Builder\SiteParts::build($db, $type, $language, $preview === $type || $allDrafts, $variant);
            } catch (\Throwable $e) {
                error_log('Site parts: ' . $e->getMessage()); // a site without the table (before migration) renders the parts from the layout

                return null;
            }
            if ($build === null) {
                return null;
            }
            $k->editor = $editor && $preview === $type;
            $k->source = 'part:' . $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
            $html = \Talea\Builder\Renderer::html($build, $k);
            $k->editor = false;

            return $html;
        };

        $wrapper = $meta['part'] ?? null;
        unset($meta['part']);
        if ($wrapper !== null) {
            $k->content = $content;
            if (($html = $render($wrapper)) !== null) {
                $content = $html;
                $meta['build'] = true;
            }
            $k->content = '';
        }
        $parts = ['header' => $render('header'), 'footer' => $render('footer')];
        // on a page's canvas the site header and footer (a part or the built-in one) are marked by two comments with the address
        // of their builder: the canvas overlay (image/builder/overlay.js) offers "Edit site header" on hover; the layout is not touched
        if ($editor && $preview === '' && $this->app->auth()->hasModule('parts')) {
            foreach (['header', 'footer'] as $type) {
                $variant = \Talea\Builder\SiteParts::pageVariant($db, $type, $language, $ids);
                $edit = $this->app->url('admin.php?module=parts&action=builder&type=' . $type . '&language=' . rawurlencode($language) . ($variant !== '' ? '&variant=' . rawurlencode($variant) : ''));
                $meta['part_marks'][$type] = ['<!--tl-part ' . $type . ' ' . $edit . '-->', '<!--/tl-part-->'];
            }
        }
        $meta['popups'] = $this->popups($k, in_array($wrapper, ['news_item', 'list'], true));
        if ($r->get('popup') !== '' || $this->previewPopup > 0) {
            $meta['noindex'] = true; // preview of a popup draft
        }

        if ($k->types !== []) {
            $meta['css'] = \Talea\Builder\Renderer::css($db, $k)
                // the builder canvas reloads after every change – a page transition would only flicker and report an abort in
                // the browser
                . ($editor ? '@view-transition{navigation:none}' : '');
            if ($k->faq !== [] && !isset($meta['faq'])) {
                $meta['faq'] = $k->faq;
            }
            if ($k->structured !== []) {
                $meta['structured'] = $k->structured;
            }
        }
        if ($preview !== '') {
            $meta['noindex'] = true;
        }

        return [$content, $parts, $meta];
    }

    /**
     * Popups for the shown page (Builder\Popups): enabled and published, by the server rules. A draft preview
     * (?popup=<id>&build=draft or /_popup/<id>) adds the given popup even when disabled and opens it immediately.
     */
    private function popups(\Talea\Builder\Context $k, bool $news): string
    {
        $r = $this->app->request;
        if ($r->get('preview') === 'vzhled' || ($r->get('editor') === '1' && !$this->previewPopup)) {
            return ''; // the preview in Appearance and the builder canvas (outside the popup builder) show the page without popups
        }
        $db = $this->app->db();
        $queryPopup = $r->get('build') === 'draft' ? $db->internalId('popups', $r->get('popup')) : 0;
        $preview = $this->previewPopup ?: ($queryPopup > 0 && $this->kernel->canSeeDraft('popup:' . $queryPopup) ? $queryPopup : 0);
        try {
            $popups = \Talea\Builder\Popups::forPage($db, ['page_id' => ($this->counterpart[0] ?? '') === 'pages' ? (int) $this->counterpart[2]['page_id'] : null,
                'collection' => $this->pageCollection, 'news' => $news, 'language' => Language::code(), 'today' => date('Y-m-d')]);
            $draft = $preview > 0 ? \Talea\Builder\Popups::byId($db, $preview) : null;
        } catch (\Throwable $e) {
            error_log('Pop-ups: ' . $e->getMessage()); // site before migration

            return '';
        }
        if ($draft !== null) {
            $popups = [...array_filter($popups, fn (array $p): bool => $p['popup_id'] !== $preview), ['build' => $draft['build_draft'] ?? $draft['build'], 'preview' => true] + $draft];
            $k->withoutCache = true;
        }
        $html = '';
        foreach ($popups as $p) {
            $build = \Talea\Builder\Build::fromJson($p['build']);
            if ($build === null) {
                continue;
            }
            if ($p['rules']['from'] !== '' || $p['rules']['to'] !== '') {
                $k->withoutCache = true; // a popup with a date range must not stay in the page cache after it ends
            }
            $k->source = 'popup:' . ($p['public_id'] ?? $db->publicId('popups', (int) $p['popup_id']));
            // signed-in users (administrators, editors) view the popups, but are not counted in the counters
            $html .= \Talea\Builder\Popups::wrapper($p, \Talea\Builder\Renderer::html($build, $k), $this->app->auth()->user() === null ? $this->app->url('popup') : '', !empty($p['preview']));
        }

        return $html;
    }

    /**
     * Language switcher for the template: code => [name, url, is active]. For a news item it leads to its translation,
     * otherwise to the counterpart of the page or category, and when there is none, to the version's home page.
     * An empty array = the site has a single language.
     *
     * @param array<string, mixed>|null $newsItem
     * @return array<string, array{name:string, url:string, active:bool, translated:bool}>
     */
    private function languages(?array $newsItem): array
    {
        $siteSettings = $this->app->settings();
        // an unfinished language (without a published translation of the home page) is not offered by the switcher; it stays
        // on its own pages
        $publishedLanguages = Language::published($siteSettings, $this->app->db());
        $additional = array_values(array_filter(Language::additional($siteSettings), fn (string $j): bool => in_array($j, $publishedLanguages, true) || $j === Language::siteColumn()));
        if ($additional === []) {
            return [];
        }
        $translations = [];
        if ($newsItem !== null) {
            $original = (int) ($newsItem['translation_of'] ?: $newsItem['news_id']);
            $translations = array_map(fn (string $seo): string => 'news/' . $seo, $this->app->db()->pairs('SELECT language, slug FROM {news} WHERE (news_id = ? OR translation_of = ?) AND visible = TRUE AND published_at <= NOW()', [$original, $original]));
        } elseif ($this->collectionItem !== null) {
            [$idk, $collection, $seo] = $this->collectionItem;
            $translations = array_map(fn (string $s): string => $collection . '/' . $s, $this->app->db()->pairs('SELECT language, slug FROM {collection_items} WHERE collection_id = ? AND slug = ? AND visible = TRUE', [$idk, $seo]));
        } elseif ($this->counterpart !== null && !$this->isHome) {
            // category or page: the original + its translations
            [$table, $key, $row, $path] = $this->counterpart;
            $original = (int) ($row['translation_of'] ?: $row[$key]);
            $condition = $table === 'pages' ? ' AND visible = TRUE' : '';
            $translations = array_map(fn (string $seo): string => $path . $seo, $this->app->db()->pairs("SELECT language, slug FROM {{$table}} WHERE ({$key} = ? OR translation_of = ?){$condition}", [$original, $original]));
        }
        $root = $this->app->request->basePath() . '/';
        $result = [];
        foreach ([Language::defaults($siteSettings), ...$additional] as $code) {
            $column = Language::column($siteSettings, $code);
            $prefix = $column === '' ? '' : $column . '/';
            $result[$code] = [
                'name' => Language::AVAILABLE[$code][0],
                'url' => $root . $prefix . ($translations[$column] ?? ''),
                'active' => $code === Language::code(),
                'translated' => isset($translations[$column]),
            ];
        }

        return $result;
    }

    /** @var list<array{title:string, slug:string, intro:bool}>|null */
    private ?array $menuPages = null;

    /** Pages for the template's main navigation (the home page leads to the site root). */
    public function menuPages(): array
    {
        if ($this->menuPages === null) {
            $home = $this->homePageId();
            $this->menuPages = array_map(
                fn (array $s): array => ['title' => $s['title'], 'slug' => (int) $s['page_id'] === $home ? '' : $s['slug'], 'intro' => (int) $s['page_id'] === $home],
                $this->app->db()->all('SELECT page_id, title, slug FROM {pages} WHERE visible = TRUE AND in_menu = TRUE AND language = ? ORDER BY sort_order, title', [Language::siteColumn()]),
            );
        }

        return $this->menuPages;
    }

    /** Breadcrumb navigation of the shown page (Breadcrumbs element and BreadcrumbList): Home and the given levels. */
    public function breadcrumbs(array ...$levels): void
    {
        $this->context()->breadcrumbs = [[t('Home'), $this->app->url('')], ...$levels];
    }

    /** @var array<string, list<array<string, mixed>>> location => menu items (Core\Menu) */
    private array $menu = [];

    /** @return list<array<string, mixed>> */
    private function menu(string $location): array
    {
        return $this->menu[$location] ??= \Talea\Core\Menu::items($this->app, $location, Language::siteColumn(), $this->homePageId());
    }

    /**
     * Assembles the page: wraps the content in the site layout (header with navigation, footer), adds SEO and saves it to
     * the cache.
     *
     * @param array<string, mixed> $meta
     */
    public function context(): \Talea\Builder\Context
    {
        if ($this->context === null) {
            $this->context = new \Talea\Builder\Context($this->app);
            // path of the shown page already for the content (filter and pagination links of a collection list, active
            // navigation item)
            $this->context->path = (string) parse_url($this->app->url(ltrim($this->app->request->path(), '/')), PHP_URL_PATH);
        }

        return $this->context;
    }

    /**
     * Editing a page or news item directly on the site. Without the permission it does nothing; with it, it prepares the
     * "Edit here" link, and with ?edit=text it returns a form with the editor instead of the content.
     * The administration saves it (action save_text).
     *
     * @param array<string, mixed> $record row of tl_pages or tl_news
     */
    public function editInPlace(string $type, array $record, string $path): ?string
    {
        $auth = $this->app->auth();
        if ($auth->user() === null || !($type === 'news' ? $auth->canEditArticle($record) : $auth->hasModule('pages'))) {
            return null;
        }
        $url = $this->app->url($path);
        if ($this->app->request->get('edit') !== 'text') {
            // the draft is visible only in the preview – without it "Edit here" would end on the Not found page
            $this->editHereUrl = $url . ($this->app->request->get('preview') === '1' ? '?preview=1&edit=text' : '?edit=text');

            return null;
        }

        // the form and the editor's toolbar speak the language and form of address of the user's administration, the page around it the site's
        return Language::runWith(Language::adminCode($auth->user(), $this->app->settings()), fn (): string => $this->view->render('edit', [
            'app' => $this->app, 'type' => $type, 'record' => $record,
            'back' => $url . ($this->app->request->get('preview') === '1' ? '?preview=1' : ''),
            'action' => $this->app->url('admin.php?module=' . ($type === 'news' ? 'news' : 'pages') . '&action=save_text'),
            'error' => $this->app->request->get('error') === '1',
        ]), 'admin-', Language::normalizeRegister((string) ($auth->user()['register'] ?? '')));
    }

    /**
     * What the SEO fields of a row add to $meta: the kind that picks the title template, the page's robots directives, the
     * canonical override and the sharing texts. A row's own SEO title is shown as written.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $vars extra title template variables (category, excerpt)
     * @return array<string, mixed>
     */
    public function seoMeta(string $kind, array $row = [], array $vars = []): array
    {
        return [
            'seo_kind' => $kind, 'own_title' => (string) ($row['seo_title'] ?? '') !== '', 'title_vars' => $vars,
            'robots' => (string) ($row['robots'] ?? ''), 'canonical' => \Talea\Core\Robots::canonical((string) ($row['canonical_url'] ?? '')),
            'share_title' => (string) ($row['share_title'] ?? ''), 'share_description' => (string) ($row['share_description'] ?? ''),
            'own_description' => (string) ($row['description'] ?? $row['seo_description'] ?? '') !== '',
        ];
    }

    /** Home page ID in the shown site version's language (counterpart of the page from Settings); 0 = the news listing is home. */
    public function homePageId(): int
    {
        $id = $this->app->settings()->int('home_page');
        if ($id === 0 || Language::siteColumn() === '') {
            return $id;
        }

        return (int) $this->app->db()->value('SELECT page_id FROM {pages} WHERE translation_of = ? AND language = ?', [$id, Language::siteColumn()]);
    }

    /**
     * Add-on tokens {{ext.<slug>.<name>}} (3.0) in HTML an editor wrote – page and news text, a category description. Never over
     * a whole page: a template adds what the visitor sent (the search query), and that must not run an add-on (3.3.2, N38).
     * Builds fill them in Renderer::html().
     */
    public static function authored(string $html): string
    {
        return \Talea\Extension\Registry::fillTokens($html);
    }

    /** The bar of the whole-site preview: visitors see the published site; a link ends the preview. */
    private function previewBar(): string
    {
        return '<div class="tl-preview-bar" role="status" style="position:sticky;top:0;z-index:2147483000;display:flex;gap:1rem;flex-wrap:wrap;justify-content:center;align-items:center;'
            . 'padding:0.5rem 1rem;background:#16181d;color:#fff;font:600 0.875rem/1.4 system-ui,sans-serif">'
            . '<span>' . e(t('Preview of drafts – visitors still see the published site.')) . '</span>'
            . '<a href="?preview_end=1" style="color:#fff;text-decoration:underline">' . e(t('End the preview')) . '</a></div>';
    }

    /**
     * Links to system URLs stored in the content (href="/news" from an older build or a starter site) in the form of the
     * version's language, so they do not go through a redirect (Core\Routes).
     */
    private function localizeSystemLinks(string $html): string
    {
        if (\Talea\Core\Routes::newsSlug($this->app->db()) === '' || !preg_match('#href="[^"]*/news#', $html)) {
            return $html;
        }
        $base = preg_quote($this->app->request->basePath() . ($this->app->languagePrefix !== '' ? '/' . $this->app->languagePrefix : ''), '#');

        return (string) preg_replace_callback('#href="' . $base . '/(news(?:[/?\#][^"]*)?)"#',
            fn (array $m): string => 'href="' . e($this->app->url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5))) . '"', $html);
    }
}
