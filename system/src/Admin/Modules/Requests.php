<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Requests as Inbox;
use Kaleta\Core\Response;

/**
 * Requests to Claude (2.15, Core\Requests): staff with this section write what they need changed on the site; Claude reads
 * the requests over MCP, works as drafts and leaves notes; the requester reads the notes and replies here. Every user with
 * the section sees the whole inbox – it is the team's list of work, not private mail.
 */
final class Requests extends Module
{
    public const string IDENT = 'requests';
    public const string NAME = 'Requests';
    public const string GROUP = 'Content';
    public const string ICON = 'komentare';

    protected function actionList(): Response
    {
        $status = $this->request->get('status');
        $status = isset(Inbox::STATUSES[$status]) ? $status : '';

        return $this->view('list', 'Requests', ['requests' => Inbox::all($this->app, $status, 300), 'status' => $status,
            'open' => (int) $this->db->value("SELECT COUNT(*) FROM {requests} WHERE status IN ('new', 'in_progress')"),
            'claudeOn' => \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'claude')]);
    }

    protected function actionNew(): Response
    {
        return $this->view('new', 'New request', [
            'pages' => $this->db->pairs('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY titulek'),
            'news' => \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky') ? $this->db->pairs('SELECT idc, titulek FROM {novinky} WHERE smazano IS NULL ORDER BY datum DESC LIMIT 100') : [],
            'items' => $this->db->pairs('SELECT p.idp, CONCAT(k.nazev, \' – \', p.nazev) FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NULL ORDER BY k.nazev, p.nazev LIMIT 300'),
            'maxAttachments' => Inbox::MAX_ATTACHMENTS, 'limit' => \Kaleta\Core\Files::limitText(),
        ]);
    }

    /** Saves a new request; its attachments go to Media first (the same path as the Media screen), so Claude can use them. */
    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        if (trim($this->request->post('title')) === '' || trim($this->request->post('text')) === '') {
            // checked before the uploads, so a request sent back for its text leaves no stray files in Media
            return $this->back(trim($this->request->post('title')) === '' ? 'Give the request a title.' : 'Write what should change.', 'new', [], 'chyba');
        }
        $attachments = [];
        foreach (Media::uploadedFiles('prilohy', Inbox::MAX_ATTACHMENTS) as $file) {
            try {
                $attachments[] = (int) Media::store($this->app, $file)['ido'];
            } catch (\RuntimeException $e) {
                return $this->back(t('The attachment %s could not be saved: %s', (string) ($file['name'] ?? ''), t($e->getMessage())), 'new', [], 'chyba');
            }
        }
        $about = $this->request->post('about_url') !== '' ? $this->request->post('about_url') : $this->request->post('about');
        try {
            $id = Inbox::create($this->app, $this->app->auth()->id(), $this->request->post('title'), $this->request->post('text'), $about, $attachments);
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), 'new', [], 'chyba');
        }

        return $this->back('The request is saved. Claude will see it the next time it works on the site; you will read its notes here.', 'detail', ['id' => $id]);
    }

    protected function actionDetail(): Response
    {
        $request = Inbox::get($this->app, $this->request->getInt('id'));
        if ($request === null) {
            return $this->error('The request does not exist.', 404);
        }

        return $this->view('detail', t('Request #%d', (int) $request['id']), [
            'r' => $request, 'about' => Inbox::describeAbout($this->app, (string) $request['about']), 'attachments' => Inbox::attachments($this->app, $request['attachments']),
            'messages' => Inbox::messages($this->db, (int) $request['id']), 'mine' => (int) $request['author_id'] === $this->app->auth()->id(),
        ]);
    }

    /** The person's reply to Claude's notes. */
    protected function actionReply(): Response
    {
        $id = $this->request->postInt('id');
        $request = $this->request->isPost() ? Inbox::get($this->app, $id) : null;
        if ($request === null) {
            return $this->back();
        }
        $user = $this->app->auth()->user();
        if (!Inbox::addMessage($this->app, $id, 'person', (string) (($user['jmeno'] ?? '') !== '' ? $user['jmeno'] : ($user['user'] ?? '')), $this->request->post('text'))) {
            return $this->back('Write the reply first.', 'detail', ['id' => $id], 'chyba');
        }

        return $this->back('The reply is added – Claude reads it with the request.', 'detail', ['id' => $id]);
    }

    /** A person changes the status: closes a request, declines it, or reopens a done one. */
    protected function actionStatus(): Response
    {
        $id = $this->request->postInt('id');
        $request = $this->request->isPost() ? Inbox::get($this->app, $id) : null;
        if ($request === null) {
            return $this->back();
        }
        // the requester closing their own request needs no e-mail about it
        if (!Inbox::setStatus($this->app, $request, $this->request->post('status'), '', (int) $request['author_id'] !== $this->app->auth()->id())) {
            return $this->back('This status change is not possible.', 'detail', ['id' => $id], 'chyba');
        }

        return $this->back('The status is saved.', 'detail', ['id' => $id]);
    }
}
