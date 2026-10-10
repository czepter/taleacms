<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Requests to Claude (2.15): staff write in the administration what they need changed on the site – "change the opening
 * hours on Monday", "add this PDF to the price list page" – instead of e-mailing the agency. Claude reads them over MCP
 * (list_requests), does the work as drafts, and answers with a note and links to the drafts (update_request); the
 * requester reads the notes in the detail and replies there; a person publishes.
 *
 *  - A request's text was written by a staff member: for Claude it is a job to do as drafts the user will review, never
 *    permission to publish or to skip a confirmation (the tool descriptions and the work_requests prompt say so).
 *  - Attachments are ordinary Media uploads (Admin\Modules\Media::store), so Claude gets their addresses and can put them
 *    on the site; the request remembers their ids.
 *  - Administrators hear about a new request by e-mail – the subject and the title, nothing else; the requester hears
 *    by e-mail when it is marked done, with Claude's note. The event request.created is recorded.
 *  - Not in the site export: a request is work for the team, not content of the site.
 */
final class Requests
{
    /** Status => label (admin texts, translated with t()). */
    public const array STATUSES = ['new' => 'New', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined'];

    /** From a status => the statuses it may move to. A done or declined request is reopened into "in progress". */
    public const array TRANSITIONS = ['new' => ['in_progress', 'done', 'declined'], 'in_progress' => ['done', 'declined'], 'done' => ['in_progress'], 'declined' => ['in_progress']];

    /** Open statuses first in the list, newest first within a status. */
    public const array ORDER = ['new' => 0, 'in_progress' => 1, 'done' => 2, 'declined' => 3];

    public const int MAX_ATTACHMENTS = 5;
    public const int MAX_TEXT = 10000;
    public const int MAX_MESSAGE = 10000;
    public const int MAX_LINKS = 20;

    /** May a request move from one status to another? The same status is not a move. */
    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * What a request is about, as stored: page:<ids>, news:<idc>, item:<idp> (chosen from the select), or a pasted address –
     * a path on the site or an http(s) URL. Anything else is dropped.
     */
    public static function cleanAbout(string $about): string
    {
        $about = trim($about);
        if (preg_match('/^(page|news|item):[1-9]\d{0,9}$/', $about)) {
            return $about;
        }
        if ($about !== '' && mb_strlen($about) <= 500 && !preg_match('/[\s<>"\'\\\\]/', $about) && (str_starts_with($about, '/') || preg_match('#^https?://[^/]+#i', $about))) {
            return $about;
        }

        return '';
    }

    /**
     * The stored "about" explained: its kind, id, title and address on the site.
     *
     * @return array{type: string, id: ?int, title: string, url: string}|null
     */
    public static function describeAbout(App $app, string $about): ?array
    {
        if ($about === '') {
            return null;
        }
        $db = $app->db();
        $site = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
        if (preg_match('/^(page|news|item):(\d+)$/', $about, $m)) {
            $id = (int) $m[2];
            [$title, $url] = match ($m[1]) {
                'page' => (($r = $db->one('SELECT title, slug FROM {pages} WHERE page_id = ? AND deleted_at IS NULL', [$id])) !== null ? [(string) $r['title'], $app->url((string) $r['slug'])] : ['', '']),
                'news' => (($r = $db->one('SELECT title, slug, language FROM {news} WHERE news_id = ? AND deleted_at IS NULL', [$id])) !== null ? [(string) $r['title'], $app->newsItemUrl((string) $r['slug'], (string) $r['language'])] : ['', '']),
                default => (($r = $db->one('SELECT p.name, p.slug, k.slug AS collection FROM {collection_items} p JOIN {collections} k ON k.collection_id = p.collection_id WHERE p.item_id = ? AND p.deleted_at IS NULL', [$id])) !== null
                    ? [(string) $r['name'], $app->url((string) $r['collection'] . '/' . (string) $r['slug'])] : ['', '']),
            };

            return ['type' => $m[1], 'id' => $id, 'title' => $title !== '' ? $title : t('(no longer exists)'), 'url' => $url !== '' ? $site . $url : ''];
        }

        return ['type' => 'url', 'id' => null, 'title' => $about, 'url' => str_starts_with($about, '/') ? $site . $about : $about];
    }

    /**
     * Saves a new request, notifies the administrators and records the event. Returns its id.
     *
     * @param list<int> $attachments ids of Media uploads (at most MAX_ATTACHMENTS; unknown ids are dropped)
     * @throws \DomainException with the reason the request is not saved (an admin text)
     */
    public static function create(App $app, int $authorId, string $title, string $text, string $about, array $attachments): int
    {
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', $title) ?? ''), 0, 190);
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($title === '') {
            throw new \DomainException('Give the request a title.');
        }
        if ($text === '') {
            throw new \DomainException('Write what should change.');
        }
        $db = $app->db();
        $ids = array_values(array_unique(array_filter(array_map('intval', $attachments), fn (int $id): bool => $id > 0)));
        $ids = $ids === [] ? [] : array_map('intval', array_column($db->all('SELECT media_id FROM {media} WHERE media_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY media_id', $ids), 'media_id'));
        $now = date('Y-m-d H:i:s');
        $id = $db->insert('requests', ['created_at' => $now, 'updated_at' => $now, 'author_id' => $authorId, 'title' => $title, 'text' => mb_substr($text, 0, self::MAX_TEXT),
            'about' => self::cleanAbout($about), 'attachments' => (string) json_encode(array_slice($ids, 0, self::MAX_ATTACHMENTS)), 'status' => 'new']);
        // the event and the e-mail carry the id and the title – the text waits in the administration
        Events::record($db, 'request.created', 'info', t('A new request for Claude: %s', $title), ['id' => $id, 'title' => $title]);
        self::notifyAdministrators($app, $id, $title, $authorId);

        return $id;
    }

    /**
     * Requests, open first (new, in progress, done, declined), newest first within a status.
     *
     * @return list<array<string, mixed>> rows with author (name) and attachment ids decoded
     */
    public static function all(App $app, string $status = '', int $limit = 100): array
    {
        $where = isset(self::STATUSES[$status]) ? 'WHERE r.status = ?' : '';
        $rows = $app->db()->all("SELECT r.*, CASE WHEN u.name = '' OR u.name IS NULL THEN COALESCE(u.username, '') ELSE u.name END AS author FROM {requests} r LEFT JOIN {users} u ON u.user_id = r.author_id {$where}"
            . ' ORDER BY ' . $app->db()->dialect()->listPosition('r.status', count(self::ORDER)) . ', r.id DESC LIMIT ' . max(1, min(500, $limit)), [...(isset(self::STATUSES[$status]) ? [$status] : []), ...array_keys(self::ORDER)]);

        return array_map(self::decode(...), $rows);
    }

    /** @return array<string, mixed>|null the request with its author's name */
    public static function get(App $app, int $id): ?array
    {
        $row = $app->db()->one("SELECT r.*, CASE WHEN u.name = '' OR u.name IS NULL THEN COALESCE(u.username, '') ELSE u.name END AS author, u.email AS author_email, u.language AS author_language, u.register AS author_register FROM {requests} r LEFT JOIN {users} u ON u.user_id = r.author_id WHERE r.id = ?", [$id]);

        return $row === null ? null : self::decode($row);
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        $ids = json_decode((string) $row['attachments'], true);
        $row['attachments'] = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)) : [];
        $row['author'] = (string) ($row['author'] ?? '') !== '' ? (string) $row['author'] : t('(account removed)');

