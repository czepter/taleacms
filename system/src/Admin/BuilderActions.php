<?php

declare(strict_types=1);

namespace Kaleta\Admin;

use Kaleta\Core\Language;
use Kaleta\Core\Preview;
use Kaleta\Core\Response;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\ElementClipboard;
use Kaleta\Builder\Style;

/**
 * Builder actions shared by pages (Modules\Pages) and site parts (Modules\SiteParts): editor, autosaving the draft,
 * publishing, discarding changes, versions, sections from the library and shared classes. The module supplies what the
 * build "target" is and how to save it.
 *
 * Target: ['row' => database row, 'build' => ?string, 'draft' => ?string, 'language' => content code, 'title' => string,
 *         'revisions' => ['page_id' => …] | ['part' => …], 'params' => parameters of action URLs (id, or type and language)]
 */
trait BuilderActions
{
    /** @return array<string, mixed>|null the target from the request parameters */
    abstract protected function loadBuildTarget(): ?array;

    /** Saves the work-in-progress draft (null = discard). */
    abstract protected function saveDraft(array $target, ?string $draft): void;

    abstract protected function publishTarget(array $target): void;

    /**
     * Details for the editor: url (public), preview (canvas), visible, parts (offer site part elements), headings (run the heading check),
     * back [url, text], settings (URL of the target's settings, or null), settings_text (its link label), signature (target of the signed
     * preview, e.g. "page:12" – Core\Preview), collection (the fields offered as {{placeholders}}).
     *
     * @return array<string, mixed>
     */
    abstract protected function describeTarget(array $target): array;

