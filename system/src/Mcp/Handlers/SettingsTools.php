<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Categories;
use Talea\Admin\Modules\Pages;
use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\SearchData;
use Talea\Core\SearchInsights;
use Talea\Front\SiteIdentity;
use Talea\Builder\SiteParts;
use Talea\Builder\DesignSystem;
use Talea\Builder\Library;
use Talea\Builder\Collections;
use Talea\Builder\Publisher;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;

/**
 * MCP tools: settings, redirects and audit (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait SettingsTools
{
    /** update_settings */
    private function toolUpdateSettings(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        $adminOnly();
        $instructionsBefore = $siteSettings->get('claude_instructions'); // a change goes to the change log and to Home (#149)
        $changes = is_array($a['settings'] ?? null) ? $a['settings'] : [];
        $stored = [];
        $errors = [];
        foreach ($changes as $key => $value) {
            $key = (string) $key;
            if ($key === 'extensions' || $key === 'additional_languages') {
                // lists (2.2): extensions switched on, further language versions of the site
                $list = array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value))));
                if ($key === 'extensions') {
                    $unknown = array_diff($list, array_keys(\Talea\Core\Extensions::CATALOG));
                    if ($unknown !== [] || !in_array('claude', $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown extensions: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys(\Talea\Core\Extensions::CATALOG)) . '.'
                            : 'The Claude connection cannot switch itself off – keep "claude" in the list (the user can switch it off in the admin).';
                        continue;
                    }
                    \Talea\Core\Extensions::save($siteSettings, $list);
                    $stored[$key] = \Talea\Core\Extensions::enabled($siteSettings);
                } else {
                    $codes = explode('|', \Talea\Core\Language::CODES);
                    $unknown = array_diff($list, $codes);
                    if ($unknown !== [] || in_array(\Talea\Core\Language::defaults($siteSettings), $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown language codes: ' . implode(', ', $unknown) . '.' : 'The default language of the site is not a further language version.';
                        continue;
                    }
                    $siteSettings->set($key, implode(',', array_unique($list)));
                    $stored[$key] = array_values(array_unique($list));
                }
                continue;
            }
            if ($key === 'screen_collections') {
                // screen mode (2.11): the collections the screen shows – only ones that exist
                $list = array_values(array_unique(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value)))));
                $known = array_map('strval', array_column($db->all('SELECT slug FROM {collections}'), 'slug'));
                $unknown = array_diff($list, $known);
                if ($unknown !== []) {
                    $errors[$key] = 'Unknown collections: ' . implode(', ', $unknown) . ' (list_collections).';
                    continue;
                }
                $siteSettings->set($key, implode(',', $list));
                $stored[$key] = $list;
                continue;
            }
            if (in_array($key, ['logo', 'favicon', 'share_image'], true)) {
                // logo and icon: a file from Media (upload_file) or from the system (image/…); empty = no logo / icon
                $path = ltrim(trim((string) $value), '/');
                $ok = $path === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#', $path) && !str_contains($path, '..') && is_file(TALEA_ROOT . '/' . $path));
                if ($ok && $key === 'favicon' && $path !== '') {
                    // icons for phones and for installing the site (media/icon-<n>.png) are prepared right away,
                    // as in Appearance
                    $ok = \Talea\Core\Images::icons(TALEA_ROOT . '/' . $path);
                } elseif ($key === 'favicon') {
                    array_map(fn (int $n): bool => @unlink(TALEA_ROOT . '/' . \Talea\Core\MediaFolder::icon($n)), \Talea\Core\Images::ICON_SIZES);
                }
                if (!$ok) {
                    $errors[$key] = 'A path to a file from Media (media/…) or from the system (image/…); an icon must be convertible to PNG.';
                    continue;
                }
                $siteSettings->set($key, $path);
                $stored[$key] = $path;
                continue;
            }
            if ($key === 'slugs_per_language' && is_scalar($value)) {
                // changes the database keys; refused while two language versions share an address (Core\Slug)
                $on = is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
                if (($refusal = \Talea\Core\Slug::switchPerLanguage($db, $siteSettings, $on)) !== null) {
                    $errors[$key] = sprintf($refusal[0], $refusal[1]);
                    continue;
                }
                $stored[$key] = $on ? '1' : '0';
                continue;
            }
            if ($key === 'site_url') {
                // the address password-reset and member sign-in links are built from: an administrator's connection with full
                // access only, and never while the server sets it (TALEA_SITE_URL, Core\SiteUrl)
                $url = is_string($value) ? rtrim(trim($value), '/') : '';
                $refusal = match (true) {
                    \Talea\Core\SiteUrl::locked($this->app->db()) => 'The server sets the site address (TALEA_SITE_URL) and applies it when the site starts: change it in the hosting and restart the site.',
                    ($auth->connection()['access'] ?? 'full') !== 'full' => 'The site address can be changed only by a connection with full access.',
                    \Talea\Admin\Modules\Settings::verifyValue($key, $url) === null => 'An address like https://example.com – the scheme and the host, no path.',
                    default => null,
                };
                if ($refusal !== null) {
                    $errors[$key] = $refusal;
                    continue;
                }
                \Talea\Core\SiteUrl::change($this->app, $url);
                $stored[$key] = $url;
                continue;
            }
            if ($key === 'stats' && is_scalar($value)) {
                // 3.2: the Statistics feature is the only switch – the old setting keeps working and switches the feature
                $on = is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
                $list = array_values(array_diff(\Talea\Core\Extensions::enabled($siteSettings), ['stats']));
                \Talea\Core\Extensions::save($siteSettings, $on ? [...$list, 'stats'] : $list);
                $stored[$key] = $on ? '1' : '0';
                continue;
            }
            $clean = preg_match(self::MCP_SETTINGS, $key) && is_scalar($value) ? \Talea\Admin\Modules\Settings::verifyValue($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value) : null;
            if ($clean !== null && $key === 'home_page' && (int) $clean > 0
                && $db->value('SELECT page_id FROM {pages} WHERE page_id = ? AND visible = TRUE AND deleted_at IS NULL', [(int) $clean]) === null) {
                $errors[$key] = 'Only a visible page can be the home page.';
                continue;
            }
            if ($clean !== null && $key === 'news_slug' && ($slugError = \Talea\Core\Routes::slugError($clean, $db)) !== null) {
                $errors[$key] = $slugError;
                continue;
            }
            if (in_array($key, ['head_code', 'marketing_code', 'cookies_external_code'], true)) {
                $errors[$key] = 'Code that runs on the site is set only in the administration (Settings), not through a Claude connection.';
                continue;
            }
            if (in_array($key, ['gtm_id', 'matomo_url', 'matomo_id'], true)) {
                // 3.3.2: a GTM container or a Matomo host runs whatever script its owner configures – the administrator sets them
                $errors[$key] = 'Google Tag Manager and Matomo load script chosen by whoever runs them, so they are set only in the administration (Settings → Analytics), not through a Claude connection. ga4_id and plausible_domain can be set here.';
                continue;
            }
            if ($clean === null) {
                $type = (string) \Talea\Admin\Modules\Settings::fieldType($key);
                $errors[$key] = !preg_match(self::MCP_SETTINGS, $key) ? 'This setting cannot be changed through MCP (only in the administration).'
                    : (str_starts_with($type, 'choice:') ? 'Invalid value. Allowed: ' . implode(', ', array_map(fn (string $v): string => $v === '' ? '"" (empty)' : $v, explode('|', substr($type, 7)))) : 'Invalid value.');
                continue;
            }
            $siteSettings->set($key, $clean);
            $stored[$key] = $clean;
        }
        if ($stored !== []) {
            \Talea\Front\Cache::clear();
        }
        \Talea\Core\ClaudeInstructions::changed($this->app, $instructionsBefore, $siteSettings->get('claude_instructions'));
        if (isset($stored['company_hours'])) {
            \Talea\Core\GoogleBusiness::hoursChanged($this->app); // the Business Profile gets the new week (2.13)
        }
        \Talea\Admin\Modules\Seo::ensureIndexNowKey($siteSettings); // instant indexing switched on here gets its key too
        if (($stored['screen_mode'] ?? '') === '1') {
            \Talea\Front\Screen::ensureSecret($siteSettings); // the address exists as soon as the mode is on – the administrator finds it in Settings → General
        }
        $current = [];
        foreach (['site_name', 'site_url', 'redirect_to_site_url', 'site_description', 'footer_text', 'logo', 'favicon', 'home_page', 'news_slug', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin', 'news_per_page',
            'share_image', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_email', 'company_hours', 'company_map', 'company_gps', 'dark_mode', 'theme_switcher',
            'indexing', 'schema_org', 'llms_txt', 'markdown_versions', 'indexnow', 'ai_crawlers', 'cookies_mode', 'cookies_log', 'stats', 'security_contact', 'slugs_per_language'] as $key) {
            $current[$key] = $siteSettings->get($key);
        }
        $current['stats'] = \Talea\Front\Stats::enabled($siteSettings) ? '1' : '0'; // the Statistics feature (3.2)
        $current += ['extensions' => \Talea\Core\Extensions::enabled($siteSettings), 'additional_languages' => \Talea\Core\Language::additional($siteSettings),
            'claude_instructions' => $siteSettings->get('claude_instructions'),
            'screen' => \Talea\Front\Screen::settings($siteSettings)]; // on, seconds, collections, news, hours, clock – never the secret address

        return ['saved' => $stored ?: new \stdClass(), 'errors' => $errors ?: new \stdClass(), 'settings' => $current];
    }

    /** list_redirects and save_redirect */
    private function toolListRedirects(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        if (!\Talea\Core\Extensions::isEnabled($siteSettings, 'redirects')) {
            throw new \DomainException('The Redirects extension is off (Extensions in the admin).');
        }
        if (!$auth->hasModule('redirects')) {
            throw new \DomainException('Only roles with the Redirects section can manage redirects.');
        }
        if ($name === 'save_redirect') {
            $adminOnly();
            $rawFrom = trim((string) ($a['from'] ?? ''));
            // an existing rule keeps what the call leaves out; a new one starts with exact, 301 and on. The row is found by its own
            // normalised address: with a match type only that one; without, the address as typed (a regex is never parsed as a URL)
            $given = isset($a['match']) ? (string) $a['match'] : null;
            $typed = preg_match('#^https?://#i', $rawFrom) ? \Talea\Admin\Modules\Redirects::normalizeFrom($rawFrom, 'exact') : ltrim($rawFrom, '/');
            $old = $given !== null
                ? $db->one('SELECT redirect_id, match_type, type, active FROM {redirects} WHERE from_path = ?', [\Talea\Admin\Modules\Redirects::normalizeFrom($rawFrom, $given)])
                : $db->one('SELECT redirect_id, match_type, type, active FROM {redirects} WHERE from_path = ? OR from_path = ? ORDER BY CASE WHEN from_path = ? THEN 0 ELSE 1 END', [$typed, $rawFrom, $typed]);
            if ($old !== null && $given !== null && $old['match_type'] !== $given) {
                $old = null; // the address is taken by a rule of another kind: it is replaced with the defaults of a new one
            }
            $match = $given ?? (string) ($old['match_type'] ?? 'exact');
            $type = (int) ($a['code'] ?? $old['type'] ?? 301);
            $active = isset($a['active']) ? (filter_var($a['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true) : (bool) ($old['active'] ?? true);
            $from = \Talea\Admin\Modules\Redirects::normalizeFrom($rawFrom, $match);
            $to = trim((string) ($a['to'] ?? ''));
            if ($from === '' || ($match === 'exact' && !preg_match('#^[A-Za-z0-9/._~%-]{1,250}$#', $from))) {
                throw new \InvalidArgumentException('The old path must be a path on this site, e.g. /old-page.');
            }
            if (!empty($a['delete'])) {
                $db->delete('redirects', ['from_path' => $from]);
            } else {
                if (!preg_match('#^https?://[^\s]{3,240}$#i', $to) && !preg_match('#^/?[^\s:]{0,250}$#', $to) && $type < 400) {
                    throw new \InvalidArgumentException('The new address must be a path (/new) or an https://… address.');
                }
                $rule = \Talea\Admin\Modules\Redirects::normalize($from, $to, $match, $type);
                if (is_string($rule)) {
                    throw new \InvalidArgumentException($rule);
                }
                // the old address names the row, so it cannot clash with another one
                \Talea\Admin\Modules\Redirects::store($db, $rule['from'], $rule['to'], $match, $type, $active);
            }
            \Talea\Front\Cache::clear();
        }

        // every missing address carries the page the visitor most likely meant (2.14, Core\RedirectMatcher)
        $pending = \Talea\Core\NotFound::pending($this->app, 60, 30);
        $suggestions = \Talea\Core\RedirectMatcher::suggestions($this->app, $pending);

        return ['redirects' => array_map(fn (array $r): array => ['from' => $r['from_path'], 'to' => $r['to_path'], 'code' => $r['type'], 'match' => $r['match_type'], 'active' => (bool) $r['active'], 'last_hit_at' => $r['last_hit_at'], 'count' => $r['hits'], 'auto_score' => $r['auto_score'] !== null ? (int) $r['auto_score'] : null],
                $db->all('SELECT from_path, to_path, type, match_type, active, last_hit_at, hits, auto_score FROM {redirects} ORDER BY from_path LIMIT 500')),
            'not_found' => array_map(fn (array $n): array => ['path' => $n['path'], 'count' => $n['count'], 'last_seen' => $n['last_seen_at'], 'referrer' => $n['referrer'], 'first_seen_at' => $n['first_seen_at']] + ['suggestion' => isset($suggestions[$n['path']]) ? '/' . $suggestions[$n['path']]['to'] : null, 'score' => $suggestions[$n['path']]['score'] ?? null], $pending),
            'auto' => ['on' => $siteSettings->bool('redirect_auto'), 'threshold' => \Talea\Core\RedirectMatcher::threshold($siteSettings)]];
    }

    /** save_redirect: the same as list_redirects */
    private function toolSaveRedirect(string $name, array $a): mixed
    {
        return $this->toolListRedirects($name, $a);
    }

    /** ignore_not_found */
    private function toolIgnoreNotFound(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'Addresses not found are handled by an administrator.');
        $paths = is_array($a['paths'] ?? null) ? array_values(array_filter($a['paths'], 'is_string')) : [];
        $need($paths !== [] || !empty($a['all']), 'Send paths, or all: true.');

        return ['ignored' => \Talea\Core\NotFound::ignore($this->app, !empty($a['all']) ? null : $paths),
            'not_found' => array_map(fn (array $n): array => ['path' => $n['path'], 'count' => $n['count'], 'last_seen' => $n['last_seen_at']], \Talea\Core\NotFound::pending($this->app, 60, 30))];
    }

    /** site_audit */
    private function toolSiteAudit(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin() || $auth->hasModule('pages'), 'The site audit is for administrators and editors of pages.');
        $audit = (new \Talea\Core\Audit($this->app))->scored();
        $findings = $audit['findings'];
        if (is_string($a['kind'] ?? null) && $a['kind'] !== '') {
            $findings = array_values(array_filter($findings, fn (array $f): bool => $f['kind'] === $a['kind']));
        }
        $counts = array_count_values(array_column($findings, 'kind'));

        return ['score' => $audit['score'], 'previous_score' => $audit['previous'], 'passed' => $audit['passed'], 'total' => count($findings), 'by_kind' => $counts ?: new \stdClass(), 'findings' => $findings];
    }

    /** get_stats (2.3): the same report as the Statistics screen */
    private function toolGetStats(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('stats')) {
            throw new \DomainException('Statistics are for administrators and users with access to Statistics.');
        }

        $days = (int) ($a['days'] ?? 30);
        $report = \Talea\Core\Report::build($this->app->db(), $days);
        $report['form_steps'] = \Talea\Front\FormSteps::overview($this->app->db(), $days);

        return $report + ['statistics_on' => \Talea\Front\Stats::enabled($this->app->settings())];
    }

    /** get_search_performance: what Search engines → Performance shows, for whoever may open it (the Statistics right) */
    private function toolGetSearchPerformance(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('stats')) {
            throw new \DomainException('Search data is for administrators and users with access to Statistics.');
        }
        $db = $this->app->db();
        [$connected, $engine] = SearchInsights::choose($db, (string) ($a['engine'] ?? ''));
        $out = ['engine' => $engine];
        $notes = [];
        foreach (SearchData::ENGINES as $e) {
            $day = SearchInsights::snapshots($db, $e)[$e] ?? null;
            $out[$e] = $day === null ? null : ['day' => $day];
            if ($day === null) {
                $notes[] = $e . ': ' . (in_array($e, $connected, true) ? 'connected; the first data arrives after the nightly update.' : 'not connected – the owner connects it in Administration → Connections.');
            }
        }
        if ($notes !== []) {
            $out['note'] = implode(' ', $notes);
        }
        $summary = SearchInsights::summary($db, $engine);
        if ($summary === null) {
            return $out;
        }
        $root = SearchInsights::root($this->app);
        $pages = $db->all('SELECT page_id, public_id, slug, language, translation_of FROM {pages} WHERE visible = TRUE AND deleted_at IS NULL AND password_hash IS NULL');
        $byKey = array_combine(SearchInsights::pageKeys($this->app, $pages, $root), array_column($pages, 'public_id'));
        $top = SearchInsights::byPath($db, $engine, $root);
        uasort($top, fn (array $x, array $y): int => $y['clicks'] <=> $x['clicks'] ?: $y['impressions'] <=> $x['impressions']);
        $queries = SearchInsights::rows($db, $engine, 'query');
        $numbers = fn (array $r): array => ['clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => (float) $r['ctr'], 'position' => (float) $r['position'] > 0 ? (float) $r['position'] : null];

        $out += [
            'summary' => $summary,
            'queries' => array_map(fn (array $r): array => ['query' => (string) $r['key']] + $numbers($r), array_slice($queries, 0, SearchData::TOP)),
            'pages' => array_map(fn (string $path, array $r): array => ['path' => '/' . $path, 'page_id' => $byKey[$path] ?? null] + $r, array_keys(array_slice($top, 0, SearchData::TOP, true)), array_slice($top, 0, SearchData::TOP, true)),
            'opportunities' => SearchInsights::opportunities($queries, SearchInsights::rows($db, $engine, 'page_query')),
            'low_ctr' => SearchInsights::lowCtr(SearchInsights::rows($db, $engine, 'page')),
            'sitemaps' => in_array(\Talea\Connectors\Google::KEY, $connected, true) ? SearchData::report($db, date('Y-m-d', strtotime('-30 days')))[\Talea\Connectors\Google::KEY]['sitemaps'] ?? [] : [],
        ];
        if (!empty($a['page'])) {
            $page = $db->one('SELECT page_id, slug, language, translation_of FROM {pages} WHERE page_id = ?', [(int) $a['page']]);
            $out['page'] = $page === null ? null : SearchInsights::forPath($db, SearchInsights::pageKeys($this->app, [$page], $root)[(int) $page['page_id']], $root);
        }

        return $out;
    }

    /** list_changes (2.2): the change log, people and Claude told apart */
    private function toolListChanges(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The change log is for administrators.');
        }
        $conditions = ['1 = 1'];
        $params = [];
        if (in_array($a['by'] ?? '', ['people', 'claude'], true)) {
            $conditions[] = $a['by'] === 'claude' ? "via <> ''" : "via = ''";
        }
        if (is_string($a['since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['since'])) {
            $conditions[] = 'created_at >= ?';
            $params[] = $a['since'] . ' 00:00:00';
        }
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));

        return ['changes' => array_map(fn (array $r): array => ['when' => substr((string) $r['created_at'], 0, 16), 'who' => $r['user_name'], 'claude_connection' => $r['via'] !== '' ? $r['via'] : null,
            'where' => $r['module'], 'action' => $r['action'], 'detail' => $r['description']] + ($r['reason'] !== '' ? ['reason' => $r['reason']] : []),
            $this->app->db()->all('SELECT created_at, user_name, via, module, action, description, reason FROM {change_log} WHERE ' . implode(' AND ', $conditions) . ' ORDER BY log_id DESC LIMIT ' . $limit, $params))];
    }

    /** list_agent_sessions (2.17, Core\AgentJournal) */
    private function toolListAgentSessions(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Claude sessions are for administrators.');
        }

        return ['sessions' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'connection' => $r['connection'], 'started' => substr((string) $r['started_at'], 0, 16),
            'last' => substr((string) $r['last_at'], 0, 16), 'rows_changed' => (int) $r['rows_changed'], 'untracked_writes' => (int) $r['rows_untracked'], 'tools' => (string) ($r['tools'] ?? ''),
            'undone' => $r['undone_at'] !== null ? substr((string) $r['undone_at'], 0, 16) : null], \Talea\Core\AgentJournal::sessions($this->app->db(), max(1, min(100, (int) ($a['limit'] ?? 20)))))];
    }

    /** undo_agent_session (2.17) */
    private function toolUndoAgentSession(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Only an administrator can undo a Claude session.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Undoing needs confirm=true – only when the user asked for it.');
        }

        return \Talea\Core\AgentJournal::undo($this->app, (int) ($a['id'] ?? 0), ($a['force'] ?? false) === true)
            + ['note' => 'Tell the user what was put back, and list any rows left because they changed since (conflicts) and writes undo could not follow (untracked).'];
    }

    /** processing_record (2.14, Core\Privacy) */
    private function toolProcessingRecord(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The record of processing is for administrators – it describes the whole site.');
        }
        $sections = \Talea\Core\Privacy::processingRecord($this->app);

        return ['markdown' => \Talea\Core\Privacy::markdown($sections, t('Record of processing')), 'sections' => count($sections),
            'note' => 'A template assembled from the configuration, not legal advice: the owner reviews it, adds what the site does not know (paper files, other systems) and keeps it with their documentation. Printable in Settings → Privacy and cookies → Record of processing.'];
    }

    /** accessibility_statement (2.14, Core\Privacy) */
    private function toolAccessibilityStatement(string $name, array $a): mixed
    {
        $statement = \Talea\Core\Privacy::accessibilityStatement($this->app);
        $pageId = $this->app->settings()->int('accessibility_statement_page');
        $page = $pageId > 0 ? $this->app->db()->one('SELECT page_id, slug, visible FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$pageId]) : null;

        return ['title' => $statement['title'], 'status' => $statement['status'] === 'full' ? 'fully_compliant' : 'partially_compliant', 'standard' => 'EN 301 549 / WCAG 2.1 AA',
            'barriers' => $statement['findings'], 'date' => $statement['date'], 'text' => $statement['text'],
            'page' => $page !== null ? ['id' => (int) $page['page_id'], 'slug' => $page['slug'], 'published' => (int) $page['visible'] === 1] : null,
            'next' => $statement['findings'] !== [] ? 'Fix the barriers (site_audit kind accessibility), then let the administrator regenerate the draft in Settings → Privacy and cookies. A template, not legal advice.'
                : ($page === null ? 'The administrator creates the page in Settings → Privacy and cookies (a hidden draft to review and publish). A template, not legal advice.' : 'Review the page with the user before publishing it (publish_build). A template, not legal advice.')];
    }
}
