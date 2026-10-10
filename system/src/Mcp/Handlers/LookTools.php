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
    /** list_classes */
    private function toolListClasses(string $name, array $a): mixed
    {
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        // with the draft look: Claude works on what will be published (draft = changed in the draft look)
        $classes = \Kaleta\Core\Look::classes($db, $siteSettings, true);
        if (isset($a['name'])) {
            $classes = array_intersect_key($classes, [(string) $a['name'] => true]);
        }

        return array_values(array_map(fn (string $name, array $c): array => ['name' => $name, 'style' => $c['style'] ?: new \stdClass(), 'css' => $c['css']]
            + ($c['draft'] ? ['draft' => true] : []), array_keys($classes), $classes));
    }

    /** save_classes */
    private function toolSaveClasses(string $name, array $a): mixed
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
        $conversion = HtmlConverter::convert('<style>' . str_ireplace('</style', '', (string) ($a['css'] ?? '')) . '</style>', true);
        $stored = [];
        $inDraft = [];
        foreach (array_unique(array_merge(array_keys($conversion['classes']), array_keys($conversion['class_styles']))) as $className) {
            // merged: a rule only for :hover or @media keeps the class base and the other states (replace: true = the whole class anew)
            $previous = empty($a['replace']) ? (\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className] ?? null) : null; // the draft, when there is one
            $style = ($conversion['class_styles'][$className] ?? []) + (array) ($previous['style'] ?? []);
            $css = $conversion['classes'][$className] ?? (string) ($previous['css'] ?? '');
            // a change of an existing class goes to the draft look, a new class is live at once (it changes nothing published)
            \Kaleta\Core\Look::setClass($siteSettings, $className, ['style' => $style, 'css' => $css]) ? $inDraft[] = $className : $stored[] = $className;
        }
        $deleted = [];
        foreach (is_array($a['delete'] ?? null) ? $a['delete'] : [] as $className) {
            if (is_string($className) && isset(\Kaleta\Core\Look::classes($db, $siteSettings, true)[$className])) {
                \Kaleta\Core\Look::setClass($siteSettings, $className, null);
                $deleted[] = $className;
            }
        }

        return ['saved' => $stored, 'look_draft' => $inDraft, 'deleted' => $deleted, 'notes' => $conversion['notes']]
            + ($inDraft !== [] || $deleted !== [] ? ['note' => 'Changes of existing classes and deletions are in the draft look – check them with preview_link site: true, publish with publish_look.'] : []);
    }

    /** update_design_system */
    private function toolUpdateDesignSystem(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can use this tool.');
            }
        };

        $adminOnly();
        $ds = isset($a['preset']) ? (DesignSystem::preset((string) $a['preset']) ?? throw new \InvalidArgumentException('The preset does not exist: ' . implode(', ', array_keys(DesignSystem::PRESETS)) . '.')) : \Kaleta\Core\Look::designSystem($siteSettings);
        $changes = is_array($a['design'] ?? null) ? $a['design'] : [];
        foreach (['colors', 'colors_dark'] as $group) {
            if (is_array($changes[$group] ?? null)) {
                $changes[$group] += $ds[$group];
            }
        }
        $ds = DesignSystem::sanitize($changes + $ds);
        \Kaleta\Core\Look::setDesignSystem($siteSettings, $ds); // to the draft look – publish_look publishes it

        return ['design_system' => $ds, 'readability' => DesignSystem::contrasts($ds), 'status' => 'draft look – visitors see it after publish_look',
            'preview' => \Kaleta\Admin\Modules\Appearance::sitePreviewUrl($this->app, 60)];
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