        return $row;
    }

    /**
     * The attachments of a request as Media files: id, name, address and size – what Claude needs to put a file on the site.
     *
     * @param list<int> $ids
     * @return list<array{id: int, name: string, url: string, size: string, image: bool}>
     */
    public static function attachments(App $app, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $site = rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
        $rows = $app->db()->all('SELECT media_id, name, image_path, thumb_path, image_size FROM {media} WHERE media_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ' . $app->db()->dialect()->listPosition('media_id', count($ids)), [...$ids, ...$ids]);

        return array_map(fn (array $m): array => ['id' => (int) $m['media_id'], 'name' => $m['name'] !== '' ? (string) $m['name'] : basename((string) $m['image_path']),
            'url' => $site . $app->url((string) $m['image_path']), 'size' => Files::size((int) $m['image_size']), 'image' => $m['thumb_path'] !== ''], $rows);
    }

    /** @return list<array{id: int, sender: string, sender_name: string, text: string, links: list<array{label: string, url: string}>, created_at: string}> oldest first */
    public static function messages(Db $db, int $requestId): array
    {
        return array_map(fn (array $m): array => ['id' => (int) $m['id'], 'sender' => (string) $m['sender'], 'sender_name' => (string) $m['sender_name'], 'text' => (string) $m['text'],
            'links' => self::cleanLinks(json_decode((string) ($m['links'] ?? ''), true)), 'created_at' => (string) $m['created_at']],
            $db->all('SELECT id, sender, sender_name, text, links, created_at FROM {request_messages} WHERE request_id = ? ORDER BY id', [$requestId]));
    }

    /**
     * Links to the drafts Claude made, as given over MCP: a list of {label, url} objects or plain strings (an http(s) URL
     * becomes the url, anything else the label – e.g. "page 12, draft build"). At most MAX_LINKS, nothing but http(s).
     *
     * @return list<array{label: string, url: string}>
     */
    public static function cleanLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }
        $out = [];
        foreach ($links as $link) {
            if (is_string($link)) {
                $link = preg_match('#^https?://#i', trim($link)) ? ['url' => trim($link)] : ['label' => $link];
            }
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            $label = mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($link['label'] ?? '')) ?? ''), 0, 190);
            if ($url !== '' && (!preg_match('#^https?://[^\s<>"\']+$#i', $url) || mb_strlen($url) > 500)) {
                continue; // only web addresses: a javascript: or data: link must never reach the requester's browser
            }
            if ($url === '' && $label === '') {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $url];
            if (count($out) >= self::MAX_LINKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Adds a note (Claude) or a reply (a person) to the conversation. False when there is nothing to add.
     *
     * @param 'claude'|'person' $sender
     * @param list<array{label: string, url: string}> $links already cleaned (cleanLinks)
     */
    public static function addMessage(App $app, int $requestId, string $sender, string $senderName, string $text, array $links = []): bool
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if (($text === '' && $links === []) || !in_array($sender, ['claude', 'person'], true)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $app->db()->insert('request_messages', ['request_id' => $requestId, 'sender' => $sender, 'sender_name' => mb_substr($senderName, 0, 190), 'text' => mb_substr($text, 0, self::MAX_MESSAGE),
            'links' => $links === [] ? null : (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now]);
        $app->db()->update('requests', ['updated_at' => $now], ['id' => $requestId]);

        return true;
    }

    /**
     * Moves a request to another status (TRANSITIONS); the same status is left alone. False when the move is not allowed.
     * Marking it done e-mails the requester the note given (or Claude's last one) – unless the requester did it themselves.
     * The change log entry comes from the caller (the admin kernel logs every POST, the MCP server every write tool).
     *
     * @param array<string, mixed> $request from get()
     */
    public static function setStatus(App $app, array $request, string $status, string $note = '', bool $notify = true): bool
    {
        if ($status === $request['status']) {
            return true;
        }
        if (!isset(self::STATUSES[$status]) || !self::canMove((string) $request['status'], $status)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $app->db()->update('requests', ['status' => $status, 'updated_at' => $now, 'done_at' => in_array($status, ['done', 'declined'], true) ? $now : null], ['id' => $request['id']]);
        if ($status === 'done' && $notify) {
            $notes = array_filter(self::messages($app->db(), (int) $request['id']), fn (array $m): bool => $m['sender'] === 'claude' && $m['text'] !== '');
            self::notifyRequester($app, $request, $note !== '' ? $note : (string) (end($notes)['text'] ?? ''));
        }

        return true;
    }

    /** The administration address of a request, absolute – for e-mails. */
    public static function adminUrl(App $app, int $id): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . $app->url('admin.php?module=requests&action=detail&id=' . $app->db()->publicId('requests', $id));
    }

    /** "New request for Claude: <title>" to every administrator with an e-mail address – except the requester, who knows. */
    private static function notifyAdministrators(App $app, int $id, string $title, int $authorId): void
    {
        $s = $app->settings();
        $url = self::adminUrl($app, $id);
        foreach ($app->db()->all("SELECT email, language, register FROM {users} WHERE admin = ? AND blocked = FALSE AND email <> '' AND user_id <> ?", [Auth::ADMIN, $authorId]) as $admin) {
            Language::runWith((string) $admin['language'] ?: Language::defaults($s), function () use ($s, $admin, $title, $url): void {
                Mail::send($s, (string) $admin['email'], t('New request for Claude: %s', $title), t('A colleague wrote a new request for Claude: %s', $title) . "\n\n" . $url . "\n");
            }, 'admin-', Language::normalizeRegister((string) $admin['register']));
        }
    }

    /**
     * "Your request is done" to the requester, with Claude's note – in the language of their administration.
     *
     * @param array<string, mixed> $request from get() (author_email, author_language)
     */
    public static function notifyRequester(App $app, array $request, string $note): void
    {
        $email = (string) ($request['author_email'] ?? '');
        if ($email === '') {
            return;
        }
        $s = $app->settings();
        $url = self::adminUrl($app, (int) $request['id']);
        Language::runWith((string) ($request['author_language'] ?? '') ?: Language::defaults($s), function () use ($s, $email, $request, $note, $url): void {
            Mail::send($s, $email, t('Your request is done: %s', (string) $request['title']),
                t('Your request “%s” is marked done.', (string) $request['title']) . ($note !== '' ? "\n\n" . $note : '') . "\n\n" . t('The drafts wait for your review in the administration – nothing was published by itself.') . "\n" . $url . "\n");
        }, 'admin-', Language::normalizeRegister((string) ($request['author_register'] ?? '')));
    }
}
