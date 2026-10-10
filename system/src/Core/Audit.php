<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\Check;

/**
 * Site audit (1.9): what hurts a site in search engines and for visitors, collected across the whole site – for the
 * administrator (Administration → Site audit) and for Claude (site_audit), who can then fix it.
 *
 *  - links on the site to pages, news or items that do not exist (pages, site parts, templates, components, pop-ups,
 *    the menu, collection items and news), and broken external links found by the background link check (Core\Links:
 *    since 2.14 in page builds and collection items too, with the element);
 *  - orphan pages (2.14, Core\InternalLinks): published pages, news and items nothing on the site links to;
 *  - pages and item pages without a description, duplicate titles;
 *  - menu items pointing at hidden or deleted pages;
 *  - the builder check of every published build: buttons without a link, images without alt, the heading outline;
 *  - the most frequent addresses that end in 404 and have no redirect;
 *  - accessibility (2.3, the European Accessibility Act, WCAG 2.2 AA): colour contrast of the design system, links that
 *    do not say where they lead, images in text without alt, empty links, tables without header cells, frames without a
 *    title, and whether the site has an accessibility statement;
 *  - real-user speed (2.8): pages whose p75 LCP got worse by more than a quarter against the previous 30 days (Core\WebVitals);
 *  - review by (2.10): pages, news items, collection items and pop-ups whose review-by day has come (Core\Validity);
 *  - before handing the site over (2.4): what an agency checks before a client takes it – mail, backups, two-step sign-in,
 *    legal pages, indexing, tracking without consent, the client's own account, the agency's contact; since 2.8 also the
 *    security hygiene (Core\SecurityHygiene): unused accounts and Claude connections, the automatic suspension.
 *
 * Runs on demand only: a company site has hundreds of rows, not millions.
 */
final class Audit
{
    /** Kinds of findings in the order they are shown. */
    public const array KINDS = [
        'link' => 'Broken links', 'orphan' => 'Pages nobody links to', 'menu' => 'Menu', 'description' => 'Missing descriptions', 'title' => 'Duplicate titles',
        'build' => 'Buttons, images and headings', 'review' => 'Review by', 'job' => 'Job openings', 'document' => 'Document expires soon', 'accessibility' => 'Accessibility', 'not_found' => 'Frequent 404 errors', 'speed' => 'Speed', 'fact' => 'Facts', 'blueprint' => 'Industry checks', 'handover' => 'Before handing over',
    ];

    /** At most this many findings of one kind – beyond that the list would not help anyone. */
    private const int PER_KIND = 100;

    /** @var array<string, bool> resolved internal paths */
    private array $resolved = [];

    /** @var list<array<string, mixed>> */
    private array $findings = [];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return list<array{kind: string, where: string, message: string, edit: string, url: string, target: array<string, int|string>, element?: string}>
     */
    public function run(): array
    {
        $this->findings = [];
        $this->pages();
        $this->parts();
        $this->collections();
        $this->menus();
        $this->news();
        $this->brokenLinks();
        $this->orphans();
        $this->review();
        $this->jobs();
        $this->documents();
        $this->accessibility();
        $this->notFound();
        $this->speed();
        $this->facts();
        $this->blueprint();
        $this->handover();
        $order = array_flip(array_keys(self::KINDS));
        $counts = [];
        $out = [];
        foreach ($this->findings as $f) {
            $counts[$f['kind']] = ($counts[$f['kind']] ?? 0) + 1;
            if ($counts[$f['kind']] <= self::PER_KIND) {
                $out[] = $f;
            }
        }
        usort($out, fn (array $a, array $b): int => $order[$a['kind']] <=> $order[$b['kind']]);

        return $out;
    }

    /**
     * Only the checks of the whole site before handing it over (2.7: the end of the migration parity report).
     *
     * @return list<array<string, mixed>>
     */
    public function handoverFindings(): array
    {
        $this->findings = [];
        $this->handover();

        return $this->findings;
    }

    /* ---------- sources ---------- */

