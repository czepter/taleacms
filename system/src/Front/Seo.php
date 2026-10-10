<?php

declare(strict_types=1);

namespace Talea\Front;

use Talea\Core\App;

/**
 * SEO, GEO, analytics and consent: robots.txt, sitemap.xml, llms.txt, Markdown version of a news item,
 * tags for <head> (verification, structured data, tracking codes) and the cookie bar before </body>.
 * Everything is driven by Settings; layouts only print the variables $hlava and $pata.
 */
final class Seo
{
    /** Bots of AI services that the switch in Settings applies to. */
    private const array AI_BOTS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent', 'cohere-ai'];

    private readonly string $siteSettings;

    /** Site root without the language version prefix. */
    private readonly string $root;

    public function __construct(private readonly App $app)
    {
        $this->siteSettings = $app->request->origin() . $app->url('');
        $this->root = $app->request->origin() . $app->request->basePath() . '/';
    }

    /** System path with the custom news address applied – Core\Routes. */
    private function path(string $path): string
    {
        return \Talea\Core\Routes::publicPath($path, $this->app->db());
    }

    /**
     * security.txt (RFC 9116, 2.1): whom to tell about a security problem of this site. Expires half a year ahead – the
     * file is generated, so it never goes stale.
     *
     * @param list<string> $languages
     */
    public static function securityTxt(string $contact, string $siteUrl, array $languages, int $now): string
    {
        return 'Contact: ' . (str_contains($contact, '@') && !str_starts_with($contact, 'https://') ? 'mailto:' . $contact : $contact) . "\n"
            . 'Expires: ' . gmdate('Y-m-d\T00:00:00\Z', $now + 183 * 86400) . "\n"
            . ($languages !== [] ? 'Preferred-Languages: ' . implode(', ', $languages) . "\n" : '')
            . 'Canonical: ' . rtrim($siteUrl, '/') . "/.well-known/security.txt\n";
    }

    public function robotsTxt(): string
    {
        $s = $this->app->settings();
        if (\Talea\Core\Demo::active()) {
            return "# The public demo of Talea is not indexed.\nUser-agent: *\nDisallow: /\n";
        }
        if (!$s->bool('indexing')) {
            return "# Indexing of the site is turned off in Settings.\nUser-agent: *\nDisallow: /\n";
        }
        $rows = ['User-agent: *', 'Disallow: /admin.php', 'Disallow: /search', 'Disallow: /*?preview=', ''];
        if ($s->get('ai_crawlers') === 'block') {
            foreach (self::AI_BOTS as $bot) {
                $rows[] = 'User-agent: ' . $bot;
            }
            array_push($rows, 'Disallow: /', '');
        }
        if (trim($s->get('robots_extra')) !== '') {
            array_push($rows, trim($s->get('robots_extra')), '');
        }
        $rows[] = 'Sitemap: ' . $this->siteSettings . 'sitemap.xml';

        return implode("\n", $rows) . "\n";
    }

    /** Absolute URL of a page-like path in the form of the url_slash setting; $after goes behind it (a news item's ".md"). */
    private function page(string $path, string $after = ''): string
    {
        $suffix = \Talea\Core\Routes::pageLike('/' . $path) && $after === '' ? \Talea\Core\Routes::suffix($this->app->settings()->get('url_slash')) : '';

        return $this->siteSettings . $path . $suffix . $after;
    }

