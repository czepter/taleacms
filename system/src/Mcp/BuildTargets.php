<?php

declare(strict_types=1);

namespace Talea\Mcp;

use Talea\Core\Language;
use Talea\Builder\SiteParts;
use Talea\Builder\Collections;
use Talea\Builder\Publisher;
use Talea\Builder\Build;

/**
 * The build a builder tool works on (a page, a site part, an item template, a pop-up, a component or a form): loading it,
 * saving and publishing its draft, and the addresses that show it. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait BuildTargets
{
    /**
     * Build target: a page (id; without id and with $create a new hidden page named from "title") or a site part
     * (part = type, language), which only the administrator can change. A part that does not exist yet is created with a
     * draft from the layout.
     *
     * @return array{kind: string, row: array<string, mixed>, build: ?string, draft: ?string, language: string}
     */
    private function loadBuildTarget(array $a, bool $create = false): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        if (isset($a['popup']) && (int) $a['popup'] > 0) {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can change pop-up windows.');
            }
            $row = \Talea\Builder\Popups::byId($db, (int) $a['popup']) ?? throw new \InvalidArgumentException('The pop-up window does not exist. Use list_popups.');

            return ['kind' => 'popup', 'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => Language::ofContent($siteSettings, ''),
                'revision' => ['part' => 'popup:' . $row['popup_id']]];
        }
        if (isset($a['component']) && (int) $a['component'] > 0) {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Components can be changed only by an administrator.');
            }
            $row = \Talea\Builder\Components::byId($db, (int) $a['component']) ?? throw new \InvalidArgumentException('The component does not exist. Use list_components.');

            return ['kind' => 'component', 'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => Language::ofContent($siteSettings, ''),
                'revision' => ['part' => 'component:' . (int) $row['component_id']]];
        }
        if (isset($a['form']) && (int) $a['form'] > 0) {
            if (!$auth->hasModule('enquiries')) {
                throw new \DomainException('Forms can be changed only by someone with access to Enquiries.');
            }
            $row = \Talea\Builder\FormRecords::byId($db, (int) $a['form']) ?? throw new \InvalidArgumentException('The form does not exist. Use list_forms.');

            return ['kind' => 'form', 'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => Language::ofContent($siteSettings, ''),
                'revision' => ['part' => 'form:' . (int) $row['form_id']]];
        }
        if (isset($a['collection']) && $a['collection'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can change the item page template of a collection.');
            }
            $row = \Talea\Builder\Collections::bySlug($db, (string) $a['collection']) ?? throw new \InvalidArgumentException('The collection does not exist. Use list_collections.');
            $language = (string) ($a['language'] ?? '') === Language::defaults($siteSettings) ? '' : (string) ($a['language'] ?? '');
            if ($language !== '' && !in_array($language, Language::additional($siteSettings), true)) {
                throw new \InvalidArgumentException('The language version “' . $language . '” is not switched on (Extensions → Language versions, languages in Settings).');
            }
            $row = \Talea\Builder\Collections::inLanguage($db, $row, $language);
            if ($row['build'] === null && $row['build_draft'] === null) {
                // the template the builder would show until someone edits it (another language starts with a copy of the default)
                $row['build_draft'] = \Talea\Builder\Collections::initialTemplateDraft($db, $row);
            }

            return ['kind' => 'collection', 'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => Language::ofContent($siteSettings, $language),
                'revision' => ['part' => \Talea\Builder\Collections::templateKey($row)]];
        }
        if (isset($a['part']) && $a['part'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Only the site administrator can change site parts (header, footer, wrappers).');
            }
            $type = self::PART_NAMES[(string) $a['part']] ?? (string) $a['part'];
            if (!isset(SiteParts::TYPES[$type])) {
                throw new \InvalidArgumentException('Unknown site part. Parts: ' . implode(', ', array_keys(self::PART_NAMES)) . '.');
            }
            $language = in_array($a['language'] ?? '', Language::additional($siteSettings), true) ? (string) $a['language'] : '';
            $variant = (string) ($a['variant'] ?? '');
            if ($variant !== '') {
                $row = in_array($type, SiteParts::WITH_VARIANTS, true) ? SiteParts::row($db, $type, $language, $variant) : null;
                if ($row === null) {
                    throw new \InvalidArgumentException('The variant does not exist. list_site_parts lists header and footer variants, save_part_variant creates one.');
                }
            } else {
                // a part that does not exist yet: the draft the builder would start with – the row is created only on write
                // (reading changes nothing)
                $row = SiteParts::row($db, $type, $language) ?? ['type' => $type, 'language' => $language, 'variant' => '', 'name' => '', 'pages' => null, 'build' => null,
                    'build_draft' => SiteParts::initialDraft($db, $type, $language, Language::ofContent($siteSettings, $language)), 'updated_at' => null, 'is_new' => true];
            }

            return ['kind' => 'part', 'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => Language::ofContent($siteSettings, $language),
                'revision' => ['part' => SiteParts::versionKey($type, $language, $variant)]];
        }
        if (!$auth->hasModule('pages')) {
            throw new \DomainException('Only editors and administrators can change pages.');
        }
        if (!isset($a['id']) && $create) {
            $a['id'] = $this->savePage(null, ['title' => (string) ($a['title'] ?? '')])['id'];
        }
        $row = $db->one('SELECT * FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [(int) ($a['id'] ?? 0)]) ?? throw new \InvalidArgumentException('The page does not exist. Use list_pages.');

        return ['kind' => 'page', 'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => Language::ofContent($siteSettings, $row['language']),
            'revision' => ['page_id' => (int) $row['page_id']]];
    }

    /** The target's draft build, otherwise the published one; a text page as a build from its text. */
    private function targetBuild(array $target): array
    {
        return Build::fromJson($target['draft'] ?? $target['build'])
            ?? ($target['kind'] === 'page' ? Build::fromText($target['row']['title'], (string) $target['row']['text']) : ['v' => Build::VERSION, 'children' => []]);
    }

    /** @return array<string, mixed> */
    private function describeTarget(array $target): array
    {
        return match ($target['kind']) {
            'page' => ['id' => $this->pid('pages', $target['row']['page_id']), 'title' => $target['row']['title']],
            'collection' => ['collection' => $target['row']['slug'], 'title' => 'Detail: ' . $target['row']['name'], 'item_pages' => (bool) $target['row']['detail']]
                + ($target['row']['template_language'] !== '' ? ['language' => $target['row']['template_language']] : []),
            'popup' => ['popup' => $this->pid('popups', $target['row']['popup_id']), 'title' => 'Pop-up: ' . $target['row']['name'], 'active' => (bool) $target['row']['active']],
            'component' => ['component' => $this->pid('components', $target['row']['component_id']), 'title' => 'Component: ' . $target['row']['name']],
            'form' => ['form' => $this->pid('forms', $target['row']['form_id']), 'title' => 'Form: ' . $target['row']['name']],
            default => ['part' => self::partName($target['row']['type']), 'language' => $target['row']['language'], 'title' => SiteParts::TYPES[$target['row']['type']][0]]
                + ($target['row']['variant'] !== '' ? ['variant' => $target['row']['variant']] : []),
        };
    }

    /**
     * A build tool asked to publish: only with the publishing permission (an editor or an administrator, and a connection
     * with full access). Checked before anything is saved, so nothing half-done stays behind.
     *
     * @param array<string, mixed> $a
     */
    private function mayPublish(array $a): void
    {
        if (!empty($a['publish']) && !$this->app->auth()->canPublish()) {
            throw new \DomainException('Publishing needs an editor or an administrator and a connection with full access. Save the build without publish – it stays a draft for the user to publish.');
        }
    }

    private function publishTarget(array $target): void
    {
        $db = $this->app->db();
        if ($target['kind'] === 'page') {
            Publisher::page($this->app, (array) $db->one('SELECT * FROM {pages} WHERE page_id = ?', [$target['row']['page_id']]));
        } elseif ($target['kind'] === 'collection') {
            $row = \Talea\Builder\Collections::inLanguage($db, (array) \Talea\Builder\Collections::byId($db, (int) $target['row']['collection_id']), $target['row']['template_language']);
            // a default template nobody saved is published too (otherwise there would be nothing to publish)
            $row['build_draft'] ??= $target['draft'];
            Publisher::collection($this->app, $row);
        } elseif ($target['kind'] === 'popup') {
            Publisher::popup($this->app, (array) \Talea\Builder\Popups::byId($db, $target['row']['popup_id']));
        } elseif ($target['kind'] === 'component') {
            Publisher::component($this->app, (array) \Talea\Builder\Components::byId($db, (int) $target['row']['component_id']));
        } elseif ($target['kind'] === 'form') {
            Publisher::form($this->app, (array) \Talea\Builder\FormRecords::byId($db, (int) $target['row']['form_id']));
        } else {
            $this->createSitePart($target['row']);
            Publisher::part($this->app, (array) SiteParts::row($db, $target['row']['type'], $target['row']['language'], (string) $target['row']['variant']));
        }
    }

    /**
     * A site part that loadBuildTarget() only offered (not in the database yet) is created with the default draft before
     * the first write.
     */
    private function createSitePart(array $row): void
    {
        if (!empty($row['is_new']) && SiteParts::row($this->app->db(), $row['type'], $row['language']) === null) {
            $this->app->db()->insert('site_parts', ['type' => $row['type'], 'language' => $row['language'], 'build_draft' => $row['build_draft'], 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** Sanitizes and saves the draft (and publishes it if asked); returns what the model needs for further work. */
    private function saveBuild(array $target, array $input, bool $publish): array
    {
        $this->mayPublish(['publish' => $publish]);
        $db = $this->app->db();
        [$build, $errors] = Build::sanitize($input, $this->app->auth()->canWriteCode(), Build::fromJson($target['draft'] ?? $target['build']));
        if ($target['kind'] === 'form') {
            $build = \Talea\Builder\FormRecords::definitionBuild($build); // a form's build is its one form element
        }
        $r = $target['row'];
        if ($target['kind'] === 'page') {
            $db->update('pages', ['build_draft' => Build::toJson($build)], ['page_id' => $r['page_id']]);
        } elseif ($target['kind'] === 'collection') {
            \Talea\Builder\Collections::writeTemplate($db, $r, ['build_draft' => Build::toJson($build)]);
            $target['draft'] = Build::toJson($build);
        } elseif ($target['kind'] === 'popup') {
            $db->update('popups', ['build_draft' => Build::toJson($build)], ['popup_id' => $r['popup_id']]);
        } elseif ($target['kind'] === 'component') {
            if (\Talea\Builder\Components::isShared((array) $r) && ($refusal = \Talea\Builder\Components::formRefusal(Build::toJson($build))) !== null) {
                throw new \DomainException($refusal);
            }
            $db->update('components', ['build_draft' => Build::toJson($build)], ['component_id' => $r['component_id']]);
        } elseif ($target['kind'] === 'form') {
            $db->update('forms', ['build_draft' => Build::toJson($build), 'updated_at' => date('Y-m-d H:i:s')], ['form_id' => $r['form_id']]);
            \Talea\Builder\FormRecords::forget();
        } else {
            $this->createSitePart($r);
            $db->update('site_parts', ['build_draft' => Build::toJson($build)], ['type' => $r['type'], 'language' => $r['language'], 'variant' => $r['variant']]);
        }
        if ($publish) {
            $this->publishTarget($target);
        }
        $params = match ($target['kind']) {
            'page' => 'module=pages&action=builder&id=' . $this->pid('pages', $r['page_id']),
            'collection' => 'module=collections&action=builder&id=' . $this->pid('collections', $r['collection_id']) . ($r['template_language'] !== '' ? '&language=' . $r['template_language'] : ''),
            'popup' => 'module=popups&action=builder&id=' . $this->pid('popups', $r['popup_id']),
            'component' => 'module=components&action=builder&id=' . $this->pid('components', $r['component_id']),
            'form' => 'module=forms&action=builder&id=' . $this->pid('forms', $r['form_id']),
            default => 'module=parts&action=builder&type=' . $r['type'] . '&language=' . $r['language'],
        };

        return $this->describeTarget($target) + ['status' => $publish ? 'published' : 'draft – shown on the site after publishing', 'elements' => $this->countElements($build['children']),
            'errors' => $errors, 'preview' => $publish ? $this->targetUrl($target) : $this->targetPreviewUrl($target, 60),
            'builder_url' => $this->app->request->origin() . $this->app->url('admin.php?' . $params)] + $this->checkTarget($target, $build);
    }

    /**
     * Check before publishing as in the builder (buttons without a link, images without alt text, the page's heading
     * outline, sample content still in place); nothing when there are no findings.
     */
    private function checkTarget(array $target, array $build): array
    {
        $page = $target['kind'] === 'page';
        $components = $page ? \Talea\Builder\Components::builds($this->app->db()) : [];
        $findings = [...\Talea\Builder\Check::builds($build, $page, components: $components), ...\Talea\Builder\SampleContent::findings($build)];

        return $findings === [] ? [] : ['check' => $findings];
    }

    /**
     * Signed link to the target's draft (valid only for this target and for a limited time). With $comments the key allows
     * comments on the draft (2.15, Core\DraftComments) – page drafts only, the other targets have no comment widget.
     */
    private function targetPreviewUrl(array $target, int $minutes, bool $comments = false): string
    {
        $r = $target['row'];
        if ($target['kind'] === 'component' || $target['kind'] === 'form') {
            return $this->targetUrl($target) . ($target['kind'] === 'form' ? '?build=draft' : ''); // the component or form canvas: for a signed-in user only
        }
        $signature = match ($target['kind']) {
            'page' => 'page:' . (int) $r['page_id'],
            'collection' => \Talea\Builder\Collections::templateKey($r),
            'popup' => 'popup:' . (int) $r['popup_id'],
            default => 'part:' . $r['type'] . ':' . $r['language'] . ($r['variant'] !== '' ? ':' . $r['variant'] : ''), // a link to the header would not show the variant's draft
        };
        $key = \Talea\Core\Preview::key($this->app->db(), $this->app->settings(), $signature, $minutes, $comments && $target['kind'] === 'page');
        if ($target['kind'] === 'popup') {
            return $this->targetUrl($target) . '?build=draft&preview_key=' . $key;
        }

        $variant = $target['kind'] === 'part' && $r['variant'] !== '' ? 'variant=' . rawurlencode($r['variant']) . '&' : '';

        return $this->targetUrl($target) . '?' . ($target['kind'] === 'part' ? 'part=' . $r['type'] . '&' : '') . $variant . 'build=draft&preview_key=' . $key;
    }

    /**
     * Public URL where the target is visible (for a site part the home page, news item detail, listing, 404; for a
     * collection the first item).
     */
    private function targetUrl(array $target): string
    {
        $r = $target['row'];
        if ($target['kind'] === 'popup') {
            return $this->app->request->origin() . $this->app->url('_popup/' . $this->pid('popups', $r['popup_id'])); // the popup draft over an empty site page
        }
        if ($target['kind'] === 'component') {
            return $this->app->request->origin() . $this->app->url('_component/' . $this->pid('components', $r['component_id']));
        }
        if ($target['kind'] === 'form') {
            return $this->app->request->origin() . $this->app->url('_form/' . $this->pid('forms', $r['form_id']));
        }
        if ($target['kind'] === 'collection') {
            $language = $r['template_language'];
            $item = $this->app->db()->value('SELECT slug FROM {collection_items} WHERE collection_id = ? AND language = ? AND deleted_at IS NULL ORDER BY visible DESC, sort_order, item_id LIMIT 1', [$r['collection_id'], $language]);

            return $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . $r['slug'] . '/' . ($item ?? '_sample'));
        }
        if ($target['kind'] === 'page') {
            $home = $this->app->settings()->int('home_page') === (int) $r['page_id'];
            $path = $home ? '' : $r['slug'];
        } elseif ($r['variant'] !== '') {
            // the variant is shown on the first page it applies to
            $pageId = (int) ((json_decode((string) $r['pages'], true) ?: [])[0] ?? 0);
            $path = (string) $this->app->db()->value('SELECT slug FROM {pages} WHERE page_id = ?', [$pageId]);
        } else {
            $path = match ($r['type']) {
                'news_item', 'list' => 'news',
                'not_found' => 'this-page-does-not-exist',
                default => '',
            };
        }

        return $this->app->request->origin() . $this->app->url(($r['language'] !== '' ? $r['language'] . '/' : '') . $path);
    }
}