    private function pages(): void
    {
        $db = $this->app->db();
        $home = (int) $this->app->settings()->get('home_page');
        $titles = [];
        foreach ($db->all('SELECT page_id, title, seo_title, slug, description, text, build, visible, noindex, language FROM {pages} WHERE deleted_at IS NULL') as $p) {
            $where = t('Page “%s”', $p['title']);
            $target = ['page' => (int) $p['page_id']];
            $url = (int) $p['page_id'] === $home ? '' : (string) $p['slug'];
            $edit = 'admin.php?module=pages&action=edit&id=' . $this->app->db()->publicId('pages', (int) $p['page_id']);
            $build = $p['build'] !== null ? Build::fromJson((string) $p['build']) : null;
            $this->links($p['build'] ?? (string) $p['text'], $where, $build !== null ? 'admin.php?module=pages&action=builder&id=' . $this->app->db()->publicId('pages', (int) $p['page_id']) : $edit, $url, $target);
            if (!$p['visible']) {
                continue; // a hidden page is not in search engines – only its links matter (it may be published later)
            }
            if ($build !== null) {
                foreach (Check::builds($build, true, 50) as $c) {
                    $this->add('build', $where, $c['message'], 'admin.php?module=pages&action=builder&id=' . $this->app->db()->publicId('pages', (int) $p['page_id']), $url, $target, $c['id']);
                }
            }
            if ($p['noindex']) {
                continue;
            }
            if (trim((string) $p['description']) === '') {
                $this->add('description', $where, t('No description for search engines – they then make up their own from the page text.'), $edit, $url, $target);
            }
            $titles[$p['language'] . '|' . mb_strtolower(trim($p['seo_title'] !== '' ? (string) $p['seo_title'] : (string) $p['title']))][] = [$where, $edit, $url, $target];
        }
        foreach ($db->all('SELECT p.item_id, p.collection_id, p.name, p.slug, p.seo_title, p.description, p.data, p.language, k.slug AS collection, k.fields, k.name AS collection_name FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.detail = TRUE AND p.visible = TRUE AND p.noindex = FALSE AND p.deleted_at IS NULL') as $p) {
            $where = t('Item “%s” (%s)', $p['name'], $p['collection_name']);
            $edit = 'admin.php?module=collections&action=item&id=' . $this->app->db()->publicId('collections', (int) $p['collection_id']) . '&item=' . $this->app->db()->publicId('collection_items', (int) $p['item_id']);
            $url = ($p['language'] !== '' ? $p['language'] . '/' : '') . $p['collection'] . '/' . $p['slug'];
            $target = ['collection' => (string) $p['collection'], 'item' => (int) $p['item_id']];
            if (trim((string) $p['description']) === '' && !$this->hasLongerText((string) $p['data'], (string) $p['fields'])) {
                $this->add('description', $where, t('No description for search engines and no longer text to take one from.'), $edit, $url, $target);
            }
            $titles[$p['language'] . '|' . mb_strtolower(trim($p['seo_title'] !== '' ? (string) $p['seo_title'] : (string) $p['name']))][] = [$where, $edit, $url, $target];
        }
        foreach ($titles as $key => $same) {
            if (count($same) < 2) {
                continue;
            }
            foreach ($same as [$where, $edit, $url, $target]) {
                $this->add('title', $where, t('The title “%s” is used %d times – search engines cannot tell the pages apart.', explode('|', $key, 2)[1], count($same)), $edit, $url, $target);
            }
        }
    }

