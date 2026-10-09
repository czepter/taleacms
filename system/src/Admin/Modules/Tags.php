<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * Tags and topics. Tags are created automatically when writing news; here they can be renamed, merged and deleted.
 * A tag with a description and an image behaves on the site as a topic page (/novinky/stitek/<slug>).
 */
final class Tags extends Module
{
    public const string IDENT = 'tags';
    public const string EXTENSION = 'novinky';
    public const string NAME = 'Tags and topics';
    public const string GROUP = 'Content';
    public const string ICON = 'stitky';
    public const string PARENT = 'news';

    protected function actionList(): Response
    {
        $edit = $this->db->one('SELECT * FROM {tags} WHERE tag_id = ?', [$this->request->getInt('edit')]);

        return $this->view('list', 'Tags and topics', [
            'tags' => $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM {news_tags} cs WHERE cs.tag_id = s.tag_id) AS pocet FROM {tags} s ORDER BY (s.description IS NOT NULL AND s.description <> \'\') DESC, pocet DESC, s.name LIMIT 500'),
            'edit' => $edit,
        ]);
    }

    protected function actionSave(): Response
    {
        $tag = $this->db->one('SELECT * FROM {tags} WHERE tag_id = ?', [$this->request->postInt('ids')]);
        $name = mb_substr(trim($this->request->post('nazev')), 0, 80);
        if (!$this->request->isPost() || $tag === null || $name === '') {
            return $this->back('Enter the tag name.', type: 'error');
        }
        $this->db->update('tags', ['name' => $name, 'description' => \Kaleta\Core\Html::forUser(trim($this->request->post('popis')), $this->app->auth()), 'image' => mb_substr($this->request->post('image'), 0, 255)], ['tag_id' => $tag['ids']]);

        // merge: the news items get the target tag, this one ceases to exist and its slug is redirected
        $target = $this->db->one('SELECT * FROM {tags} WHERE tag_id = ? AND tag_id <> ?', [$this->request->postInt('sloucit_do'), $tag['ids']]);
        if ($target !== null) {
            $this->db->run('INSERT IGNORE INTO {news_tags} (news_id, tag_id) SELECT news_id, ? FROM {news_tags} WHERE tag_id = ?', [$target['ids'], $tag['ids']]);
            $this->db->delete('news_tags', ['tag_id' => $tag['ids']]);
            $this->db->delete('tags', ['tag_id' => $tag['ids']]);
            Redirects::add($this->db, 'novinky/stitek/' . $tag['slug'], 'novinky/stitek/' . $target['slug']);

            return $this->back(t('Tag “%s” has been merged into “%s”.', $tag['nazev'], $target['nazev']));
        }

        return $this->back('Tag saved.');
    }

    protected function actionDelete(): Response
    {
        if ($this->request->isPost()) {
            $this->db->delete('news_tags', ['tag_id' => $this->request->postInt('ids')]);
            $this->db->delete('tags', ['tag_id' => $this->request->postInt('ids')]);
        }

        return $this->back('Tag deleted. The news items remain, they just no longer carry it.');
    }
}
