<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: settings, redirects and audit (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait SettingsTools
{
    /** update_settings (uprav_nastaveni) */
    private function toolUpdateSettings(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $changes = is_array($a['nastaveni'] ?? null) ? $a['nastaveni'] : [];
        $stored = [];
        $errors = [];
        foreach ($changes as $key => $value) {
            // keys of 1.4.0 and older still work (nazev_webu, firma_email, nazev_webu_de…)
            $key = (string) $key;
            $key = \Kaleta\Core\OldSettingsKeys::current($key); // keys of 1.4.0 and older keep working over MCP
            if ($key === 'extensions' || $key === 'additional_languages') {
                // lists (2.2): extensions switched on, further language versions of the site
                $list = array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value))));
                if ($key === 'extensions') {
                    $unknown = array_diff($list, array_keys(\Kaleta\Core\Extensions::CATALOG));
                    if ($unknown !== [] || !in_array('claude', $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown extensions: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys(\Kaleta\Core\Extensions::CATALOG)) . '.'
                            : 'The Claude connection cannot switch itself off – keep "claude" in the list (the user can switch it off in the admin).';
                        continue;
                    }
                    \Kaleta\Core\Extensions::save($siteSettings, $list);
                    $stored[$key] = \Kaleta\Core\Extensions::enabled($siteSettings);
                } else {
                    $codes = explode('|', \Kaleta\Core\Language::CODES);
                    $unknown = array_diff($list, $codes);
                    if ($unknown !== [] || in_array(\Kaleta\Core\Language::defaults($siteSettings), $list, true)) {
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
                $known = array_map('strval', array_column($db->all('SELECT seo_link FROM {kolekce}'), 'seo_link'));
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
                // logo and icon: a file from Media (nahraj_soubor) or from the system (image/…); empty = no logo / icon
                $path = ltrim(trim((string) $value), '/');
                $ok = $path === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#', $path) && !str_contains($path, '..') && is_file(KALETA_ROOT . '/' . $path));
                if ($ok && $key === 'favicon' && $path !== '') {
                    // icons for phones and for installing the site (media/ikona-<n>.png) are prepared right away,
                    // as in Appearance
                    $ok = \Kaleta\Core\Images::icons(KALETA_ROOT . '/' . $path);
                } elseif ($key === 'favicon') {
                    array_map(fn (int $n): bool => @unlink(KALETA_ROOT . '/media/ikona-' . $n . '.png'), \Kaleta\Core\Images::ICON_SIZES);
                }
                if (!$ok) {
                    $errors[$key] = 'Cesta k souboru z Médií (media/…) nebo ze systému (image/…); ikona musí jít převést na PNG.';
                    continue;
                }
                $siteSettings->set($key, $path);
                $stored[$key] = $path;
                continue;
            }
            $clean = preg_match(self::MCP_SETTINGS, $key) && is_scalar($value) ? \Kaleta\Admin\Modules\Settings::verifyValue($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value) : null;
            if ($clean !== null && $key === 'home_page' && (int) $clean > 0
                && $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND zobrazit = 1 AND smazano IS NULL', [(int) $clean]) === null) {
                $errors[$key] = 'Úvodní stránkou může být jen zveřejněná stránka.';
                continue;
            }
            if (in_array($key, ['head_code', 'marketing_code', 'cookies_external_code'], true)) {
                $errors[$key] = 'Code that runs on the site is set only in the administration (Settings), not through a Claude connection.';
                continue;
            }
            if ($clean === null) {
                $errors[$key] = preg_match(self::MCP_SETTINGS, $key) ? 'Neplatná hodnota.' : 'Tohle nastavení přes MCP měnit nejde (jen v administraci).';
                continue;
            }
            $siteSettings->set($key, $clean);
            $stored[$key] = $clean;
        }
        if ($stored !== []) {
            \Kaleta\Front\Cache::clear();
        }
        if (isset($stored['company_hours'])) {
            \Kaleta\Core\GoogleBusiness::hoursChanged($this->app); // the Business Profile gets the new week (2.13)
        }
        if (($stored['screen_mode'] ?? '') === '1') {
            \Kaleta\Front\Screen::ensureSecret($siteSettings); // the address exists as soon as the mode is on – the administrator finds it in Settings → General
        }
        $current = [];
        foreach (['site_name', 'site_description', 'footer_text', 'logo', 'favicon', 'home_page', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin', 'news_per_page',
            'share_image', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_email', 'company_hours', 'company_map', 'company_gps', 'dark_mode', 'theme_switcher',
            'indexing', 'schema_org', 'llms_txt', 'markdown_news', 'indexnow', 'ai_crawlers', 'cookies_mode', 'cookies_log', 'stats', 'security_contact'] as $key) {
            $current[$key] = $siteSettings->get($key);
        }
        $current += ['extensions' => \Kaleta\Core\Extensions::enabled($siteSettings), 'additional_languages' => \Kaleta\Core\Language::additional($siteSettings),
            'claude_instructions' => $siteSettings->get('claude_instructions'),
            'screen' => \Kaleta\Front\Screen::settings($siteSettings)]; // on, seconds, collections, news, hours, clock – never the secret address

        return ['ulozeno' => $stored ?: new \stdClass(), 'chyby' => $errors ?: new \stdClass(), 'nastaveni' => $current];
    }

    /** list_redirects and save_redirect (seznam_presmerovani, uloz_presmerovani) */
    private function toolListRedirects(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'presmerovani')) {
            throw new \DomainException('Rozšíření Přesměrování je vypnuté (Rozšíření v administraci).');
        }
        if (!$auth->hasModule('redirects')) {
            throw new \DomainException('Přesměrování smí spravovat jen role se sekcí Přesměrování.');
        }
        if ($name === 'uloz_presmerovani') {
            $adminOnly();
            $z = trim((string) parse_url((string) ($a['z'] ?? ''), PHP_URL_PATH), '/ ');
            $commandName = trim((string) ($a['na'] ?? ''));
            if ($z === '' || !preg_match('#^[A-Za-z0-9/._~%-]{1,250}$#', $z)) {
                throw new \InvalidArgumentException('Stará cesta musí být cesta na tomto webu, např. /stara-stranka.');
            }
            if (!empty($a['smazat'])) {
                $db->delete('presmerovani', ['z_adresy' => $z]);
            } else {
                if (!preg_match('#^https?://[^\s]{3,240}$#i', $commandName) && !preg_match('#^/?[^\s:]{0,250}$#', $commandName)) {
                    throw new \InvalidArgumentException('Nová adresa musí být cesta (/nova) nebo https://… adresa.');
                }
                $commandName = preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/');
                \Kaleta\Admin\Modules\Redirects::add($db, $z, $commandName);
                $db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [(int) ($a['typ'] ?? 301) === 302 ? 302 : 301, $z]);
                $db->delete('nenalezeno', ['cesta' => $z]);
            }
            \Kaleta\Front\Cache::clear();
        }

        return ['presmerovani' => $db->all('SELECT z_adresy AS z, na_adresu AS na, typ, pocet FROM {presmerovani} ORDER BY z_adresy LIMIT 500'),
            'nenalezeno' => \Kaleta\Core\NotFound::pending($this->app, 60, 30)];
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

        return ['ignored' => \Kaleta\Core\NotFound::ignore($this->app, !empty($a['all']) ? null : $paths),
            'not_found' => array_map(fn (array $n): array => ['path' => $n['cesta'], 'count' => $n['pocet'], 'last_seen' => $n['naposledy']], \Kaleta\Core\NotFound::pending($this->app, 60, 30))];
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
        $findings = (new \Kaleta\Core\Audit($this->app))->run();
        if (is_string($a['kind'] ?? null) && $a['kind'] !== '') {
            $findings = array_values(array_filter($findings, fn (array $f): bool => $f['kind'] === $a['kind']));
        }
        $counts = array_count_values(array_column($findings, 'kind'));

        return ['total' => count($findings), 'by_kind' => $counts ?: new \stdClass(), 'findings' => $findings];
    }

    /** get_stats (2.3): the same report as the Statistics screen */
    private function toolGetStats(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('stats')) {
            throw new \DomainException('Statistics are for administrators and users with access to Statistics.');
        }

        return \Kaleta\Core\Report::build($this->app->db(), (int) ($a['days'] ?? 30)) + ['statistics_on' => $this->app->settings()->bool('stats')
            && \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'statistika')];
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
            $conditions[] = 'cas >= ?';
            $params[] = $a['since'] . ' 00:00:00';
        }
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));

        return ['changes' => array_map(fn (array $r): array => ['when' => substr((string) $r['cas'], 0, 16), 'who' => $r['jmeno'], 'claude_connection' => $r['via'] !== '' ? $r['via'] : null,
            'where' => $r['modul'], 'action' => $r['akce'], 'detail' => $r['popis']],
            $this->app->db()->all('SELECT cas, jmeno, via, modul, akce, popis FROM {protokol} WHERE ' . implode(' AND ', $conditions) . ' ORDER BY idp DESC LIMIT ' . $limit, $params))];
    }
}
