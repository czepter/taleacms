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
 * MCP tools: look (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait LookTools
{
    /** list_classes (seznam_trid) */
    private function toolListClasses(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        // with the draft look: Claude works on what will be published (draft = changed in the draft look)
        $classes = \Kaleta\Core\Look::classes($db, $siteSettings, true);
        if (isset($a['nazev'])) {
            $classes = array_intersect_key($classes, [(string) $a['nazev'] => true]);
        }

        return array_values(array_map(fn (string $name, array $c): array => ['nazev' => $name, 'style' => $c['style'] ?: new \stdClass(), 'css' => $c['css']]
            + ($c['draft'] ? ['draft' => true] : []), array_keys($classes), $classes));
    }

    /** save_classes (uloz_tridy) */
    private function toolSaveClasses(string $name, array $a): mixed
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
        $conversion = HtmlConverter::convert('<style>' . str_ireplace('</style', '', (string) ($a['css'] ?? '')) . '</style>', true);
        $stored = [];
        $inDraft = [];
        foreach (array_unique(array_merge(array_keys($conversion['tridy']), array_keys($conversion['tridy_styl']))) as $className) {
            // merged: a rule only for :hover or @media keeps the class base and the other states (nahradit: true = the whole class anew)
            $previous = empty($a['nahradit']) ? (\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className] ?? null) : null; // the draft, when there is one
            $style = ($conversion['tridy_styl'][$className] ?? []) + (array) ($previous['style'] ?? []);
            $css = $conversion['tridy'][$className] ?? (string) ($previous['css'] ?? '');
            // a change of an existing class goes to the draft look, a new class is live at once (it changes nothing published)
            \Kaleta\Core\Look::setClass($siteSettings, $className, ['style' => $style, 'css' => $css]) ? $inDraft[] = $className : $stored[] = $className;
        }
        $deleted = [];
        foreach (is_array($a['smazat'] ?? null) ? $a['smazat'] : [] as $className) {
            if (is_string($className) && isset(\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className])) {
                \Kaleta\Core\Look::setClass($siteSettings, $className, null);
                $deleted[] = $className;
            }
        }

        return ['ulozeno' => $stored, 'look_draft' => $inDraft, 'deleted_at' => $deleted, 'hlaseni' => $conversion['hlaseni']]
            + ($inDraft !== [] || $deleted !== [] ? ['pozn' => 'Changes of existing classes and deletions are in the draft look – check them with preview_link site: true, publish with publish_look.'] : []);
    }

    /** update_design_system (uprav_design_system) */
    private function toolUpdateDesignSystem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $ds = isset($a['predvolba']) ? (DesignSystem::preset((string) $a['predvolba']) ?? throw new \InvalidArgumentException('Předvolba neexistuje: ' . implode(', ', array_keys(DesignSystem::PRESETS)) . '.')) : \Kaleta\Core\Look::designSystem($siteSettings);
        $changes = is_array($a['ds'] ?? null) ? $a['ds'] : [];
        foreach (['barvy', 'barvy_tmave'] as $group) {
            if (is_array($changes[$group] ?? null)) {
                $changes[$group] += $ds[$group];
            }
        }
        $ds = DesignSystem::sanitize($changes + $ds);
        \Kaleta\Core\Look::setDesignSystem($siteSettings, $ds); // to the draft look – publish_look publishes it

        return ['design_system' => $ds, 'citelnost' => DesignSystem::contrasts($ds), 'status' => 'draft look – visitors see it after publish_look',
            'nahled' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];
    }

    /** publish_look and discard_look and restore_look_version */
    private function toolPublishLook(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'The look of the site can be published only by an administrator.');
        if ($name === 'discard_look') {
            \Kaleta\Core\Look::discard($this->app->settings());

            return ['discarded' => true];
        }
        if ($name === 'restore_look_version') {
            \Kaleta\Core\Look::restoreVersion($this->app, $id);

            return ['draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $this->app->settings()), 'admin-'), 'preview' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];
        }
        $summary = \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::publish($this->app), 'admin-');
        if ($summary === []) {
            throw new \DomainException('There is no draft look to publish.');
        }

        return ['published' => $summary];
    }

    /** discard_look: the same as publish_look */
    private function toolDiscardLook(string $name, array $a): mixed
    {
        return $this->toolPublishLook($name, $a);
    }

    /** list_look_versions */
    private function toolListLookVersions(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return ['versions' => \Kaleta\Core\Look::versions($db), 'draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $this->app->settings()), 'admin-')];
    }

    /** restore_look_version: the same as publish_look */
    private function toolRestoreLookVersion(string $name, array $a): mixed
    {
        return $this->toolPublishLook($name, $a);
    }
}