    /** Full-screen build editor: canvas with the real site page, tree, properties. */
    protected function actionBuilder(): Response
    {
        $target = $this->loadBuildTarget();
        if ($target === null) {
            return $this->error('Page does not exist.', 404);
        }
        $app = $this->app;
        $e = $this->describeTarget($target);
        $collections = array_map(fn (array $k): array => ['slug' => $k['slug'], 'name' => $k['name'], 'fields' => $k['fields'], 'detail' => (bool) $k['detail']], Collections::all($this->db));
        $extensions = \Kaleta\Core\Extensions::enabled($app->settings());
        $schema = Build::schema($app->auth()->isAdmin(), $target['language'], $e['parts'], $extensions);
        $components = \Kaleta\Admin\Modules\Components::listForEditor($this->db);
        foreach ($schema['elements'] as &$element) {
            if ($element['type'] === 'component') {
                $element['properties']['component'] = ['type' => 'choice', 'label' => 'Component', 'default' => '',
                    'options' => ['' => '—'] + array_column(array_map(fn (array $k): array => ['id' => (string) $k['id'], 'name' => $k['name']], $components), 'name', 'id')];
            }
            if ($element['type'] === 'collection_list') {
                // in the editor, a choice of the site's collections (the validator takes the collection slug as text)
                $element['properties']['collection'] = ['type' => 'choice', 'label' => 'Collections', 'default' => $collections[0]['slug'] ?? '',
                    'options' => ['' => '—'] + array_column($collections, 'name', 'slug')];
            }
        }
        unset($element);
        $schema = self::translateSchema($schema);
        Library::createClasses($this->db, ['card']); // card pattern in the Collection list
        $defaultLanguage = Language::defaults($app->settings());
        $data = [
            'page' => ['title' => $target['title'], 'url' => $e['url'], 'visible' => $e['visible'], 'published' => $target['build'] !== null, 'can_publish' => $app->auth()->canPublish(),
                'headings' => (bool) ($e['headings'] ?? false)], // check before publishing: the page should have one h1 and not skip levels
            'build' => Build::fromJson($target['draft'] ?? $target['build']),
            'changed' => $target['draft'] !== null && $target['draft'] !== $target['build'],
            'version' => self::computeBuildVersion($target['draft'] ?? $target['build']),
            'schema' => $schema,
            'collections' => $collections,
            'components' => $components,
            'ai' => (new \Kaleta\Core\Assistant($app->settings()))->isReady(),
            'detail_collection' => $e['collection'] ?? null,
            'library' => Library::listAll($extensions),
            'library_categories' => array_map(fn (string $k): string => t($k), Library::CATEGORIES),
            // language versions of the site for the display condition ('' = the default language, as the "language" column has it)
            'languages' => [['code' => '', 'name' => t('%s (default language)', Language::AVAILABLE[$defaultLanguage][0])],
                ...array_map(fn (string $c): array => ['code' => $c, 'name' => Language::AVAILABLE[$c][0]], Language::additional($app->settings()))],
            'classes' => $this->loadBuilderClasses(),
            'my_sections' => self::listMySections($this->db),
            'colors' => DesignSystem::load($app->settings())['colors'],
            // options for the link field: site pages (with the language prefix) and news; the editor adds anchors on the page
            'links' => [...array_map(fn (array $s): array => ['/' . ($s['language'] !== '' ? $s['language'] . '/' : '') . ((int) $s['page_id'] === $app->settings()->int('home_page') ? '' : $s['slug']), $s['title'] . ($s['visible'] ? '' : ' (' . t('hidden') . ')')],
                $this->db->all('SELECT page_id, title, slug, language, visible FROM {pages} WHERE deleted_at IS NULL ORDER BY language, sort_order, title LIMIT 300')), ['/' . \Kaleta\Core\Routes::publicPath('news', $this->db), t('News')]],
            'preview' => $e['preview'],
            'comments' => $this->commentsForEditor((string) ($e['signature'] ?? '')), // comments from shared previews (2.15); null = this target has none (components have no signed preview)
            'settings_text' => $e['settings_text'] ?? null, // label of the link to the target's settings (otherwise "Page settings")
            'back' => $e['back'],
            'urls' => array_map(fn (string $action): string => $this->url($action, $target['params']), [
                'save' => 'build_save', 'publish' => 'build_publish', 'discard' => 'build_discard', 'section' => 'build_section', 'class' => 'build_class',
                'versions' => 'build_versions', 'restore' => 'build_restore', 'ai_section' => 'build_ai_section', 'ai_text' => 'build_ai_text', 'save_section' => 'build_save_section',
                'share' => 'build_share', 'package' => 'build_package', 'paste' => 'build_paste', 'comment_resolve' => 'build_comment_resolve',
            ]) + ['delete_section' => $app->auth()->isAdmin() ? $this->url('build_delete_section', $target['params']) : null] + ['admin' => $app->url('admin.php'), 'settings' => $e['settings'],
                'component' => $app->auth()->isAdmin() ? $app->url('admin.php?module=components&action=from_element') : null,
                'section_preview' => $app->url('_section/'),
                'guide' => \Kaleta\Admin\Guide::forScreen(static::IDENT, 'builder', '', \Kaleta\Core\Language::code())],
        ];

        return Response::html($app->view->render('admin/pages/builder', ['app' => $app, 'data' => $data, 'title' => $target['title']]));
    }

