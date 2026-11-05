<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Builder\Build;
use Talea\Builder\PageTemplates as Templates;
use Talea\Core\Response;

/**
 * Your page templates (Builder\PageTemplates), under Pages: "Save as template" in a page's menu keeps a copy of its build; this list
 * renames and deletes the templates, New page offers them as starts. Whoever may open Pages may use them.
 */
final class PageTemplates extends Module
{
    public const string IDENT = 'page_templates';
    public const string SHARES_PERMISSION_OF = 'pages';
    public const string PARENT = 'pages';
    public const string NAME = 'Your page templates';
    public const string GROUP = 'Website';
    public const string ICON = 'pages';
    public const bool ADMIN_ONLY = false;

    /** Rename or delete the templates (they are copies, the pages they came from stay as they are). */
    protected function actionList(): Response
    {
        return $this->view('list', 'Your page templates', ['templates' => Templates::all($this->db)]);
    }

    /** "Save as template" (Pages list): a copy of the page's build (its draft, otherwise the published one) becomes a template for New page. */
    protected function actionSave(): Response
    {
        $page = $this->request->isPost() ? $this->db->byPublicId('pages', $this->request->post('page_id')) : null;
        $json = $page === null || $page['deleted_at'] !== null ? null : ($page['build_draft'] ?? $page['build']);
        if ($page === null || $json === null) {
            return $this->back('Only a page built in the builder can be saved as a template.', '', [], 'error');
        }
        $saved = Templates::save($this->db, (string) $page['title'], Build::fromJson((string) $json) ?? ['v' => Build::VERSION, 'children' => []], $this->app->auth()->isAdmin());
        if (is_array($saved)) {
            return $this->back($saved['error'], '', [], 'error');
        }

        return $this->back(t('“%s” is saved as a template – New page offers it.', (string) $page['title']));
    }

    protected function actionRename(): Response
    {
        if ($this->request->isPost() && Templates::rename($this->db, $this->request->post('id'), $this->request->post('name'))) {
            return $this->back('The template is renamed.');
        }

        return $this->back('Give the template a name.', '', [], 'error');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            Templates::delete($this->db, $this->request->post('id'));
        }

        return $this->back('The template is deleted. Pages started from it stay as they are.');
    }
}
