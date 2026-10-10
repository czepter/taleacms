<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Admin\BuilderActions;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Builder\SiteParts as CastiWebu;
use Kaleta\Builder\Publisher;

/**
 * Site parts in the builder: header, footer and the wrappers of the news item detail, the list and the 404 page. Without a
 * published build the layout draws the part; "Revert to layout" turns the build off (it stays in the versions).
 */
final class SiteParts extends Module
{
    use BuilderActions {
        actionBuilder as protected openBuilder;
    }

    public const string IDENT = 'parts';
    public const string NAME = 'Site parts';
    public const string GROUP = 'Appearance';
    public const string ICON = 'parts';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $siteSettings = $this->app->settings();
        $languages = array_merge([''], Language::additional($siteSettings));
        $rows = [];
        $variants = [];
        foreach ($this->db->all('SELECT type, language, variant, name, pages, build IS NOT NULL AS published, build_draft IS NOT NULL AND (build IS NULL OR build_draft <> build) AS changed, updated_at FROM {site_parts} ORDER BY name') as $r) {
            if ($r['variant'] === '') {
                $rows[$r['type'] . ':' . $r['language']] = $r;
            } else {
                $variants[$r['type'] . ':' . $r['language']][] = $r;
            }
        }

        return $this->view('list', 'Site parts', [
            'types' => CastiWebu::TYPES, 'languages' => $languages, 'rows' => $rows, 'variants' => $variants,
            'pageNames' => $this->db->pairs('SELECT page_id, title FROM {pages} WHERE deleted_at IS NULL ORDER BY sort_order, title'),
            'languageNames' => array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[Language::ofContent($siteSettings, $j)][0], $languages)),
        ]);
    }

    /** Editor; a part that does not exist yet is created with a draft based on what the layout has drawn so far. */
    protected function actionBuilder(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type !== null && $variant === '' && CastiWebu::row($this->db, $type, $language) === null) {
            $this->db->insert('site_parts', ['type' => $type, 'language' => $language, 'build_draft' => CastiWebu::initialDraft($this->db, $type, $language, $this->contentLanguage($language)), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        return $this->openBuilder();
    }

    /** The part reverts to the layout (a variant is deleted): the published build goes to the versions, the site draws the part from the layout. */
    protected function actionTemplate(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        $row = $this->request->isPost() && $type !== null ? CastiWebu::row($this->db, $type, $language, $variant) : null;
        if ($row !== null) {
            Publisher::version($this->app, ['part' => CastiWebu::versionKey($type, $language, $variant)], $row['build'], null, $row['updated_at']);
            $this->db->delete('site_parts', ['type' => $type, 'language' => $language, 'variant' => $variant]);
            \Kaleta\Front\Cache::clear();
        }

        return $this->back($variant !== '' ? 'The variant was deleted – the selected pages have the default version again.' : 'The site part is back to its default design. The previous design is in the history when you open it in the builder again.');
    }

    /** Form of a header or footer variant: name and the pages it applies to. */
    /** Ready-made templates of the part (PartTemplates) to start from. */
    protected function actionTemplates(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type === null) {
            return $this->error('The site part does not exist.', 404);
        }

        return $this->view('templates', t('Templates: %s', t(CastiWebu::TYPES[$type][0])), [
            'type' => $type, 'language' => $language, 'variant' => $variant,
            'templates' => \Kaleta\Builder\PartTemplates::forType($type, \Kaleta\Core\Extensions::enabled($this->app->settings())),
        ]);
    }

    /** A template into the part's draft – the builder opens with it; the site changes only after publishing. */
    protected function actionApplyTemplate(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if (!$this->request->isPost() || $type === null
            || !CastiWebu::applyTemplate($this->db, $type, $language, $variant, $this->request->post('template'), $this->contentLanguage($language), \Kaleta\Core\Extensions::enabled($this->app->settings()))) {
            return $this->back('The template could not be used.', '', [], 'error');
        }
        $this->app->session->flash('ok', 'The template is in the draft – adjust it and publish; until then visitors see the published version.');

        return Response::redirect($this->url('builder', ['type' => $type, 'language' => $language] + ($variant !== '' ? ['variant' => $variant] : [])));
    }

    protected function actionVariant(): Response
    {
        [$type, $language, $variant] = $this->readPartParams();
        if ($type === null || !in_array($type, CastiWebu::WITH_VARIANTS, true)) {
            return $this->error('Only the header and footer can have variants.', 404);
        }
        $row = $variant !== '' ? CastiWebu::row($this->db, $type, $language, $variant) : null;

        return $this->view('variant', t('Variant: %s', t(CastiWebu::TYPES[$type][0])), [
            'type' => $type, 'language' => $language, 'variant' => $row['variant'] ?? '', 'name' => $row['name'] ?? '',
            'selected' => array_map('intval', json_decode((string) ($row['pages'] ?? '[]'), true) ?: []),
            'pages' => $this->db->all('SELECT page_id, title FROM {pages} WHERE language = ? AND deleted_at IS NULL ORDER BY sort_order, title', [$language]),
        ]);
    }

    /** Saving a variant; a new one starts as a copy of the default form (or the form from the layout) as a draft. */
    protected function actionSaveVariant(): Response
    {
        [$type, $language] = $this->readPartParams();
        if (!$this->request->isPost() || $type === null || !in_array($type, CastiWebu::WITH_VARIANTS, true)) {
            return $this->back();
        }
        $name = mb_substr(trim($this->request->post('name')), 0, 100);
        if ($name === '') {
            return $this->back('The variant needs a name.', 'variant', ['type' => $type, 'language' => $language], 'error');
        }
        $variant = CastiWebu::saveVariant($this->db, $type, $language, $this->request->post('variant'), $name, array_map('intval', $this->request->postList('pages')), $this->contentLanguage($language));
        \Kaleta\Front\Cache::clear();

        return \Kaleta\Core\Response::redirect($this->url('builder', ['type' => $type, 'language' => $language, 'variant' => $variant]));
    }

    protected function loadBuildTarget(): ?array
    {
        [$type, $language, $variant] = $this->readPartParams();
        $row = $type === null ? null : CastiWebu::row($this->db, $type, $language, $variant);

        return $row === null ? null : [
            'row' => $row, 'build' => $row['build'], 'draft' => $row['build_draft'], 'language' => $this->contentLanguage($language),
            'title' => t(CastiWebu::TYPES[$type][0]) . ($variant !== '' ? ' – ' . $row['name'] : ''),
            'revisions' => ['part' => CastiWebu::versionKey($type, $language, $variant)], 'params' => ['type' => $type, 'language' => $language] + ($variant !== '' ? ['variant' => $variant] : []),
        ];
    }

    protected function saveDraft(array $target, ?string $draft): void
    {
        $this->db->update('site_parts', ['build_draft' => $draft], ['type' => $target['row']['type'], 'language' => $target['row']['language'], 'variant' => $target['row']['variant']]);
    }

    protected function publishTarget(array $target): void
    {
        Publisher::part($this->app, $target['row']);
    }

    protected function describeTarget(array $target): array
    {
        $type = $target['row']['type'];
        $language = $target['row']['language'];
        // preview: a page on which the part appears (the news item wrapper on the newest news item, 404 on a non-existent URL)
        // a variant is shown on the first page it applies to
        $page = $target['row']['variant'] !== '' ? (json_decode((string) $target['row']['pages'], true) ?: [])[0] ?? null : null;
        $path = $page !== null ? (string) $this->db->value('SELECT slug FROM {pages} WHERE page_id = ?', [(int) $page]) : match ($type) {
            'news_item' => ($seo = $this->db->value('SELECT slug FROM {news} WHERE visible = 1 AND deleted_at IS NULL AND published_at <= NOW() AND language = ? ORDER BY published_at DESC LIMIT 1', [$language])) !== null ? 'news/' . $seo : 'news',
            'list' => 'news',
            'not_found' => 'this-page-does-not-exist',
            default => '',
        };
        $url = $this->app->url(($language !== '' ? $language . '/' : '') . $path);

        return [
            'url' => $url, 'preview' => $url . '?part=' . $type . '&build=draft&editor=1' . ($target['row']['variant'] !== '' ? '&variant=' . rawurlencode($target['row']['variant']) : ''),
            'visible' => true, 'parts' => true,
            'back' => ['url' => $this->url(), 'text' => t('Site parts')], 'settings' => null, 'signature' => 'part:' . $type . ':' . $language . ($target['row']['variant'] !== '' ? ':' . $target['row']['variant'] : ''),
        ];
    }

    /** @return array{0: ?string, 1: string, 2: string} type, language and variant of the part from the request URL */
    private function readPartParams(): array
    {
        $type = $this->request->get('type');
        $language = $this->request->get('language');
        $variant = $this->request->get('variant');

        return [isset(CastiWebu::TYPES[$type]) ? $type : null, in_array($language, Language::additional($this->app->settings()), true) ? $language : '',
            in_array($type, CastiWebu::WITH_VARIANTS, true) && preg_match(CastiWebu::VARIANT_PATTERN, $variant) ? $variant : ''];
    }
}