    /** Autosaving the draft from the editor (JSON). Returns the sanitized build and the errors the editor shows. */
    protected function actionBuildSave(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'error' => t('Page does not exist.')], 404);
        }
        $input = json_decode((string) ($_POST['build'] ?? ''), true);
        if (!is_array($input)) {
            return Response::json(['ok' => false, 'error' => t('The build is not valid JSON.')], 400);
        }
        if (($conflict = $this->checkVersionConflict($target)) !== null) {
            return $conflict;
        }
        [$build, $errors] = Build::sanitize($input, $this->app->auth()->isAdmin(), Build::fromJson($target['draft'] ?? $target['build']));
        $json = Build::toJson($build);
        $this->saveDraft($target, $json);

        return Response::json(['ok' => true, 'build' => $build, 'errors' => $errors, 'changed' => $json !== $target['build'], 'version' => self::computeBuildVersion($json)]);
    }

    /** Publishing: the draft becomes the build; the previous published version goes to the history. */
    protected function actionBuildPublish(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null || ($target['draft'] ?? $target['build']) === null) {
            return Response::json(['ok' => false, 'error' => t('There is nothing to publish.')], 400);
        }
        if (!$this->app->auth()->canPublish()) {
            return Response::json(['ok' => false, 'error' => t('Only an editor or administrator can publish. Your changes stay saved as a draft.')], 403);
        }
        // only what the editor saved last is published – not an older draft, nor someone else's work-in-progress changes
        if (($conflict = $this->checkVersionConflict($target)) !== null) {
            return $conflict;
        }
        $this->publishTarget($target);
        ChangeLog::write($this->app, static::IDENT, 'build published', mb_substr($target['title'], 0, 80));

        return Response::json(['ok' => true]);
    }

    /**
     * Draft preview link for a colleague or a client: anyone can open it without signing in, it is valid only for this
     * target and the given number of days (1–7). It shows the draft at the moment of opening, not the state when the link
     * was created; search engines do not index it.
     */
    protected function actionBuildShare(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'error' => t('Page does not exist.')], 404);
        }
        $e = $this->describeTarget($target);
        $days = max(1, min(7, $this->request->postInt('days', 7)));
        // "Allow comments" (2.15, Core\DraftComments): the flag is signed into the key; comments exist for page drafts
        $comments = $this->request->post('comments') === '1' && \Kaleta\Core\DraftComments::parseTarget((string) ($e['signature'] ?? '')) !== null;
        $key = Preview::key($this->db, $this->app->settings(), $e['signature'], $days * 24 * 60, $comments);
        $url = str_replace('&editor=1', '', $e['preview']);
        ChangeLog::write($this->app, static::IDENT, 'preview shared', mb_substr($target['title'], 0, 80) . ' (' . $days . ' d' . ($comments ? ', comments' : '') . ')');

        return Response::json(['ok' => true, 'link' => $this->request->origin() . $url . '&preview_key=' . $key, 'valid_until' => time() + $days * 86400, 'comments' => $comments]);
    }

    /**
     * One click resolves a comment from a shared preview (2.15, Core\DraftComments); the editor's panel gets the fresh list.
     * The comment must belong to this target – a comment id alone does not reach another page.
     */
    protected function actionBuildCommentResolve(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'error' => t('Page does not exist.')], 404);
        }
        $signature = (string) ($this->describeTarget($target)['signature'] ?? '');
        $comment = \Kaleta\Core\DraftComments::find($this->db, $this->request->postInt('id'));
        if ($comment === null || $comment['target'] !== $signature) {
            return Response::json(['ok' => false, 'error' => t('The comment does not exist.')], 404);
        }
        if (\Kaleta\Core\DraftComments::resolve($this->app, $comment['id'])) {
            ChangeLog::write($this->app, static::IDENT, 'comment resolved', mb_substr($target['title'], 0, 80) . ' (#' . $comment['id'] . ')');
        }

        return Response::json(['ok' => true, 'comments' => $this->commentsForEditor($signature)]);
    }

    /**
     * Comments of a page draft for the builder's panel (unresolved first), or null for targets that have no comments (site
     * parts, collection templates, pop-ups, components).
     *
     * @return list<array<string, mixed>>|null
     */
    private function commentsForEditor(string $signature): ?array
    {
        if (\Kaleta\Core\DraftComments::parseTarget($signature) === null) {
            return null;
        }

        return array_map(fn (array $c): array => ['id' => $c['id'], 'element' => $c['element'], 'quote' => $c['quote'], 'name' => $c['name'], 'text' => $c['text'],
            'when' => format_date($c['created_at'], true), 'resolved' => $c['resolved_at'] !== null], \Kaleta\Core\DraftComments::list($this->db, $signature, false, 200));
    }

    /**
     * The selected elements packed for the system clipboard (JSON): with the shared classes and the components they use, so
     * that the builder of another Kaleta site can paste them (Builder\ElementClipboard). Changes nothing.
     */
    protected function actionBuildPackage(): Response
    {
        $elements = json_decode((string) ($_POST['elements'] ?? ''), true);
        if (!$this->request->isPost() || !is_array($elements) || !array_is_list($elements) || $elements === []) {
            return Response::json(['ok' => false, 'error' => t('Nothing to copy.')], 400);
        }

        return Response::json(['ok' => true, 'clipboard' => ElementClipboard::pack($this->db, array_slice($elements, 0, ElementClipboard::MAX_ELEMENTS), $this->request->origin())]);
    }

    /**
     * Elements pasted from the clipboard of another Kaleta site (JSON): the envelope is checked, missing classes and
     * components are created like on a page import (an administrator only, existing classes stay), the elements go through
     * the validator and come back with new ids – the editor inserts them at the selected position and saves the draft as
     * usual. Media of the other site are relinked or left out; the editor shows how many.
     */
    protected function actionBuildPaste(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null) {
            return Response::json(['ok' => false, 'error' => t('Page does not exist.')], 404);
        }
        $raw = (string) ($_POST['clipboard'] ?? '');
        $package = strlen($raw) <= ElementClipboard::MAX_LENGTH ? ElementClipboard::parse(json_decode($raw, true)) : null;
        if ($package === null) {
            return Response::json(['ok' => false, 'error' => t('The clipboard does not contain Kaleta elements.')], 400);
        }
        $admin = $this->app->auth()->isAdmin();
        [$elements, $created, $images] = ElementClipboard::import($this->app->settings(), $package, $this->request->origin(), $admin);
        [$build, $errors] = Build::sanitize(['children' => $elements], $admin);
        if ($build['children'] === []) {
            return Response::json(['ok' => false, 'error' => t('None of the elements could be inserted.') . ($errors !== [] ? ' ' . implode(' ', array_slice(array_values($errors), 0, 3)) : '')], 400);
        }
        $messages = array_values($errors);
        if ($created['classes'] > 0 || $created['components'] > 0) {
            $messages[] = t('New classes: %d, new components: %d (those the site already had were kept).', $created['classes'], $created['components']);
        } elseif (!$admin && ($package['classes'] !== [] || $package['components'] !== [])) {
            $messages[] = t('Its classes and components were not imported – only an administrator can add them.');
        }
        if ($images > 0) {
            $messages[] = str_starts_with($package['site'], 'https://')
                ? t('%d images still load from %s – replace them with files from this site’s Media.', $images, $package['site'])
                : t('%d images were left out – the media of the other site are not available here.', $images);
        }

        return Response::json(['ok' => true, 'elements' => $build['children'], 'notes' => $messages, 'classes' => $this->loadBuilderClasses(),
            'components' => \Kaleta\Admin\Modules\Components::listForEditor($this->db)]);
    }

    /** Discards the work-in-progress changes: the editor returns to the published build. */
    protected function actionBuildDiscard(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        if ($target === null || $target['build'] === null) {
            return Response::json(['ok' => false, 'error' => t('There is no published version yet – nothing to revert to.')], 400);
        }
        $this->saveDraft($target, null);

        return Response::json(['ok' => true, 'build' => Build::fromJson($target['build']), 'version' => self::computeBuildVersion($target['build'])]);
    }

    /** A section from the library as new elements (JSON) in the target's language; missing classes it uses are created. */
    protected function actionBuildSection(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $section = $target !== null ? Library::section($this->request->get('key'), $target['language']) : null;
        if ($section === null) {
            return Response::json(['ok' => false, 'error' => t('The section is not in the library.')], 404);
        }
        Library::createClasses($this->db, $section['classes']);

        return Response::json(['ok' => true, 'element' => $section['element'], 'classes' => $this->loadBuilderClasses()]);
    }

    /** @return list<array{id:int, name:string, element:array<string, mixed>}> the site's custom sections (panel Add → My sections) */
    public static function listMySections(\Kaleta\Core\Db $db): array
    {
        return array_values(array_filter(array_map(fn (array $r): ?array => is_array($p = json_decode((string) $r['element'], true)) ? ['id' => (int) $r['section_id'], 'name' => $r['name'], 'element' => $p] : null,
            $db->all('SELECT section_id, name, element FROM {sections} ORDER BY name LIMIT 200'))));
    }

    /** Saves the selected element to the custom section library (it goes through the validator like every build). */
    protected function actionBuildSaveSection(): Response
    {
        $name = mb_substr(trim($this->request->post('name')), 0, 100);
        $element = json_decode((string) ($_POST['element'] ?? ''), true);
        if (!$this->request->isPost() || $name === '' || !is_array($element)) {
            return Response::json(['ok' => false, 'error' => t('The section needs a name.')], 400);
        }
        [$build] = Build::sanitize(['children' => [$element]], $this->app->auth()->isAdmin());
        if (($build['children'][0] ?? null) === null) {
            return Response::json(['ok' => false, 'error' => t('The element could not be saved.')], 400);
        }
        $this->db->insert('sections', ['name' => $name, 'element' => (string) json_encode($build['children'][0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]);
        ChangeLog::write($this->app, static::IDENT, 'section saved to library', $name);

        return Response::json(['ok' => true, 'sections' => self::listMySections($this->db)]);
    }

    protected function actionBuildDeleteSection(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return Response::json(['ok' => false, 'error' => t('Only an administrator can remove sections.')], 403);
        }
        $this->db->delete('sections', ['section_id' => $this->request->postInt('section_id')]);

        return Response::json(['ok' => true, 'sections' => self::listMySections($this->db)]);
    }

    /** Saving or deleting a shared class (JSON). */
    protected function actionBuildClass(): Response
    {
        if (!$this->request->isPost()) {
            return Response::json(['ok' => false], 405);
        }
        $name = $this->request->post('name');
        if (!preg_match(Build::CLASS_PATTERN, $name)) {
            return Response::json(['ok' => false, 'error' => t('Class name: lowercase letters without accents, digits and hyphens (e.g. card, card--highlighted).')], 400);
        }
        if ($this->request->post('usage') === '1') {
            return Response::json(['ok' => true, 'usage' => $this->findClassUsages($name)]);
        }
        if (!$this->app->auth()->isAdmin()) {
            // a shared class changes the look of all pages (through the draft look) – so only the administrator edits,
            // renames and deletes it
            return Response::json(['ok' => false, 'error' => t('Only an administrator edits a shared class – a change applies to the whole website at once. Set the look of a single element in its style.')], 403);
        }
        if (($new = $this->request->post('new_name')) !== '') {
            // renaming: the class row and all builds that use it (pages, site parts, collection item templates, components, my sections)
            if (!preg_match(Build::CLASS_PATTERN, $new) || $this->db->value('SELECT 1 FROM {classes} WHERE name = ?', [$new]) !== null) {
                return Response::json(['ok' => false, 'error' => t('The new name must be unused and written in lowercase without accents (e.g. card-large).')], 400);
            }
            $this->db->update('classes', ['name' => $new, 'updated_at' => date('Y-m-d H:i:s')], ['name' => $name]);
            \Kaleta\Core\Look::renameClass($this->app->settings(), $name, $new);
            foreach (self::BUILD_SOURCES as $table => [$key, $columns]) {
                foreach ($this->db->all('SELECT ' . $key . ', ' . implode(', ', $columns) . ' FROM {' . $table . '} WHERE ' . implode(' OR ', array_map(fn (string $s): string => $s . ' LIKE ?', $columns)), array_fill(0, count($columns), '%"' . $name . '"%')) as $r) {
                    $change = [];
                    foreach ($columns as $s) {
                        if ($r[$s] !== null && str_contains($r[$s], '"' . $name . '"')) {
                            $change[$s] = self::renameClass((string) $r[$s], $name, $new);
                        }
                    }
                    $this->db->update($table, $change, [$key => $r[$key]]);
                }
            }
            \Kaleta\Front\Cache::clear();

            return Response::json(['ok' => true, 'classes' => $this->loadBuilderClasses(), 'name' => $new]);
        }
        // the class goes to the draft look (Core\Look): the builder shows it, visitors see it once the look is published
        if ($this->request->post('delete') === '1') {
            \Kaleta\Core\Look::setClass($this->app->settings(), $name, null);
        } else {
            $errors = [];
            $discarded = [];
            $style = Style::sanitize(json_decode((string) ($_POST['style'] ?? ''), true), $name, $errors);
            $css = Style::customCss($this->request->post('css'), $discarded);
            \Kaleta\Core\Look::setClass($this->app->settings(), $name, ['style' => $style, 'css' => $css]);
            if ($errors !== [] || $discarded !== []) {
                return Response::json(['ok' => true, 'draft' => true, 'classes' => $this->loadBuilderClasses(), 'errors' => $errors + array_map(fn (string $d): string => t('Declaration not allowed: %s', $d), $discarded)]);
            }
        }

        return Response::json(['ok' => true, 'draft' => true, 'classes' => $this->loadBuilderClasses()]);
    }

    /** Tables with builds: table => [key, columns with the build JSON]. */
    private const array BUILD_SOURCES = [
        'pages' => ['page_id', ['build', 'build_draft']], 'site_parts' => ['type', ['build', 'build_draft']], 'collections' => ['collection_id', ['build', 'build_draft']],
        'components' => ['component_id', ['build', 'build_draft']], 'sections' => ['section_id', ['element']],
    ];

    /** Renames the class in the "classes" array of all build elements (JSON) – other occurrences of the text stay. */
    private static function renameClass(string $json, string $old, string $new): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $json;
        }
        $walk = function (array $x) use (&$walk, $old, $new): array {
            if (isset($x['classes']) && is_array($x['classes'])) {
                $x['classes'] = array_map(fn (mixed $t): mixed => $t === $old ? $new : $t, $x['classes']);
            }
            foreach ($x as $k => $v) {
                if (is_array($v)) {
                    $x[$k] = $walk($v);
                }
            }

            return $x;
        };

        return (string) json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> where the class is used (names of pages, site parts, collections, components, my sections) */
    private function findClassUsages(string $name): array
    {
        $pattern = '%"classes":[%"' . addcslashes($name, '%_\\') . '"%';
        $whereParts = [];
        foreach ($this->db->all('SELECT title FROM {pages} WHERE deleted_at IS NULL AND (build LIKE ? OR build_draft LIKE ?)', [$pattern, $pattern]) as $r) {
            $whereParts[] = t('page') . ' ' . $r['title'];
        }
        foreach ($this->db->all('SELECT type, name FROM {site_parts} WHERE build LIKE ? OR build_draft LIKE ?', [$pattern, $pattern]) as $r) {
            $whereParts[] = t('site part') . ' ' . ($r['name'] !== '' ? $r['name'] : $r['type']);
        }
        foreach ([['collections', 'name', 'collection'], ['components', 'name', 'component']] as [$table, $column, $kind]) {
            foreach ($this->db->all('SELECT ' . $column . ' AS n FROM {' . $table . '} WHERE build LIKE ? OR build_draft LIKE ?', [$pattern, $pattern]) as $r) {
                $whereParts[] = t($kind) . ' ' . $r['n'];
            }
        }

        return array_values(array_unique($whereParts));
    }

    /** Published versions (JSON for the Versions dialog). */
    protected function actionBuildVersions(): Response
    {
        $target = $this->loadBuildTarget();

        // date in the admin format (format_date()), not the browser's – in English it otherwise came out as the American 9/25/2026, 9:43:45 AM
        return Response::json(['revisions' => $target === null ? [] : array_map(fn (array $r): array => ['revision_id' => (int) $r['revision_id'], 'when' => format_date($r['created_at'], true), 'who' => $r['user_name']], Publisher::listAll($this->db, $target['revisions']))]);
    }

    /** An older version is loaded into the draft; it is published only with the Publish button. */
    protected function actionBuildRestore(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $build = $target !== null ? Publisher::load($this->db, $target['revisions'], $this->request->postInt('revision_id')) : null;
        if ($build === null) {
            return Response::json(['ok' => false, 'error' => t('The version does not exist.')], 404);
        }
        $this->saveDraft($target, $build);

        return Response::json(['ok' => true, 'build' => Build::fromJson($build), 'version' => self::computeBuildVersion($build)]);
    }

    /** Hash of the content the editor last saw on the server (the draft, otherwise the published build). */
    private static function computeBuildVersion(?string $json): string
    {
        return substr(md5((string) $json), 0, 16);
    }

    /**
     * Protection against overwriting someone else's changes: the editor sends the hash of the version it is based on. When
     * the draft has changed in the meantime (another editor, Claude via MCP, a second tab), it is rejected and the editor
     * offers to load the newer one, or to overwrite.
     */
    private function checkVersionConflict(array $target): ?Response
    {
        $version = $this->request->post('version');
        $current = self::computeBuildVersion($target['draft'] ?? $target['build']);
        if ($version === '' || $this->request->post('overwrite') === '1' || hash_equals($current, $version)) {
            return null;
        }

        return Response::json(['ok' => false, 'conflict' => true, 'version' => $current, 'build' => Build::fromJson($target['draft'] ?? $target['build']),
            'error' => t('Someone else has edited this page in the meantime (or you opened it in another window).')], 409);
    }

    /**
     * Schema labels (names of elements, fields, style properties and their options) into the admin language. The default
     * content of elements is not translated – it is in the page language (Build::schema).
     */
    private static function translateSchema(array $schema): array
    {
        $field = function (array $properties) use (&$field): array {
            foreach ($properties as $key => $d) {
                $properties[$key]['label'] = t((string) ($d['label'] ?? ''));
                if (isset($d['options'])) {
                    $properties[$key]['options'] = array_map(fn (string $m): string => t($m), $d['options']);
                }
                if (isset($d['fields'])) {
                    $properties[$key]['fields'] = $field($d['fields']);
                }
            }

            return $properties;
        };
        foreach ($schema['elements'] as $i => $p) {
            $schema['elements'][$i] = ['name' => t($p['name']), 'description' => t($p['description']), 'group' => t($p['group']), 'properties' => $field($p['properties'])] + $p;
        }
        $schema['style'] = $field($schema['style']);
        $schema['style_groups'] = array_map(fn (string $s): string => t($s), $schema['style_groups']);

        return $schema;
    }

    /** AI assistant: a new section from a description (JSON with elements to insert). */
    protected function actionBuildAiSection(): Response
    {
        $target = $this->request->isPost() ? $this->loadBuildTarget() : null;
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if ($target === null || !$assistant->isReady()) {
            return Response::json(['ok' => false, 'error' => t('The writing assistant is not enabled (Features).')], 400);
        }
        try {
            $html = \Kaleta\Core\Language::runWith($target['language'], fn (): string => $assistant->suggestSection($this->request->post('prompt'), $target['language'], $target['title']));
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => t($e->getMessage())], 502);
        }
        ['build' => $build, 'notes' => $messages] = \Kaleta\Builder\HtmlConverter::saveToSite($this->db, $html, false);
        [$clean] = Build::sanitize($build, $this->app->auth()->isAdmin());
        if ($clean['children'] === []) {
            return Response::json(['ok' => false, 'error' => t('The assistant did not return a usable section. Try refining the description.')], 502);
        }

        return Response::json(['ok' => true, 'elements' => $clean['children'], 'classes' => $this->loadBuilderClasses(), 'notes' => $messages]);
    }

    /** AI assistant: rewrite of an element's text (shorter, longer, more formal…). Saves nothing – the editor inserts the text as a regular change. */
    protected function actionBuildAiText(): Response
    {
        $assistant = new \Kaleta\Core\Assistant($this->app->settings());
        if (!$this->request->isPost() || !$assistant->isReady()) {
            return Response::json(['ok' => false, 'error' => t('The writing assistant is not enabled (Features).')], 400);
        }
        try {
            $text = $assistant->rewrite((string) ($_POST['text'] ?? ''), $this->request->post('instruction'), $this->request->post('html') === '1');
        } catch (\RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => t($e->getMessage())], 502);
        }

        return Response::json(['ok' => true, 'text' => $text]);
    }

    /** @return array<string, array{style: array<string, mixed>|\stdClass, css: string}> */
    protected function loadBuilderClasses(): array
    {
        // with the draft look: the builder works on what will be published
        return array_map(fn (array $c): array => ['style' => $c['style'] ?: new \stdClass(), 'css' => $c['css']], \Kaleta\Core\Look::classes($this->db, $this->app->settings(), true));
    }

    protected function contentLanguage(string $column): string
    {
        return Language::ofContent($this->app->settings(), $column);
    }
}
