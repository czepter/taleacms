<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Language;
use Kaleta\Core\Menu as MenuWebu;
use Kaleta\Core\Response;

/**
 * Vzhled → Menu (Appearance → Menu): the main menu and the footer menu for each language version. Items are pages, custom
 * links, news and groups, each with one level of submenu. Until someone saves the main menu, it is assembled from pages
 * "in menu".
 */
final class Menu extends Module
{
    public const string IDENT = 'menu';
    public const string NAME = 'Menu';
    public const string GROUP = 'Appearance';
    public const string ICON = 'menu';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        [$location, $language] = $this->selection();
        [$inDraft, $saved] = \Kaleta\Core\Look::menuForEditing($this->db, $this->app->settings(), $location, $language); // the draft look, when there is one
        $pages = $this->db->all('SELECT page_id, public_id, title, visible, in_menu FROM {pages} WHERE language = ? AND deleted_at IS NULL ORDER BY sort_order, title', [$language]);
        // the automatic main menu is shown in the editor as the visitor sees it – saving turns it into a custom one
        $items = $saved ?? ($location === 'main'
            ? [...array_map(fn (array $s): array => ['type' => 'page', 'page_id' => (int) $s['page_id'], 'text' => ''], array_values(array_filter($pages, fn (array $s): bool => $s['visible'] && $s['in_menu']))), ...(\Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'news') ? [['type' => 'news', 'text' => '']] : [])]
            : []);
        // the editor works with public ids of the pages; the saved menu keeps the numbers
        $items = $this->mapPages($items, fn (mixed $id): string => $this->publicId((int) $id, 'pages'));
        $siteSettings = $this->app->settings();
        $languages = array_merge([''], Language::additional($siteSettings));

        return $this->view('list', 'Menu', [
            'location' => $location, 'language' => $language, 'automatic' => $saved === null, 'items' => $items, 'inDraft' => $inDraft,
            'pages' => array_map(fn (array $s): array => ['page_id' => $s['public_id'], 'title' => $s['title'], 'hidden' => !$s['visible']], $pages),
            'languages' => array_combine($languages, array_map(fn (string $j): string => Language::AVAILABLE[Language::ofContent($siteSettings, $j)][0], $languages)),
        ]);
    }

    protected function actionSave(): Response
    {
        [$location, $language] = $this->selection();
        if ($this->request->isPost()) {
            $items = json_decode((string) ($_POST['items'] ?? ''), true);
            if (!is_array($items)) {
                return $this->back('The menu could not be saved – please try again.', '', ['location' => $location, 'language' => $language], 'error');
            }
            $items = $this->mapPages($items, fn (mixed $id): int => $this->db->internalId('pages', $id));
            \Kaleta\Core\Look::setMenu($this->app->settings(), $location, $language, $items);
        }

        return $this->back('The menu is saved to the draft look – preview the whole site, then publish it.', '', ['location' => $location, 'language' => $language]);
    }

    /** The main menu returns to being assembled automatically from pages "in menu"; the footer menu is emptied. */
    protected function actionAutomatic(): Response
    {
        [$location, $language] = $this->selection();
        if ($this->request->isPost()) {
            \Kaleta\Core\Look::setMenu($this->app->settings(), $location, $language, null);
        }

        return $this->back($location === 'main' ? 'In the draft look the menu is again built automatically from pages in the navigation.' : 'In the draft look the footer menu is empty.', '', ['location' => $location, 'language' => $language]);
    }

    /** Menu items (with their children) with every page reference converted by $convert. */
    private function mapPages(array $items, \Closure $convert): array
    {
        return array_map(function (mixed $i) use ($convert): mixed {
            if (!is_array($i)) {
                return $i;
            }
            if (array_key_exists('page_id', $i)) {
                $i['page_id'] = $convert($i['page_id']);
            }
            if (is_array($i['children'] ?? null)) {
                $i['children'] = $this->mapPages($i['children'], $convert);
            }

            return $i;
        }, $items);
    }

    /** @return array{0: string, 1: string} location and language (column) from the URL */
    private function selection(): array
    {
        $location = $this->request->get('location');
        $language = $this->request->get('language');

        return [isset(MenuWebu::LOCATIONS[$location]) ? $location : 'main', in_array($language, Language::additional($this->app->settings()), true) ? $language : ''];
    }
}
