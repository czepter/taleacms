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
        $whereParts = $language !== null && preg_match('/^([a-z]{2})?$/', $language) ? " WHERE t.jazyk = '{$language}'" : '';

        return $db->all(
            'SELECT t.*, (SELECT COUNT(*) FROM {novinky} c WHERE c.tema = t.idt AND c.smazano IS NULL) AS pocet_clanku
             FROM {kategorie} t' . $whereParts . ' ORDER BY t.hodnost DESC, t.nazev',
        );
    }

    /**
     * News needs at least one category. When there is none (news enabled only after installation), it creates the default
     * "Aktuality" in the site language, as the installation does. Returns the id of the new category, or null when one exists.
     */
    public static function createDefault(Db $db, Settings $s): ?int
    {
        if ($db->value('SELECT 1 FROM {kategorie} LIMIT 1') !== null) {
            return null;
        }
        $name = Language::runWith(Language::defaults($s), fn (): string => t('Aktuality'));

        return $db->insert('kategorie', ['nazev' => $name, 'seo_link' => slugify($name), 'popis' => '']);
    }

    protected function actionList(): Response
    {
        [$siteLanguages, $language, $column] = $this->readLanguageFilter();

        return $this->view('list', 'Categories', ['category' => self::listAll($this->db, $column), 'siteLanguages' => $siteLanguages, 'language' => $language]);
    }

    protected function actionNew(): Response
    {
        return $this->form(['idt' => 0, 'nazev' => '', 'seo_link' => '', 'popis' => '', 'hodnost' => 100]);
    }

    protected function actionEdit(): Response
    {
        $category = $this->db->one('SELECT * FROM {kategorie} WHERE idt = ?', [$this->request->getInt('id')]);

        return $category === null ? $this->error('The category does not exist.', 404) : $this->form($category);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!$this->app->auth()->canPublish()) {
            // 3.3.2 (N11): like over MCP – a category is public structure (its address, redirects), an author-level role only reads
            return $this->back('Categories are changed by an editor or an administrator.', type: 'chyba');
        }
        $r = $this->request;
        $id = $r->postInt('idt');
        $data = [
            'nazev' => $r->post('nazev'),
            'seo_link' => slugify($r->post('seo_link') !== '' ? $r->post('seo_link') : $r->post('nazev'), 110),
            'popis' => \Kaleta\Core\Html::forUser($r->post('popis'), $this->app->auth()),
            'hodnost' => max(0, min(65535, $r->postInt('hodnost', 100))),
            'jazyk' => \Kaleta\Core\Language::column($this->app->settings(), $r->post('jazyk')),
        ];
        $data['preklad_z'] = $data['jazyk'] === '' ? null : ($this->db->value("SELECT idt FROM {kategorie} WHERE idt = ? AND jazyk = '' AND idt <> ?", [$r->postInt('preklad_z'), $id]) ?: null);
        if ($data['nazev'] === '') {
            return $this->form(['idt' => $id] + $data, ['nazev' => 'Fill in the category name.']);
        }

        $data['seo_link'] = \Kaleta\Core\Slug::makeUnique($data['seo_link'], fn (string $a): bool => $this->db->value('SELECT idt FROM {kategorie} WHERE seo_link = ? AND idt <> ?', [$a, $id]) !== null, 120);

        if ($id > 0) {
            $previous = $this->db->value('SELECT seo_link FROM {kategorie} WHERE idt = ?', [$id]);
            $this->db->update('kategorie', $data, ['idt' => $id]);
            if ($previous !== null && $previous !== $data['seo_link']) {
                // the category changed its slug: the old one is redirected, neither links nor search engines lose the page
                Redirects::add($this->db, 'novinky/kategorie/' . $previous, 'novinky/kategorie/' . $data['seo_link']);
            }
            $this->db->run('UPDATE {novinky} SET jazyk = ? WHERE tema = ?', [$data['jazyk'], $id]); // news items have the language of their category
        } else {
            $this->db->insert('kategorie', $data);
        }

        return $this->back('Category saved.');
    }

    protected function actionDelete(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (!$this->app->auth()->canPublish()) {
            return $this->back('Categories are changed by an editor or an administrator.', type: 'chyba');
        }
        $id = $this->request->postInt('idt');
        if ((int) $this->db->value('SELECT COUNT(*) FROM {novinky} WHERE tema = ?', [$id]) > 0) {
            return $this->back('The category cannot be deleted while it contains news items (including those in the trash). Move them elsewhere first.', type: 'chyba');
        }
        $this->db->delete('kategorie', ['idt' => $id]);

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