    public function sitemapXml(): string
    {
        $db = $this->app->db();
        // there is one sitemap for all language versions: the URL gets a prefix by the language of the record
        $suffix = \Talea\Core\Routes::suffix($this->app->settings()->get('url_slash'));
        $url = fn (string $path, ?string $change = null, string $priority = '0.5', string $language = ''): string => '<url><loc>'
            . e($this->root . ($language !== '' ? $language . '/' : '') . ($public = \Talea\Core\Routes::publicPath($path, $db))
                . (\Talea\Core\Routes::pageLike('/' . $public) ? $suffix : '')) . '</loc>'
            . ($change !== null ? '<lastmod>' . date('c', strtotime($change)) . '</lastmod>' : '') . '<priority>' . $priority . '</priority></url>';

        // only enabled and published language versions; content of a disabled or unfinished language is not in the sitemap
        $languages = ['', ...\Talea\Core\Language::published($this->app->settings(), $db)];
        $inLanguages = ' AND language IN (' . implode(',', array_fill(0, count($languages), '?')) . ')';
        $xml = [$url('', null, '1.0')];
        foreach (array_slice($languages, 1) as $language) {
            $xml[] = $url('', null, '0.9', $language);
        }
        $home = $this->app->settings()->int('home_page');
        foreach ($db->all('SELECT slug, updated_at, language FROM {pages} WHERE visible = TRUE AND noindex = FALSE AND password_hash IS NULL AND deleted_at IS NULL AND page_id <> ? AND (translation_of IS NULL OR translation_of <> ?)' . $inLanguages, [$home, $home, ...$languages]) as $r) {
            $xml[] = $url($r['slug'], $r['updated_at'], '0.8', $r['language']);
        }
        foreach ($db->all('SELECT k.slug AS collection, p.slug, p.language, COALESCE(p.updated_at, p.created_at) AS changed FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.detail = TRUE AND p.visible = TRUE AND p.noindex = FALSE AND p.deleted_at IS NULL AND p.language IN (' . implode(',', array_fill(0, count($languages), '?')) . ') LIMIT 5000', $languages) as $r) {
            $xml[] = $url($r['collection'] . '/' . $r['slug'], $r['changed'], '0.5', $r['language']);
        }
        if (!\Talea\Core\Extensions::isEnabled($this->app->settings(), 'news')) {
            return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
        }
        // news listing, categories and tags only where there is some published news item
        $published = 'visible = TRUE AND published_at <= NOW() AND deleted_at IS NULL';
        foreach ($db->all("SELECT language, MAX(COALESCE(edited_at, published_at)) AS changed FROM {news} WHERE {$published}{$inLanguages} GROUP BY language", $languages) as $r) {
            $xml[] = $url('news', $r['changed'], '0.6', $r['language']);
        }
        foreach ($db->all("SELECT k.slug, k.language FROM {categories} k WHERE EXISTS (SELECT 1 FROM {news} n WHERE n.category_id = k.category_id AND n.{$published}) AND k.language IN (" . implode(',', array_fill(0, count($languages), '?')) . ')', $languages) as $r) {
            $xml[] = $url('news/category/' . $r['slug'], null, '0.4', $r['language']);
        }
        foreach ($db->all("SELECT slug, language, COALESCE(edited_at, published_at) AS changed FROM {news} WHERE {$published} AND noindex = FALSE{$inLanguages} ORDER BY published_at DESC LIMIT 45000", $languages) as $r) {
            $xml[] = $url('news/' . $r['slug'], $r['changed'], '0.5', $r['language']);
        }

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $xml) . "\n</urlset>\n";
    }

    /**
     * JSON Feed 1.1 (https://jsonfeed.org) - a modern counterpart of RSS with full text.
     *
     * @param list<array<string, mixed>> $news
     * @return array<string, mixed>
     */
    public function jsonFeed(array $news): array
    {
        $s = $this->app->settings();

        return [
            'version' => 'https://jsonfeed.org/version/1.1', 'title' => $s->get('site_name'), 'description' => $s->get('site_description'),
            'home_page_url' => $this->siteSettings, 'feed_url' => $this->siteSettings . 'feed.json', 'language' => \Talea\Core\Language::code(),
            'items' => array_map(fn (array $c): array => array_filter([
                'id' => 'news-' . $c['public_id'], 'url' => $this->page($this->path('news/') . $c['slug']), 'title' => $c['title'],
                'summary' => trim(strip_tags($c['intro'])), 'content_html' => $c['intro'] . $c['text'],
                'image' => $c['image'] !== '' ? $this->absoluteUrl($c['image']) : null,
                'date_published' => date('c', strtotime($c['published_at'])), 'date_modified' => $c['edited_at'] ? date('c', strtotime($c['edited_at'])) : null,
                'authors' => $c['author_name'] !== null ? [['name' => $c['author_name']]] : null, 'tags' => [$c['category_name']],
            ]), $news),
        ];
    }

    /**
     * IndexNow: notifies search engines (Bing, Seznam, Yandex) of a new or changed URL.
     * Called after a published news item is saved; a failure is ignored, the site must not wait because of it.
     */
    /** @param string $url URL on the site from the server root (App::newsItemUrl()) */
    public function indexNow(string $url): void
    {
        if (\Talea\Core\Demo::active()) {
            return;
        }
        $s = $this->app->settings();
        $host = (string) parse_url($this->siteSettings, PHP_URL_HOST);
        if (!$s->bool('indexnow') || $s->get('indexnow_key') === '' || !$s->bool('indexing') || in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
            return;
        }
        $data = json_encode(['host' => $host, 'key' => $s->get('indexnow_key'), 'keyLocation' => $this->app->request->origin() . $this->app->request->basePath() . '/' . $s->get('indexnow_key') . '.txt', 'urlList' => [$this->app->request->origin() . $url]]);
        @file_get_contents('https://api.indexnow.org/indexnow', false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json; charset=utf-8\r\n", 'content' => $data, 'timeout' => 3, 'ignore_errors' => true,
        ]]));
    }

    /** llms.txt - a guide to the site for language models (https://llmstxt.org). */
    public function llmsTxt(): string
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $md = $s->bool('markdown_news') ? '.md' : '';
        $rows = ['# ' . $s->get('site_name'), ''];
        if ($s->get('site_description') !== '') {
            array_push($rows, '> ' . str_replace("\n", ' ', $s->get('site_description')), '');
        }
        // business facts (2.10): what the company states about itself, kept in one place
        $facts = array_filter(\Talea\Core\Facts::all($this->app, \Talea\Core\Language::siteColumn()), fn (array $f): bool => !$f['builtIn'] && $f['display'] !== '');
        if ($facts !== []) {
            $rows[] = '## ' . t('Facts');
            foreach ($facts as $f) {
                $rows[] = '- ' . $f['label'] . ': ' . $f['display'];
            }
            $rows[] = '';
        }
        $rows[] = '## ' . t('Pages');
        $home = $s->int('home_page');
        foreach ($db->all('SELECT page_id, title, slug, description FROM {pages} WHERE visible = TRUE AND noindex = FALSE AND password_hash IS NULL AND deleted_at IS NULL AND language = ? ORDER BY sort_order, title', [\Talea\Core\Language::siteColumn()]) as $r) {
            $rows[] = '- [' . $r['title'] . '](' . $this->page((int) $r['page_id'] === $home ? '' : $r['slug']) . ')' . ($r['description'] !== '' ? ': ' . $r['description'] : '');
        }
        // collections with their own item pages (guide, team, products…): item with the first longer text as its description
        foreach ($db->all('SELECT collection_id, name, slug, fields FROM {collections} WHERE detail = TRUE ORDER BY name') as $k) {
            $field = json_decode((string) $k['fields'], true) ?: [];
            $descriptiveFields = array_column(array_filter($field, fn (array $f): bool => in_array($f['type'] ?? '', ['lines', 'html', 'text'], true)), 'key');
            $items = $db->all('SELECT name, slug, data, description FROM {collection_items} WHERE collection_id = ? AND visible = TRUE AND noindex = FALSE AND language = ? ORDER BY sort_order, name LIMIT 200', [$k['collection_id'], \Talea\Core\Language::siteColumn()]);
            if ($items === []) {
                continue;
            }
            array_push($rows, '', '## ' . $k['name']);
            foreach ($items as $p) {
                $data = json_decode((string) $p['data'], true) ?: [];
                $description = (string) $p['description']; // the item's own description (1.9) first
                foreach ($description === '' ? $descriptiveFields : [] as $key) {
                    if (is_string($data[$key] ?? null) && trim(strip_tags($data[$key])) !== '') {
                        $description = mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($data[$key]), ENT_QUOTES | ENT_HTML5))), 0, 200, '…');
                        break;
                    }
                }
                $rows[] = '- [' . $p['name'] . '](' . $this->page($k['slug'] . '/' . $p['slug']) . ')' . ($description !== '' ? ': ' . $description : '');
            }
        }
        if (!\Talea\Core\Extensions::isEnabled($s, 'news')) {
            return \Talea\Core\Facts::fillText(implode("\n", $rows) . "\n", $this->app);
        }
        array_push($rows, '', '## ' . t('News'));
        foreach ($db->all('SELECT title, slug, intro FROM {news} WHERE visible = TRUE AND published_at <= NOW() AND noindex = FALSE AND deleted_at IS NULL AND language = ? ORDER BY published_at DESC LIMIT 30', [\Talea\Core\Language::siteColumn()]) as $c) {
            $rows[] = '- [' . $c['title'] . '](' . $this->page($this->path('news/') . $c['slug'], $md) . '): ' . mb_strimwidth(trim(strip_tags($c['intro'])), 0, 200, '…');
        }

        return \Talea\Core\Facts::fillText(implode("\n", $rows) . "\n", $this->app);
    }

    /** @param array<string, mixed> $newsItem */
    public function newsItemMarkdown(array $newsItem): string
    {
        $head = ['# ' . $newsItem['title'], ''];
        $head[] = '- ' . t('Author') . ': ' . ($newsItem['author_name'] ?? $this->app->settings()->get('site_name'));
        $head[] = '- ' . t('Published') . ': ' . date('Y-m-d', strtotime($newsItem['published_at'])) . ($newsItem['edited_at'] ? ', ' . t('updated') . ': ' . date('Y-m-d', strtotime($newsItem['edited_at'])) : '');
        $head[] = '- ' . t('Categories') . ': ' . $newsItem['category_name'];
        $head[] = '- ' . t('Source') . ': ' . $this->page($this->path('news/') . $newsItem['slug']);

        return implode("\n", $head) . "\n\n" . self::htmlToMarkdown($newsItem['intro']) . "\n\n" . self::htmlToMarkdown($newsItem['text']) . "\n";
    }

    /**
     * Tags before </head>.
     *
     * @param array<string, mixed> $meta     page meta data (type, description, image, noindex...)
     * @param array<string, mixed>|null $newsItem the full news item, if this is a news item page
     */
    public function head(string $title, array $meta, ?array $newsItem): string
    {
        $s = $this->app->settings();
        $h = [];
        if ($s->get('cookies_mode') === 'external' && trim($s->get('cookies_external_code')) !== '') {
            $h[] = $s->get('cookies_external_code');
        }
        if (!$s->bool('indexing')) {
            $h[] = '<meta name="robots" content="noindex, nofollow">';
        } // noindex of an individual page or news item is printed by the template from $meta['noindex'] (and it omits the canonical URL)
        if ($s->get('verification_google') !== '') {
            $h[] = '<meta name="google-site-verification" content="' . e($s->get('verification_google')) . '">';
        }
        if ($s->get('verification_bing') !== '') {
            $h[] = '<meta name="msvalidate.01" content="' . e($s->get('verification_bing')) . '">';
        }
        $generated = null;
        if (($meta['image'] ?? '') === '' && $s->get('share_image') !== '') {
            $h[] = '<meta property="og:image" content="' . e($this->absoluteUrl($s->get('share_image'))) . '">';
        } elseif (($meta['image'] ?? '') === '' && ($generated = ShareImage::url($this->app, $title)) !== null) {
            // nothing to share at all: a picture with the title in the site's colours, drawn by the site (2.12)
            $h[] = '<meta property="og:image" content="' . e($generated) . '">';
            $h[] = '<meta property="og:image:width" content="' . ShareImage::WIDTH . '">';
            $h[] = '<meta property="og:image:height" content="' . ShareImage::HEIGHT . '">';
        }
        // language versions: hreflang only to existing translations (news item, page, category, collection item), on the home
        // page to the home page of each version
        $defaults = \Talea\Core\Language::defaults($s);
        foreach ($meta['languages'] ?? [] as $code => $j) {
            if ($j['translated'] || ($meta['main'] ?? false)) {
                $h[] = '<link rel="alternate" hreflang="' . e($code) . '" href="' . e($this->app->request->origin() . $j['url']) . '">';
                if ($code === $defaults) {
                    // a visitor in a language the site does not have gets the default version
                    $h[] = '<link rel="alternate" hreflang="x-default" href="' . e($this->app->request->origin() . $j['url']) . '">';
                }
            }
        }
        $h[] = '<meta property="og:locale" content="' . \Talea\Core\Language::AVAILABLE[\Talea\Core\Language::code()][1] . '">';
        if (($meta['description'] ?? '') !== '') {
            $h[] = '<meta property="og:description" content="' . e($meta['description']) . '">';
        }
        $h[] = '<meta name="twitter:card" content="' . (($meta['image'] ?? '') !== '' || $s->get('share_image') !== '' || $generated !== null ? 'summary_large_image' : 'summary') . '">';
        if ($newsItem !== null && $s->bool('markdown_news')) {
            $h[] = '<link rel="alternate" type="text/markdown" href="' . e($this->siteSettings . $this->path('news/') . $newsItem['slug'] . '.md') . '">';
        }
        if ($s->bool('schema_org')) {
            $h[] = '<script type="application/ld+json">' . json_encode($this->structuredData($title, $meta, $newsItem), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }
        // the site's own fonts that render text above the fold (the body and the heading face) start downloading right away
        $designSystem = \Talea\Builder\DesignSystem::load($s);
        $h[] = \Talea\Builder\DesignSystem::fontPreloads($designSystem, $this->app->request->basePath());
        // design system (tokens and cascade layer order) and the style of the page build, if it is a page from the builder
        $h[] = '<style>' . \Talea\Builder\DesignSystem::css($designSystem, $this->app->request->basePath()) . ($meta['css'] ?? '') . '</style>';
        $h[] = SiteIdentity::head($s, $this->app->request->basePath());
        // shared site elements (photo gallery, photo viewer, video, sharing…) for all templates
        $version = rawurlencode(TALEA_VERSION);
        $h[] = '<link rel="stylesheet" href="' . e($this->app->url('image/web.css')) . '?v=' . $version . '">';
        // blocking="render": the page is first rendered only with the script loaded (it loads in parallel with the styles, which
        // block rendering anyway). Without it Chrome aborts the transition between pages (View Transitions) while the deferred
        // script is downloading, and prints the unhandled error „Transition was aborted because of invalid state“ to the console.
        // contact clicks (2.12, Core\Conversions) follow the same switch as the speed beacon below: the script counts a click on a
        // phone number, an e-mail address or a WhatsApp link only when the tag names the endpoint in data-conversion
        $clicks = !empty($meta['vitals']) ? ' data-conversion="' . e($this->app->url('conversion')) . '"' : '';
        $h[] = '<script src="' . e($this->app->url('image/web.js')) . '?v=' . $version . '" defer blocking="render"' . self::scriptTextsAttribute() . $clicks . '></script>';
        if (!empty($meta['vitals'])) {
            // real-user speed (2.8, Core\WebVitals): a small deferred script of its own, so pages without image/web.js stay
            // without it; the beacon goes to POST /vitals without cookies or identifiers
            $h[] = '<script src="' . e($this->app->url('image/vitals.js')) . '?v=' . $version . '" defer data-vitals="' . e($this->app->url('vitals')) . '"></script>';
        }
        $h[] = $this->analyticsCode();
        if (trim($s->get('head_code')) !== '') {
            $h[] = $s->get('head_code');
        }
        if (trim((string) ($meta['head_code'] ?? '')) !== '') {
            $h[] = (string) $meta['head_code']; // this page only, after the code for the whole site (2.3)
        }

        return implode("\n", array_filter($h)) . "\n";
    }

    /**
     * Texts that image/web.js shows to the visitor (wrapped in T() or A() there); the site dictionary translates
     * them like any other text.
     */
    public const array SCRIPT_TEXTS = ['Previous photo', 'Next photo', 'Close',
        'Added to the enquiry.', 'Show the enquiry', 'Enquiry', 'Compare', 'Clear', 'Quantity', 'Remove', 'You can compare up to four products.', 'Back', 'Next', 'Step',
        'Previous month', 'Next month', 'No free times on this day.', 'Choose a service first.', 'Loading…', 'Chosen time: %s'];

    /**
     * The data-texts attribute for the <script> tag with image/web.js: translations of the script texts (source => translation)
     * as JSON. No extra request and no inline script; the English version needs nothing – the script has English built in.
     */
    private static function scriptTextsAttribute(): string
    {
        $translations = [];
        foreach (self::SCRIPT_TEXTS as $source) {
            if (t($source) !== $source) {
                $translations[$source] = t($source);
            }
        }

        return $translations === [] ? '' : ' data-texts="' . e((string) json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    }

    /** Cookie bar of the built-in solution and marketing codes; inserted before </body>. */
    public function foot(): string
    {
        $s = $this->app->settings();
        $mode = $s->get('cookies_mode');
        $marketing = trim($s->get('marketing_code'));
        $html = $marketing === '' ? '' : self::deferUntilConsent($marketing, $mode);
        if (\Talea\Core\Demo::active()) {
            // the public demo (2.6): a badge that leads to the admin; inline styles, so no site class can hide it
            $html .= '<a href="' . e($this->app->url('admin.php')) . '" style="position:fixed;left:12px;bottom:12px;z-index:2147483000;padding:8px 12px;border-radius:999px;background:#121212;color:#fff;font:600 13px/1.2 system-ui,sans-serif;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.25)">'
                . e(t('Talea demo – try the admin')) . '</a>';
        }
        // remembering where leads came from needs the visitor's consent to marketing (2.3)
        $hasMarketing = $marketing !== '' || $s->bool('lead_attribution') || $s->get('gtm_id') !== ''; // GTM usually also runs ad tags
        if ($mode !== 'builtin' || (!$this->usesAnalyticsCookies() && !$hasMarketing)) {
            return $html;
        }
        $view = new \Talea\Core\View([TALEA_SYSTEM . '/views/front']);

        return $html . $view->render('cookies', [
            'text' => $s->get('cookies_text'),
            'policy' => \Talea\Core\Privacy::policyUrl($s),
            'analytics' => $this->usesAnalyticsCookies(),
            'marketing' => $hasMarketing,
            'evidence' => $s->bool('cookies_log') ? $this->app->url('consent') : '',
        ]);
    }

    /**
     * Marketing code by cookie mode: without a bar it is printed directly; the built-in bar unpacks it from the <template>
     * tag after consent; with an external service the scripts get markup understood by Cookiebot and services compatible
     * with it (the same as for tracking codes) – only that service runs them.
     */
    public static function deferUntilConsent(string $code, string $mode): string
    {
        return match ($mode) {
            'none' => $code,
            'external' => (string) preg_replace('/<script(?![^>]*\btype\s*=)/i', '<script type="text/plain" data-cookieconsent="marketing"', $code),
            default => '<template data-consent="marketing">' . $code . '</template>',
        };
    }

    private function usesAnalyticsCookies(): bool
    {
        $s = $this->app->settings();

        return $s->get('ga4_id') !== '' || $s->get('gtm_id') !== '' || ($s->get('matomo_url') !== '' && $s->int('matomo_id') > 0);
    }

    private function analyticsCode(): string
    {
        $s = $this->app->settings();
        // with consent: the script is "text/plain" until the bar (built-in or Cookiebot) enables it
        $pending = $s->get('cookies_mode') !== 'none';
        $attributes = $pending ? ' type="text/plain" data-consent="analytics" data-cookieconsent="statistics"' : '';
        $code = '';
        if ($s->get('ga4_id') !== '') {
            $id = $s->get('ga4_id');
            $code .= "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
                . ($pending ? "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});" : '')
                . "gtag('js',new Date());gtag('config','{$id}');</script>\n"
                . "<script async{$attributes} src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n";
        }
        if ($s->get('gtm_id') !== '') {
            $code .= self::tagManager($s->get('gtm_id'), $s->get('cookies_mode'), $s->get('ga4_id') === '');
        }
        if ($s->get('matomo_url') !== '' && $s->int('matomo_id') > 0) {
            $url = json_encode(rtrim($s->get('matomo_url'), '/') . '/', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
            $code .= "<script{$attributes}>var _paq=window._paq=window._paq||[];_paq.push(['trackPageView']);_paq.push(['enableLinkTracking']);(function(){var u={$url};_paq.push(['setTrackerUrl',u+'matomo.php']);_paq.push(['setSiteId','{$s->int('matomo_id')}']);var d=document,g=d.createElement('script'),s=d.getElementsByTagName('script')[0];g.async=true;g.src=u+'matomo.js';s.parentNode.insertBefore(g,s);})();</script>\n";
        }
        if ($s->get('plausible_domain') !== '') {
            $code .= '<script defer data-domain="' . e($s->get('plausible_domain')) . '" src="https://plausible.io/js/script.js"></script>' . "\n";
        }

        return $code;
    }

    /**
     * Google Tag Manager (2.6). Consent mode starts with everything denied; with the built-in cookie bar the container
     * loads after the first consent to analytics or marketing (the bar enables scripts marked data-gtm), with an external
     * consent service right away – that service updates the consent itself – and without a bar right away.
     */
    public static function tagManager(string $id, string $cookiesMode, bool $defineGtag = true): string
    {
        $id = preg_match('/^GTM-[A-Z0-9]{4,12}$/', $id) ? $id : '';
        if ($id === '') {
            return '';
        }
        $consent = $cookiesMode !== 'none'
            ? "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});" : '';
        $loader = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');";

        return '<script>window.dataLayer=window.dataLayer||[];' . ($defineGtag ? 'function gtag(){dataLayer.push(arguments);}' . $consent : '') . "</script>\n"
            . ($cookiesMode === 'builtin' ? '<script type="text/plain" data-gtm>' . $loader . '</script>' : '<script>' . $loader . '</script>') . "\n";
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed>|null $newsItem
     * @return array<string, mixed>
     */
    private function structuredData(string $title, array $meta, ?array $newsItem): array
    {
        $s = $this->app->settings();
        // company from "Settings → Business details" (Organization or LocalBusiness with address, opening hours and map)
        $issuer = Company::schema($s, $this->siteSettings, $this->absoluteUrl(...)) + \Talea\Core\Facts::schema($this->app); // + facts with a schema property (2.10)
        if (($issuer['@type'] ?? 'Organization') !== 'Organization' && ($special = \Talea\Core\Hours::schema(\Talea\Core\Hours::exceptions($this->app->db()))) !== []) {
            $issuer['specialOpeningHoursSpecification'] = $special; // holidays and other exceptions to the opening hours (2.10)
        }
        if ($newsItem === null) {
            $chart = [
                ['@type' => 'WebSite', '@id' => $this->siteSettings . '#web', 'name' => $s->get('site_name'), 'url' => $this->siteSettings,
                    'description' => $s->get('site_description'), 'inLanguage' => \Talea\Core\Language::code(), 'publisher' => ['@id' => $issuer['@id']],
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => $this->siteSettings . $this->path('search?q={q}'), 'query-input' => 'required name=q']],
                $issuer,
            ];
            if (!empty($meta['faq'])) {
                // a builder page with questions and answers – next to the site and company details, not instead of them
                $chart[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $d): array => [
                    '@type' => 'Question', 'name' => $d[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $d[1]],
                ], $meta['faq'])];
            }
            foreach ($meta['structured'] ?? [] as $i => $node) {
                // typed structured data of the page (the Structured data element): joins the same graph, linked to the company and website nodes;
                // a manual FAQPage replaces the automatic one so the page never carries two
                $rendered = \Talea\Builder\StructuredData::render($node, $this->absoluteUrl(...), ['company' => (string) $issuer['@id'], 'web' => $this->siteSettings . '#web']);
                if (($rendered['@type'] ?? '') === 'FAQPage') {
                    $chart = array_values(array_filter($chart, fn (array $c): bool => ($c['@type'] ?? '') !== 'FAQPage'));
                }
                $chart[] = ['@id' => $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')) . '#data-' . ($i + 1)] + $rendered;
            }
            if (!empty($meta['item'])) {
                // a collection item page: its schema.org type from the collection (service, person, product, event, question)
                $node = \Talea\Builder\CollectionSchema::forItem($meta['item']['collection'], $meta['item']['item'], $this->app->request->origin() . $this->app->url(ltrim($this->app->request->path(), '/')),
                    (string) ($meta['description'] ?? ''), (string) ($meta['image'] ?? ''), (string) $issuer['@id'], $issuer); // the whole company node: the hiring organization of a job posting (2.11)
                if ($node !== null) {
                    $chart[] = $node;
                }
            }
            if (count($meta['breadcrumbs'] ?? []) > 1) {
                $chart[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn (array $d, int $i): array => array_filter([
                    '@type' => 'ListItem', 'position' => $i + 1, 'name' => $d[0], 'item' => $d[1] !== '' ? $this->app->request->origin() . $d[1] : null,
                ]), $meta['breadcrumbs'], array_keys($meta['breadcrumbs']))];
            }

            return ['@context' => 'https://schema.org', '@graph' => $chart];
        }

        return ['@context' => 'https://schema.org', '@graph' => [
            array_filter([
                '@type' => 'BlogPosting',
                'headline' => mb_substr($newsItem['title'], 0, 110),
                'description' => $meta['description'] ?? '',
                'image' => $newsItem['image'] !== '' ? [$this->absoluteUrl($newsItem['image'])] : null,
                'datePublished' => date('c', strtotime($newsItem['published_at'])),
                'dateModified' => date('c', strtotime($newsItem['updated_at'] ?? $newsItem['edited_at'] ?? $newsItem['published_at'])),
                'author' => $newsItem['author_name'] !== null ? array_filter(['@type' => 'Person', 'name' => $newsItem['author_name'],
                    'jobTitle' => $newsItem['author_position'] ?? '', 'description' => trim((string) ($newsItem['author_bio'] ?? '')), 'image' => ($newsItem['author_photo'] ?? '') !== '' ? $this->absoluteUrl($newsItem['author_photo']) : '',
                    'sameAs' => ($newsItem['author_url'] ?? '') !== '' ? $newsItem['author_url'] : '']) : $issuer,
                'publisher' => $issuer,
                'articleSection' => $newsItem['category_name'],
                'keywords' => implode(', ', array_column($newsItem['tags'] ?? [], 'name')) ?: null,
                'mainEntityOfPage' => $this->page($this->path('news/') . $newsItem['slug']),
                'inLanguage' => \Talea\Core\Language::code(),
            ]),
            ...($this->faqData($newsItem)),
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $s->get('site_name'), 'item' => $this->siteSettings],
                ['@type' => 'ListItem', 'position' => 2, 'name' => t('News'), 'item' => $this->page($this->path('news'))],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $newsItem['category_name'], 'item' => $this->page($this->path('news/category/') . $newsItem['category_slug'])],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $newsItem['title']],
            ]],
        ]];
    }

    /**
     * Questions and answers of a news item: text "question \n answer \n\n ..." -> pairs.
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function faq(?string $text): array
    {
        $pairs = [];
        foreach (preg_split('/\R\s*\R/', trim((string) $text)) ?: [] as $block) {
            $rows = preg_split('/\R/', trim($block), 2) ?: [];
            if (count($rows) === 2 && trim($rows[0]) !== '' && trim($rows[1]) !== '') {
                $pairs[] = [trim($rows[0]), trim($rows[1])];
            }
        }

        return $pairs;
    }

    /** @return list<array<string, mixed>> */
    private function faqData(array $newsItem): array
    {
        $faq = self::faq($newsItem['faq'] ?? '');

        return $faq === [] ? [] : [['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $d): array => [
            '@type' => 'Question', 'name' => $d[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $d[1]],
        ], $faq)]];
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return str_starts_with($url, '/') ? $this->app->request->origin() . $url : $this->root . $url;
    }

    /** Simple conversion of HTML to Markdown - headings, paragraphs, lists, links, quotes, images. */
    private static function htmlToMarkdown(string $html): string
    {
        $md = preg_replace('/\s+/', ' ', $html) ?? $html;
        $replacements = [
            '#<h2[^>]*>(.*?)</h2>#i' => "\n\n## $1\n\n", '#<h3[^>]*>(.*?)</h3>#i' => "\n\n### $1\n\n", '#<h4[^>]*>(.*?)</h4>#i' => "\n\n#### $1\n\n",
            '#<(strong|b)>(.*?)</\1>#i' => '**$2**', '#<(em|i)>(.*?)</\1>#i' => '*$2*',
            '#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#i' => '[$2]($1)',
            '#<img [^>]*src="([^"]*)"[^>]*alt="([^"]*)"[^>]*>#i' => '![$2]($1)', '#<img [^>]*src="([^"]*)"[^>]*>#i' => '![]($1)',
            '#<figcaption[^>]*>(.*?)</figcaption>#i' => "\n*$1*\n",
            '#<li[^>]*>(.*?)</li>#i' => "\n- $1", '#</(ul|ol)>#i' => "\n\n",
            '#<blockquote[^>]*>(.*?)</blockquote>#i' => "\n\n> $1\n\n",
            '#<br\s*/?>#i' => "\n", '#</p>#i' => "\n\n", '#<hr[^>]*>#i' => "\n\n---\n\n",
        ];
        $md = preg_replace(array_keys($replacements), array_values($replacements), $md) ?? $md;
        $md = html_entity_decode(strip_tags($md), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $md = preg_replace(['/[ \t]+\n/', '/\n{3,}/', '/^[ \t]+/m'], ["\n", "\n\n", ''], $md) ?? $md;

        return trim($md);
    }
}
