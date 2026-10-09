<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Db;
use Kaleta\Core\Language;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;

/**
 * News categories (table ka_kategorie). A flat list – a company blog does not need a category tree.
 * The category also determines the language version of a news item.
 */
final class Categories extends Module
{
    public const string IDENT = 'categories';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Categories';
    public const string GROUP = 'Content';
    public const string ICON = 'rubriky';
    public const string PARENT = 'news';

    /**
     * Categories sorted by order and name, with the number of news items.
     *
     * @return list<array<string, mixed>>
     */
    public static function listAll(Db $db, ?string $language = null): array
    {
        $whereParts = $language !== null && preg_match('/^([a-z]{2})?$/', $language) ? " WHERE t.language = '{$language}'" : '';

        return $db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM {news} c WHERE c.category_id = t.category_id AND c.deleted_at IS NULL) AS pocet_clanku
             FROM {categories} t' . $whereParts . ' ORDER BY t.weight DESC, t.name',
        );
    }

    /**
     * News needs at least one category. When there is none (news enabled only after installation), it creates the default
     * "Aktuality" in the site language, as the installation does. Returns the id of the new category, or null when one exists.
     */
    public static function createDefault(Db $db, Settings $s): ?int
    {
        if ($db->value('SELECT 1 FROM {categories} LIMIT 1') !== null) {
            return null;
        }
        $name = Language::runWith(Language::defaults($s), fn (): string => t('Aktuality'));

        return $db->insert('categories', ['name' => $name, 'slug' => slugify($name), 'description' => '']);
    }

    protected function actionList(): Response
    {
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();

        return $this->view('list', 'Categories', ['category' => self::listAll($this->db, $column), 'siteLanguages' => $siteLanguages, 'language' => $language]);
    }

    protected function actionNew(): Response
    {
        return $this->form(['idt' => 0, 'nazev' => '', 'slug' => '', 'popis' => '', 'weight' => 100]);
    }

    protected function actionEdit(): Response
    {
        $category = $this->db->one('SELECT * FROM {categories} WHERE category_id = ?', [$this->request->getInt('id')]);

        return $category === null ? $this->error('The category does not exist.', 404) : $this->form($category);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!$this->app->auth()->canPublish()) {
            // 3.3.2 (N11): like over MCP – a category is public structure (its address, redirects), an author-level role only reads
            return $this->back('Categories are changed by an editor or an administrator.', type: 'error');
        }
        $r = $this->request;
        $id = $r->postInt('idt');
        $data = [
            'nazev' => $r->post('nazev'),
            'slug' => slugify($r->post('slug') !== '' ? $r->post('slug') : $r->post('nazev'), 110),
            'popis' => \Kaleta\Core\Html::forUser($r->post('popis'), $this->app->auth()),
            'weight' => max(0, min(65535, $r->postInt('weight', 100))),
            'language' => \Kaleta\Core\Language::column($this->app->settings(), $r->post('language')),
        ];
        $data['translation_of'] = $data['language'] === '' ? null : ($this->db->value("SELECT category_id FROM {categories} WHERE category_id = ? AND language = '' AND category_id <> ?", [$r->postInt('translation_of'), $id]) ?: null);
        if ($data['nazev'] === '') {
            return $this->form(['idt' => $id] + $data, ['nazev' => 'Fill in the category name.']);
        }

        $data['slug'] = \Kaleta\Core\Slug::makeUnique($data['slug'], fn (string $a): bool => $this->db->value('SELECT category_id FROM {categories} WHERE slug = ? AND category_id <> ?', [$a, $id]) !== null, 120);

        if ($id > 0) {
            $previous = $this->db->value('SELECT slug FROM {categories} WHERE category_id = ?', [$id]);
            $this->db->update('categories', $data, ['category_id' => $id]);
            if ($previous !== null && $previous !== $data['slug']) {
                // the category changed its slug: the old one is redirected, neither links nor search engines lose the page
                Redirects::add($this->db, 'novinky/kategorie/' . $previous, 'novinky/kategorie/' . $data['slug']);
            }
            $this->db->run('UPDATE {news} SET language = ? WHERE category_id = ?', [$data['language'], $id]); // news items have the language of their category
        } else {
            $this->db->insert('categories', $data);
        }

        return $this->back('Category saved.');
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!$this->app->auth()->canPublish()) {
            return $this->back('Categories are changed by an editor or an administrator.', type: 'error');
        }
        $id = $this->request->postInt('idt');
        if ((int) $this->db->value('SELECT COUNT(*) FROM {news} WHERE category_id = ?', [$id]) > 0) {
            return $this->back('The category cannot be deleted while it contains news items (including those in the trash). Move them elsewhere first.', type: 'error');
        }
        $this->db->delete('categories', ['category_id' => $id]);

        return $this->back('Category deleted.');
    }

    /**
     * @param array<string, mixed> $category
     * @param array<string, string> $errors
     */
    private function form(array $category, array $errors = []): Response
    {
        return $this->view('form', $category['idt'] ? 'Edit category' : 'New category', ['category' => $category, 'errors' => $errors]);
    }
}