    private function parts(): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT type, language, variant, name, build FROM {site_parts} WHERE build IS NOT NULL') as $c) {
            $where = t('Site part “%s”', trim($c['type'] . ' ' . $c['variant'] . ' ' . $c['language']));
            $edit = 'admin.php?module=parts&action=builder&type=' . rawurlencode((string) $c['type']) . ($c['variant'] !== '' ? '&variant=' . rawurlencode((string) $c['variant']) : '') . '&language=' . rawurlencode((string) $c['language']);
            $this->links((string) $c['build'], $where, $edit, null, ['part' => (string) $c['type']]);
            foreach (Check::builds((array) Build::fromJson((string) $c['build']), false, 50) as $f) {
                $this->add('build', $where, $f['message'], $edit, null, ['part' => (string) $c['type']], $f['id']);
            }
        }
        foreach ($db->all('SELECT component_id, name, build FROM {components} WHERE build IS NOT NULL') as $c) {
            $this->links((string) $c['build'], t('Component “%s”', $c['name']), 'admin.php?module=components&action=builder&id=' . $this->app->db()->publicId('components', (int) $c['component_id']), null, ['component' => (int) $c['component_id']]);
        }
        foreach ($db->all('SELECT popup_id, name, build FROM {popups} WHERE build IS NOT NULL AND active = TRUE') as $c) {
            $this->links((string) $c['build'], t('Pop-up “%s”', $c['name']), 'admin.php?module=popups&action=builder&id=' . $this->app->db()->publicId('popups', (int) $c['popup_id']), null, ['popup' => (int) $c['popup_id']]);
        }
    }

    private function collections(): void
    {
        $db = $this->app->db();
        foreach ($db->all('SELECT collection_id, name, slug, detail, build FROM {collections}') as $k) {
            if ($k['detail'] && $k['build'] !== null) {
                $this->links((string) $k['build'], t('Item template of “%s”', $k['name']), 'admin.php?module=collections&action=builder&id=' . $this->app->db()->publicId('collections', (int) $k['collection_id']), null, ['collection' => (string) $k['slug']]);
            }
        }
        foreach ($db->all('SELECT p.item_id, p.collection_id, p.name, p.data, k.slug AS collection, k.name AS collection_name FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE p.visible = TRUE AND p.deleted_at IS NULL') as $p) {
            $this->links((string) $p['data'], t('Item “%s” (%s)', $p['name'], $p['collection_name']), 'admin.php?module=collections&action=item&id=' . $this->app->db()->publicId('collections', (int) $p['collection_id']) . '&item=' . $this->app->db()->publicId('collection_items', (int) $p['item_id']), null,
                ['collection' => (string) $p['collection'], 'item' => (int) $p['item_id']]);
        }
    }

    private function menus(): void
    {
        $db = $this->app->db();
        $pages = [];
        foreach ($db->all('SELECT page_id, title, visible, deleted_at FROM {pages}') as $p) {
            $pages[(int) $p['page_id']] = $p;
        }
        foreach ($db->all('SELECT location, language, items FROM {menus}') as $m) {
            $where = t('Menu “%s”', t(Menu::LOCATIONS[$m['location']] ?? $m['location'])) . ($m['language'] !== '' ? ' (' . $m['language'] . ')' : '');
            $walk = function (array $items) use (&$walk, $pages, $where): void {
                foreach ($items as $i) {
                    if (($i['type'] ?? '') === 'page') {
                        $p = $pages[(int) ($i['page_id'] ?? 0)] ?? null;
                        $message = match (true) {
                            $p === null => t('An item points at a page that no longer exists.'),
                            $p['deleted_at'] !== null => t('The item “%s” points at a page in the trash.', $p['title']),
                            !$p['visible'] => t('The item “%s” points at a hidden page – visitors do not see it in the menu.', $p['title']),
                            default => null,
                        };
                        if ($message !== null) {
                            $this->add('menu', $where, $message, 'admin.php?module=menu', null, ['menu' => 'menu']);
                        }
                    } elseif (($i['type'] ?? '') === 'link') {
                        $this->checkUrl((string) ($i['url'] ?? ''), $where, 'admin.php?module=menu', null, ['menu' => 'menu']);
                    }
                    if (is_array($i['children'] ?? null)) {
                        $walk($i['children']);
                    }
                }
            };
            $walk(json_decode((string) $m['items'], true) ?: []);
        }
    }

    private function news(): void
    {
        if (!Extensions::isEnabled($this->app->settings(), 'news')) {
            return;
        }
        $db = $this->app->db();
        foreach ($db->all('SELECT news_id, title, slug, language, intro, text FROM {news} WHERE visible = TRUE AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 500') as $c) {
            $this->links($c['intro'] . ' ' . $c['text'], t('News item “%s”', $c['title']), 'admin.php?module=news&action=edit&id=' . $this->app->db()->publicId('news', (int) $c['news_id']),
                $this->relative($this->app->newsItemUrl((string) $c['slug'], (string) $c['language'])), ['news' => (int) $c['news_id']]);
        }
    }

    /** External links the background check found broken (Core\Links) – in news items, page builds and collection items. */
    private function brokenLinks(): void
    {
        $where = ['news' => 'News item “%s”', 'page' => 'Page “%s”', 'item' => 'Item “%s”'];
        foreach (Links::broken($this->app, 200) as $v) {
            $this->findings[] = ['kind' => 'link', 'where' => t($where[$v['kind']], $v['title']), 'message' => t('The link %s does not work (%s).', $v['url'], $v['status'] === 0 ? t('no response') : 'HTTP ' . $v['status']),
                'edit' => $v['edit'], 'url' => $v['page'] === '' ? '' : $this->app->request->origin() . $this->app->url($v['page']), 'target' => $v['target']] + ($v['element'] !== '' ? ['element' => $v['element']] : []);
        }
    }

    /** Orphans (2.14, Core\InternalLinks): published content nothing on the site links to. */
    private function orphans(): void
    {
        $where = ['news' => 'News item “%s”', 'page' => 'Page “%s”', 'item' => 'Item “%s”'];
        foreach (InternalLinks::orphans($this->app) as $o) {
            $this->add('orphan', t($where[$o['kind']], $o['title']), t('No published page, menu or text links here – visitors and search engines reach it only by its address. Add a link from a related page (Claude: suggest_internal_links).'),
                $o['edit'], $o['path'], $o['target']);
        }
    }

    /** Review by (2.10): every page, news item, collection item and pop-up whose review-by day has come, with where to edit it. */
    private function review(): void
    {
        $db = $this->app->db();
        $home = (int) $this->app->settings()->get('home_page');
        $message = fn (string $day): string => t('Asked for a review by %s.', format_date($day));
        foreach ($db->all('SELECT page_id, title, slug, language, review_by FROM {pages} WHERE review_by IS NOT NULL AND review_by <= CURRENT_DATE AND deleted_at IS NULL ORDER BY review_by') as $p) {
            $this->add('review', t('Page “%s”', $p['title']), $message((string) $p['review_by']), 'admin.php?module=pages&action=edit&id=' . $this->app->db()->publicId('pages', (int) $p['page_id']),
                (int) $p['page_id'] === $home ? '' : ($p['language'] !== '' ? $p['language'] . '/' : '') . $p['slug'], ['page' => (int) $p['page_id']]);
        }
        if (Extensions::isEnabled($this->app->settings(), 'news')) {
            foreach ($db->all('SELECT news_id, title, slug, language, review_by FROM {news} WHERE review_by IS NOT NULL AND review_by <= CURRENT_DATE AND deleted_at IS NULL ORDER BY review_by') as $c) {
                $this->add('review', t('News item “%s”', $c['title']), $message((string) $c['review_by']), 'admin.php?module=news&action=edit&id=' . $this->app->db()->publicId('news', (int) $c['news_id']),
                    $this->relative($this->app->newsItemUrl((string) $c['slug'], (string) $c['language'])), ['news' => (int) $c['news_id']]);
            }
        }
        foreach ($db->all('SELECT p.item_id, p.collection_id, p.name, p.slug, p.language, p.review_by, k.slug AS collection, k.name AS collection_name, k.detail FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE p.review_by IS NOT NULL AND p.review_by <= CURRENT_DATE AND p.deleted_at IS NULL ORDER BY p.review_by') as $p) {
            $this->add('review', t('Item “%s” (%s)', $p['name'], $p['collection_name']), $message((string) $p['review_by']), 'admin.php?module=collections&action=item&id=' . $this->app->db()->publicId('collections', (int) $p['collection_id']) . '&item=' . $this->app->db()->publicId('collection_items', (int) $p['item_id']),
                $p['detail'] ? ($p['language'] !== '' ? $p['language'] . '/' : '') . $p['collection'] . '/' . $p['slug'] : null, ['collection' => (string) $p['collection'], 'item' => (int) $p['item_id']]);
        }
        foreach ($db->all('SELECT popup_id, name, review_by FROM {popups} WHERE review_by IS NOT NULL AND review_by <= CURRENT_DATE ORDER BY review_by') as $c) {
            $this->add('review', t('Pop-up “%s”', $c['name']), $message((string) $c['review_by']), 'admin.php?module=popups&action=edit&id=' . $this->app->db()->publicId('popups', (int) $c['popup_id']), null, ['popup' => (int) $c['popup_id']]);
        }
    }

    /**
     * Job openings (2.11): a visible job without a closing date ("true until") never hides itself, and its JobPosting has no
     * validThrough – Google then cannot tell it from an expired one (Builder\CollectionSchema).
     */
    private function jobs(): void
    {
        foreach ($this->app->db()->all('SELECT p.item_id, p.collection_id, p.name, p.slug, p.language, k.slug AS collection, k.name AS collection_name, k.detail FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id'
            . ' WHERE k.preset = ? AND p.visible = TRUE AND p.deleted_at IS NULL AND p.valid_until IS NULL ORDER BY p.name', [Jobs::PRESET]) as $p) {
            $this->add('job', t('Item “%s” (%s)', $p['name'], $p['collection_name']), t('Job opening without a closing date – set “true until” to the application deadline: the job then hides itself and search engines get validThrough, which they need to tell an open job from an expired one.'),
                'admin.php?module=collections&action=item&id=' . $this->app->db()->publicId('collections', (int) $p['collection_id']) . '&item=' . $this->app->db()->publicId('collection_items', (int) $p['item_id']),
                $p['detail'] ? ($p['language'] !== '' ? $p['language'] . '/' : '') . $p['collection'] . '/' . $p['slug'] : null, ['collection' => (string) $p['collection'], 'item' => (int) $p['item_id']]);
        }
    }

    /** Document library (2.11, Core\Documents): visible documents whose true-until day comes within 30 days – a new edition is due, or the date needs moving. */
    private function documents(): void
    {
        foreach ($this->app->db()->all('SELECT p.item_id, p.collection_id, p.name, p.slug, p.language, p.valid_until, k.slug AS collection, k.name AS collection_name, k.detail FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id'
            . ' WHERE k.preset = ? AND p.visible = TRUE AND p.deleted_at IS NULL AND p.valid_until IS NOT NULL AND p.valid_until BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL ? DAY ORDER BY p.valid_until', [Documents::PRESET, Documents::EXPIRY_WARNING_DAYS]) as $p) {
            $this->add('document', t('Item “%s” (%s)', $p['name'], $p['collection_name']), t('The document is valid until %s – upload the new edition or move the date; the day after, it hides itself and its download address stops working.', format_date((string) $p['valid_until'])),
                'admin.php?module=collections&action=item&id=' . $this->app->db()->publicId('collections', (int) $p['collection_id']) . '&item=' . $this->app->db()->publicId('collection_items', (int) $p['item_id']),
                $p['detail'] ? ($p['language'] !== '' ? $p['language'] . '/' : '') . $p['collection'] . '/' . $p['slug'] : null, ['collection' => (string) $p['collection'], 'item' => (int) $p['item_id']]);
        }
    }

    /** Link texts that do not say where the link leads (screen reader users often list the links of a page on their own). */
    private const string VAGUE_LINK = '/^(click here|here|read more|more|learn more|details|link|this|zde|sem|tady|klikněte sem|klikněte zde|více|číst dál|číst více|' // check-english: allow
        . 'hier|mehr|weiterlesen|mehr erfahren|ici|cliquez ici|plus|en savoir plus|aquí|haz clic aquí|más|leer más|qui|clicca qui|di più|leggi di più|' // check-english: allow
        . 'tutaj|kliknij tutaj|więcej|czytaj więcej|tu|kliknite sem|viac|čítať ďalej)[.!…]?$/iu'; // check-english: allow

    /** Page addresses of an accessibility statement in the site languages. */
    private const string STATEMENT = '/(accessibility|pristupnost|prístupnosť|pristupnost|barrierefreiheit|accessibilite|accesibilidad|accessibilita|dostepnosc)/i'; // check-english: allow

    private function accessibility(): void
    {
        $db = $this->app->db();
        $s = $this->app->settings();
        // the design system: text and buttons in light and (when the site has it) dark mode
        $ds = \Talea\Builder\DesignSystem::load($s);
        $looks = ['' => $ds] + ($s->get('dark_mode') !== 'off' ? [t(' (dark mode)') => ['colors' => $ds['colors_dark'] + $ds['colors']] + $ds] : []);
        foreach ($looks as $suffix => $look) {
            foreach (\Talea\Builder\DesignSystem::contrasts($look) as $c) {
                if (!$c['ok']) {
                    $this->add('accessibility', t('Site appearance') . $suffix, t('%s has a contrast of %s : 1 – text needs at least 4.5 : 1.', t($c['description']), number_format($c['ratio'], 1)),
                        'admin.php?module=appearance', null, ['look' => 'design_system']);
                }
            }
        }
        $home = (int) $s->get('home_page');
        $statement = false;
        foreach ($db->all('SELECT page_id, title, slug, text, build FROM {pages} WHERE deleted_at IS NULL AND visible = TRUE') as $p) {
            $statement = $statement || preg_match(self::STATEMENT, (string) $p['slug']) === 1;
            $build = $p['build'] !== null ? Build::fromJson((string) $p['build']) : null;
            $edit = $build !== null ? 'admin.php?module=pages&action=builder&id=' . $this->app->db()->publicId('pages', (int) $p['page_id']) : 'admin.php?module=pages&action=edit&id=' . $this->app->db()->publicId('pages', (int) $p['page_id']);
            $this->accessibleContent($build, (string) $p['text'], t('Page “%s”', $p['title']), $edit, (int) $p['page_id'] === $home ? '' : (string) $p['slug'], ['page' => (int) $p['page_id']]);
        }
        if (Extensions::isEnabled($s, 'news')) {
            foreach ($db->all('SELECT news_id, title, slug, language, intro, text FROM {news} WHERE visible = TRUE AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 500') as $c) {
                $this->accessibleContent(null, $c['intro'] . ' ' . $c['text'], t('News item “%s”', $c['title']), 'admin.php?module=news&action=edit&id=' . $this->app->db()->publicId('news', (int) $c['news_id']),
                    $this->relative($this->app->newsItemUrl((string) $c['slug'], (string) $c['language'])), ['news' => (int) $c['news_id']]);
            }
        }
        if (!$statement) {
            $this->add('accessibility', t('The whole site'), t('No accessibility statement – the European Accessibility Act expects a service to say how accessible it is and whom to contact about barriers. Add a page such as /accessibility.'),
                'admin.php?module=pages', null, ['site' => 'accessibility_statement']);
        }
    }

    /**
     * Accessibility of one page or news item: the HTML of its text and of the text elements of its build, and the button
     * texts of the build.
     *
     * @param array<string, mixed>|null $build
     * @param array<string, int|string> $target
     */
    private function accessibleContent(?array $build, string $text, string $where, string $edit, ?string $url, array $target): void
    {
        $fragments = [[$text, null]];
        $walk = function (array $nodes) use (&$walk, &$fragments, $where, $edit, $url, $target): void {
            foreach ($nodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $content = is_array($n['content'] ?? null) ? $n['content'] : [];
                foreach (['html', 'text'] as $key) {
                    if (is_string($content[$key] ?? null) && str_contains($content[$key], '<')) {
                        $fragments[] = [$content[$key], (string) ($n['id'] ?? '')];
                    }
                }
                if (($n['type'] ?? '') === 'button' && is_string($content['text'] ?? null) && preg_match(self::VAGUE_LINK, trim(strip_tags($content['text'])))) {
                    $this->add('accessibility', $where, t('The button “%s” does not say what it does – screen readers read buttons and links on their own.', trim(strip_tags($content['text']))), $edit, $url, $target, (string) ($n['id'] ?? ''));
                }
                if (is_array($n['children'] ?? null)) {
                    $walk($n['children']);
                }
            }
        };
        if ($build !== null) {
            $walk($build['children'] ?? []);
        }
        foreach ($fragments as [$html, $element]) {
            if ($html === '' || !str_contains($html, '<')) {
                continue;
            }
            $found = [];
            preg_match_all('#<img\b(?![^>]*\balt=)[^>]*>#i', $html, $m);
            if ($m[0] !== []) {
                $found[] = t('An image in the text has no description for blind visitors (alt).');
            }
            preg_match_all('#<a\b([^>]*)>(.*?)</a>#is', $html, $links, PREG_SET_ORDER);
            foreach ($links as [, $attributes, $inner]) {
                $label = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5));
                if ($label === '' && !preg_match('/aria-label=|title=/i', $attributes) && !preg_match('#<img\b[^>]*\balt="[^"]+#i', $inner)) {
                    $found[] = t('A link has no text – a screen reader has nothing to read.');
                } elseif ($label !== '' && preg_match(self::VAGUE_LINK, $label)) {
                    $found[] = t('The link “%s” does not say where it leads – screen readers read links on their own.', $label);
                }
            }
            if (preg_match('#<table\b#i', $html) && !preg_match('#<th\b#i', $html)) {
                $found[] = t('A table has no header cells (th) – screen readers cannot tell what the columns mean.');
            }
            if (preg_match('#<iframe\b(?![^>]*\btitle=)#i', $html)) {
                $found[] = t('An embedded frame (iframe) has no title.');
            }
            foreach (array_unique($found) as $message) {
                $this->add('accessibility', $where, $message, $edit, $url, $target, $element);
            }
        }
    }

    /** Before handing the site over to a client (2.4) – each item says what to set and where. */
    /** The checks of the applied industry blueprints (2.11, Core\Blueprint): facts, items, company details, pages, fresh prices. */
    private function blueprint(): void
    {
        foreach (Blueprint::findings($this->app) as $i => [$name, $message, $edit]) {
            $this->add('blueprint', $name, $message, $edit, null, ['blueprint' => $i]);
        }
    }

    private function handover(): void
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $site = t('The whole site');
        $check = function (bool $ok, string $message, string $edit, string $key) use ($site): void {
            if (!$ok) {
                $this->add('handover', $site, $message, $edit, null, ['handover' => $key]);
            }
        };
        $check($s->get('site_email') !== '', t('No site e-mail: enquiries and password resets have nowhere to go.'), 'admin.php?module=settings&tab=general', 'site_email');
        $check($s->get('mail_mode') === 'smtp', t('E-mail goes out through the host’s mail() – set an SMTP server so enquiries and newsletters do not end up in spam.'), 'admin.php?module=settings&tab=mail', 'smtp');
        $check($s->get('remote_backup') !== '' && $s->get('remote_backup') !== 'off', t('Backups stay on the same server – add an off-site copy (FTPS or S3) in case the hosting is lost.'), 'admin.php?module=settings&tab=backups', 'remote_backup');
        $check(trim($s->get('company_name')) !== '' && trim($s->get('company_street')) !== '', t('Company details are missing – the footer, the imprint and search engines use them.'), 'admin.php?module=business', 'company');
        $check($s->bool('indexing'), t('Search engines are blocked – switch indexing on when the site goes live.'), 'admin.php?module=settings&tab=seo', 'indexing');
        $check($s->get('favicon') !== '' || is_file(TALEA_ROOT . '/media/icon-32.png'), t('No site icon (favicon) – browsers and phones show a blank one.'), 'admin.php?module=appearance', 'favicon');
        $tracking = trim($s->get('ga4_id') . $s->get('matomo_url') . $s->get('marketing_code')) !== '';
        $check(!$tracking || $s->get('cookies_mode') !== 'none', t('Analytics or marketing codes run without a cookie bar – visitors in the EU must consent first.'), 'admin.php?module=settings&tab=cookies', 'cookies');
        $check($s->get('security_contact') !== '', t('No security contact – add who takes reports of security problems (published as security.txt).'), 'admin.php?module=settings&tab=seo', 'security_contact');
        // accounts and access (2.8): the same findings as System status, each with the user to fix
        $hygiene = SecurityHygiene::findings($this->app);
        foreach ($hygiene['two_step'] as $u) {
            $this->add('handover', $site, t('The administrator %s signs in without two-step sign-in or a passkey.', SecurityHygiene::displayName($u)),
                'admin.php?module=users&action=edit&id=' . $this->app->db()->publicId('users', (int) $u['user_id']), null, ['handover' => 'two_step', 'username' => (int) $u['user_id']]);
        }
        foreach ($hygiene['unused_accounts'] as $u) {
            $this->add('handover', $site, t('The account %s has not been used for %d days (last activity %s) – block it, or let the automatic suspension do it.', SecurityHygiene::displayName($u), SecurityHygiene::daysAgo((string) $u['last']), format_date((string) $u['last'])),
                'admin.php?module=users&action=edit&id=' . $this->app->db()->publicId('users', (int) $u['user_id']), null, ['handover' => 'unused_account', 'username' => (int) $u['user_id']]);
        }
        foreach ($hygiene['unused_connections'] as $c) {
            $this->add('handover', $site, t('The Claude connection “%s” of %s has not been used for %d days – revoke it, or let the automatic suspension do it.', (string) $c['name'], (string) $c['username'], SecurityHygiene::daysAgo((string) $c['last'])),
                'admin.php?module=users&action=edit&id=' . $this->app->db()->publicId('users', (int) $c['user_id']) . '#connections', null, ['handover' => 'unused_connection', 'username' => (int) $c['user_id'], 'connection' => (string) $c['name']]);
        }
        $check(SecurityHygiene::autoSuspend($s) !== [], t('Unused accounts and Claude connections are only reported – switch on the automatic suspension (Settings → General) so that leftover access closes itself.'), 'admin.php?module=settings&tab=general', 'auto_suspend');
        $check((int) $db->value('SELECT COUNT(*) FROM {users} WHERE admin < 2 AND blocked = FALSE') > 0, t('The client has no account of their own yet – create one with the Client role (Users → Roles).'), 'admin.php?module=users', 'client_account');
        $check($s->get('agency_name') !== '' && ($s->get('agency_email') !== '' || $s->get('agency_phone') !== ''), t('Your contact is not set – the client will not see whom to ask (Settings → General → Built and looked after by).'), 'admin.php?module=settings&tab=general', 'agency');
        if (Extensions::isEnabled($s, 'newsletter_signup')) {
            $check($s->int('tasks_last_run') > 0, t('Background tasks have never run – newsletters are sent only while they do. Add the cron line from System status.'), 'admin.php?module=status', 'cron');
        }
        // findings of switched-on add-ons (API 2, Extension\Api::handoverFindings), e.g. the domain watch
        foreach (\Talea\Extension\Registry::handoverFindings($this->app) as $finding) {
            $this->add('handover', $site, $finding['message'], $finding['edit'], null, ['handover' => $finding['key']]);
        }
    }

    private function notFound(): void
    {
        foreach (NotFound::pending($this->app, 30, 25) as $n) {
            $path = trim($n['path'], '/');
            $this->add('not_found', '/' . $path, t('%d visits in the last 30 days ended with “page not found” – add a redirect to the right page.', (int) $n['count']),
                'admin.php?module=redirects&from=' . rawurlencode('/' . $path) . '#edit', null, ['redirect_from' => '/' . $path]);
        }
    }

    /**
     * Business facts (2.10): a {{fact.key}} token of a fact that does not exist and a computed token that cannot be
     * computed show nothing to visitors; a proof number typed in as digits (the counter) goes stale – it should be a fact.
     */
    private function facts(): void
    {
        $known = Facts::all($this->app);
        foreach (Facts::texts($this->app->db()) as $t) {
            preg_match_all(Facts::TOKEN_PATTERN, Facts::withoutCode($t['text']), $m);
            foreach (array_unique(array_diff($m[1], array_keys($known))) as $key) {
                $this->add('fact', $t['where'], t('The fact {{fact.%s}} does not exist – visitors see nothing in its place. Create it in Facts, or fix the key.', $key), $t['edit'], null, $t['target']);
            }
            preg_match_all(Facts::COMPUTED_PATTERN, Facts::withoutCode($t['text']), $m, PREG_SET_ORDER);
            foreach (array_unique(array_map(fn (array $c): string => $c[1] . ':' . $c[2], $m)) as $token) {
                [$kind, $argument] = explode(':', $token, 2);
                if (Facts::computed($this->app, $kind, $argument) === null) {
                    $this->add('fact', $t['where'], t('The token {{%s}} cannot be computed – visitors see nothing in its place. years_since takes a year, a date (YYYY-MM-DD) or a fact with one; count takes the address of a collection, or news.', $token), $t['edit'], null, $t['target']);
                }
            }
            foreach ($t['build'] !== null ? Facts::typedNumbers($t['build']) : [] as $n) {
                $this->add('fact', $t['where'], t('The number %s is typed in – make it a fact ({{fact.key}}) or a count, so it stays true.', $n['number']), $t['edit'], null, $t['target'], $n['id']);
            }
        }
    }

    /** Real-user speed (2.8): pages that got slower – p75 LCP of the last 30 days against the 30 days before, with enough measurements in both. */
    private function speed(): void
    {
        if (!Extensions::isEnabled($this->app->settings(), 'stats')) {
            return;
        }
        foreach (WebVitals::regressions($this->app->db()) as $r) {
            $this->add('speed', $r['path'], t('Loading got slower: visitors wait %s s for the main content (p75 LCP) in the last 30 days, %s s in the 30 days before (%d measurements) – check the images, fonts and embeds above the fold.',
                format_count($r['current'] / 1000, 1), format_count($r['previous'] / 1000, 1), $r['samples']), 'admin.php?module=stats', $this->relative($r['path']), ['path' => $r['path']]);
        }
    }

    /* ---------- links ---------- */

    /** Every link in a build (JSON) or HTML; internal ones must lead somewhere. */
    private function links(string $content, string $where, string $edit, ?string $url, array $target): void
    {
        preg_match_all('#"(?:link|url|href)":"((?:[^"\\\\]|\\\\.)*)"|href=\\\\?"([^"\\\\]*)\\\\?"#', $content, $m, PREG_SET_ORDER);
        $seen = [];
        foreach ($m as $match) {
            $link = stripslashes($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
            if ($link === '' || isset($seen[$link])) {
                continue;
            }
            $seen[$link] = true;
            $this->checkUrl($link, $where, $edit, $url, $target);
        }
    }

    private function checkUrl(string $link, string $where, string $edit, ?string $url, array $target): void
    {
        if ($link === '' || str_contains($link, '{{') || preg_match('#^(\#|mailto:|tel:|javascript:)#i', $link)) {
            return;
        }
        $origin = $this->app->request->origin() . $this->app->request->basePath();
        if (preg_match('#^https?://#i', $link)) {
            if (!str_starts_with(strtolower($link), strtolower($origin) . '/') && strtolower(rtrim($link, '/')) !== strtolower($origin)) {
                return; // an external link – the background link check tests those
            }
            $link = substr($link, strlen($origin));
        }
        if (!str_starts_with($link, '/')) {
            return;
        }
        $path = (string) parse_url($link, PHP_URL_PATH);
        if (!$this->resolves($path)) {
            $this->add('link', $where, t('The link %s leads to a page that does not exist.', $path), $edit, $url, $target);
        }
    }

    /** Does an internal path lead to something on the site (a page, news, an item, a file or a system address)? */
    public function resolves(string $path): bool
    {
        $path = '/' . trim(rawurldecode($path), '/');
        if (isset($this->resolved[$path])) {
            return $this->resolved[$path];
        }
        $db = $this->app->db();
        $segments = $path === '/' ? [] : explode('/', ltrim($path, '/'));
        if ($segments !== [] && in_array($segments[0], Language::additional($this->app->settings()), true)) {
            array_shift($segments);
        }
        $rest = '/' . implode('/', $segments);
        [$internal] = Routes::internalPath($rest, $db);
        $s = $internal === '/' ? [] : explode('/', ltrim($internal, '/'));
        $ok = match (true) {
            $s === [] => true,
            is_file(TALEA_ROOT . '/' . ltrim($path, '/')) && preg_match('#^/(media|image)/#', $path) === 1 => true,
            in_array($s[0], ['search', 'rss.xml', 'feed.json', 'sitemap.xml', 'robots.txt', 'llms.txt', 'admin.php', 'mcp'], true) => true,
            $s[0] === 'news' => $this->newsPathExists(array_slice($s, 1)),
            $db->value('SELECT 1 FROM {pages} WHERE slug = ? AND visible = TRUE AND deleted_at IS NULL', [implode('/', $s)]) !== null => true,
            count($s) === 2 && $db->value('SELECT 1 FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE k.slug = ? AND k.detail = TRUE AND p.slug = ? AND p.visible = TRUE AND p.deleted_at IS NULL', [$s[0], $s[1]]) !== null => true,
            $db->value('SELECT 1 FROM {redirects} WHERE from_path = ?', [trim($path, '/')]) !== null => true,
            default => false,
        };

        return $this->resolved[$path] = $ok;
    }

    /** @param list<string> $s the path after /news */
    private function newsPathExists(array $s): bool
    {
        $db = $this->app->db();

        return match (true) {
            $s === [] => true,
            count($s) === 1 => $db->value('SELECT 1 FROM {news} WHERE slug = ? AND visible = TRUE AND deleted_at IS NULL', [$s[0]]) !== null,
            count($s) === 2 && $s[0] === 'category' => $db->value('SELECT 1 FROM {categories} WHERE slug = ?', [$s[1]]) !== null,
            count($s) === 2 && $s[0] === 'tag' => $db->value('SELECT 1 FROM {tags} WHERE slug = ?', [$s[1]]) !== null,
            default => false,
        };
    }

    /* ---------- helpers ---------- */

    private function hasLongerText(string $data, string $fields): bool
    {
        $values = json_decode($data, true) ?: [];
        foreach (json_decode($fields, true) ?: [] as $f) {
            if (in_array($f['type'] ?? '', ['lines', 'html'], true) && trim(strip_tags((string) ($values[$f['key']] ?? ''))) !== '') {
                return true;
            }
        }

        return false;
    }

    /** A public URL of the app (with the base path) as a path inside the site. */
    private function relative(string $url): string
    {
        return ltrim(substr($url, strlen($this->app->request->basePath())), '/');
    }

    /**
     * @param ?string $url path of the page on the site ('' = the home page, null = no page of its own)
     * @param array<string, int|string> $target what to fix, for Claude
     */
    private function add(string $kind, string $where, string $message, string $edit, ?string $url, array $target, ?string $element = null): void
    {
        $this->findings[] = ['kind' => $kind, 'where' => $where, 'message' => $message, 'edit' => $this->app->url($edit),
            'url' => $url === null ? '' : $this->app->request->origin() . $this->app->url($url), 'target' => $target] + ($element !== null ? ['element' => $element] : []);
    }
}
