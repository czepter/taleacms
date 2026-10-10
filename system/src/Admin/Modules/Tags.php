<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\Response;

/**
 * Tags and topics. Tags are created automatically when writing news; here they can be renamed, merged and deleted.
 * A tag with a description and an image behaves on the site as a topic page (/news/tag/<slug>).
 */
final class Tags extends Module
{
    public const string IDENT = 'tags';
    public const string TABLE = 'tags';
    public const string EXTENSION = 'news';
    public const string NAME = 'Tags and topics';
    public const string GROUP = 'Content';
    public const string ICON = 'tags';
    public const string PARENT = 'news';

    protected function actionList(): Response
    {
        $edit = $this->db->one('SELECT * FROM {tags} WHERE tag_id = ?', [$this->idParam('edit')]);

        return $this->view('list', 'Tags and topics', [
            'tags' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {news_tags} cs WHERE cs.tag_id = s.tag_id) AS count FROM {tags} s ORDER BY (s.description IS NOT NULL AND s.description <> \'\') DESC, count DESC, s.name LIMIT 500'),
            'edit' => $edit,
        ]);
    }

    protected function actionSave(): Response
    {
        $tag = $this->db->one('SELECT * FROM {tags} WHERE tag_id = ?', [$this->idParam('tag_id')]);
        $name = mb_substr(trim($this->request->post('name')), 0, 80);
        if (!$this->request->isPost() || $tag === null || $name === '') {
            return $this->back('Enter the tag name.', type: 'error');
        }
        $this->db->update('tags', ['name' => $name, 'description' => \Talea\Core\Html::forUser(trim($this->request->post('description')), $this->app->auth()), 'image' => mb_substr($this->request->post('image'), 0, 255)], ['tag_id' => $tag['tag_id']]);

        // merge: the news items get the target tag, this one ceases to exist and its slug is redirected
        $target = $this->db->one('SELECT * FROM {tags} WHERE tag_id = ? AND tag_id <> ?', [$this->idParam('merge_into'), $tag['tag_id']]);
        if ($target !== null) {
            $this->db->insertIgnoreSelect('news_tags', ['news_id', 'tag_id'], 'SELECT news_id, ? FROM {news_tags} WHERE tag_id = ?', [$target['tag_id'], $tag['tag_id']]);
            $this->db->delete('news_tags', ['tag_id' => $tag['tag_id']]);
            $this->db->delete('tags', ['tag_id' => $tag['tag_id']]);
            Redirects::add($this->db, 'news/tag/' . $tag['slug'], 'news/tag/' . $target['slug']);

            return $this->back(t('Tag “%s” has been merged into “%s”.', $tag['name'], $target['name']));
        }

        return $this->back('Tag saved.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $id = $this->idParam('tag_id');
            $this->db->delete('news_tags', ['tag_id' => $id]);
            $this->db->delete('tags', ['tag_id' => $id]);
        }

        return $this->back('Tag deleted. The news items remain, they just no longer carry it.');
    }
}
