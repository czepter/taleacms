<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\ChangeLog;
use Talea\Admin\Module;
use Talea\Core\App;
use Talea\Core\Extensions;
use Talea\Core\Response;

/**
 * Short links (docs/superpowers/specs/2026-10-31-short-links-design.md): a short address for print with a QR code, leading to a
 * page, news item, collection item or any address, with campaign fields that stay off the paper. The rules are in Core\ShortLinks;
 * this screen only reads the form, shows the refusal and writes the change log.
 */
final class ShortLinks extends Module
{
    public const string IDENT = 'short_links';
    public const string TABLE = 'short_links';
    public const string NAME = 'Short links';
    public const string GROUP = 'Search engines';
    public const string ICON = 'redirects';
    public const string EXTENSION = 'short_links';

    /** The form fields (besides the ids): they travel back to the form after a refusal. */
    private const array FIELDS = ['kind', 'name', 'target', 'target_url', 'label', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** content type => its table; the picker value is "<type>:<public id>" */
    private const array TABLES = ['page' => 'pages', 'news' => 'news', 'item' => 'collection_items'];

    /** The "Short link" links of an edit screen of content (type page | news | item): create one for it, and the list of its links. HTML, '' when not available. */
    public static function entryLinks(App $app, string $type, string $publicId): string
    {
        if (!Extensions::isEnabled($app->settings(), 'short_links') || !$app->auth()->hasModule(self::IDENT)) {
            return '';
        }
        $target = $type . ':' . $publicId;
        $count = (int) $app->db()->value('SELECT COUNT(*) FROM {short_links} WHERE target_type = ? AND target_id = ?', [$type, $app->db()->internalId(self::TABLES[$type], $publicId)]);
        $url = fn (array $params): string => $app->url('admin.php?' . http_build_query(['module' => self::IDENT] + $params));

        return '<a class="navigation" href="' . e($url(['new' => 1, 'target' => $target])) . '">' . e(t('Short link')) . '</a>'
            . ($count > 0 ? ' <a class="navigation" href="' . e($url(['target' => $target])) . '">' . e(t('Short links of this content (%d)', $count)) . '</a>' : '');
    }

    /** "page:<public id>" => [type, internal id]; null = not a content target. @return ?array{0: string, 1: int} */
    private function parseTarget(string $value): ?array
    {
        [$type, $publicId] = array_pad(explode(':', $value, 2), 2, '');
        if (!isset(self::TABLES[$type])) {
            return null;
        }

        return [$type, $this->db->internalId(self::TABLES[$type], $publicId)];
    }

    /** What a row leads to, for the list and the change log: the title of the content or the address. @param array<string, mixed> $row */
    private function targetLabel(array $row): string
    {
        $id = (int) $row['target_id'];

        return match ($row['target_type']) {
            'url' => (string) $row['target_url'],
            'page' => (string) $this->db->value('SELECT title FROM {pages} WHERE page_id = ?', [$id]),
            'news' => (string) $this->db->value('SELECT title FROM {news} WHERE news_id = ?', [$id]),
            default => (string) $this->db->value('SELECT name FROM {collection_items} WHERE item_id = ?', [$id]),
        };
    }

    protected function actionList(): Response
    {
        $r = $this->request;
        $target = $r->get('target') !== '' ? $this->parseTarget($r->get('target')) : null;
        if ($r->get('new') !== '' || $r->get('edit') !== '') {
            $edit = $r->get('edit') !== '' ? $this->db->one('SELECT * FROM {short_links} WHERE short_link_id = ?', [$this->idParam('edit')]) : null;
            if ($r->get('edit') !== '' && $edit === null) {
                return $this->error('The short link does not exist.', 404);
            }
            // after a refused save the form shows what was typed
            $typed = $r->get('kind') !== '' ? array_combine(self::FIELDS, array_map($r->get(...), self::FIELDS)) : [];

            return $this->view('edit', $edit !== null ? 'Edit short link' : 'New short link', [
                'edit' => $edit, 'typed' => $typed, 'preset' => $r->get('target'), 'options' => $this->options(),
            ]);
        }
        $rows = $this->db->all('SELECT * FROM {short_links}' . ($target !== null ? ' WHERE target_type = ? AND target_id = ?' : '') . ' ORDER BY short_link_id DESC LIMIT 500', $target ?? []);
        $app = $this->app;
        $links = array_map(fn (array $row): array => $row + [
            'label_target' => $this->targetLabel($row),
            'is_public' => \Talea\Core\ShortLinks::targetUrl($app, $row) !== null,
            'address' => \Talea\Core\ShortLinks::address($app, $row['path']),
        ], $rows);

        return $this->view('list', 'Short links', ['links' => $links, 'filtered' => $target !== null]);
    }

    /** The content the picker offers: pages, news and the items of collections with item pages, not trashed, by title. @return array<string, list<array{0: string, 1: string}>> group => [value, label] */
    private function options(): array
    {
        $groups = [
            t('Pages') => array_map(fn (array $p): array => ['page:' . $p['public_id'], (string) $p['title'] . ($p['language'] !== '' ? ' (' . $p['language'] . ')' : '')], $this->db->all('SELECT public_id, title, language FROM {pages} WHERE deleted_at IS NULL ORDER BY title')),
            t('News') => array_map(fn (array $n): array => ['news:' . $n['public_id'], (string) $n['title'] . ($n['language'] !== '' ? ' (' . $n['language'] . ')' : '')], $this->db->all('SELECT public_id, title, language FROM {news} WHERE deleted_at IS NULL ORDER BY title')),
        ];
        foreach ($this->db->all('SELECT c.name AS collection, i.public_id, i.name FROM {collection_items} i JOIN {collections} c ON c.collection_id = i.collection_id WHERE c.detail = TRUE AND i.deleted_at IS NULL ORDER BY c.name, i.name') as $i) {
            $groups[$i['collection']][] = ['item:' . $i['public_id'], (string) $i['name']];
        }

        return array_filter($groups);
    }

    protected function actionSave(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $p = $this->request;
        $editing = $p->post('short_link_id') !== '';
        $typed = array_combine(self::FIELDS, array_map($p->post(...), self::FIELDS));
        $form = ($editing ? ['edit' => $p->post('short_link_id')] : ['new' => 1]) + ['kind' => $typed['kind'] === 'name' ? 'name' : 'random'] + array_filter($typed, fn (string $v): bool => $v !== '');
        if (($guard = $this->refuseUnknownId('short_link_id', 'The short link does not exist.')) !== null) {
            return $guard;
        }
        $input = array_diff_key($typed, ['kind' => 1, 'name' => 1, 'target' => 1]);
        $content = $this->parseTarget($p->post('target'));
        $input += $content !== null ? ['target_type' => $content[0], 'target_id' => $content[1]] : ['target_type' => $p->post('target') === 'url' ? 'url' : ''];
        $input['name'] = $p->post('kind') === 'name' ? ltrim(mb_strtolower(trim($p->post('name'))), '/') : '';
        if (!$editing && $p->post('kind') === 'name' && $input['name'] === '') {
            return $this->back('Enter the short address, or choose a random one.', '', $form, 'error');
        }

        $id = $this->idParam('short_link_id');
        $previous = $id > 0 ? $this->db->one('SELECT * FROM {short_links} WHERE short_link_id = ?', [$id]) : null;
        $saved = \Talea\Core\ShortLinks::save($this->app, $input, $id);
        if (is_string($saved)) {
            return $this->back($saved, '', $form, 'error');
        }
        ChangeLog::write($this->app, 'short_links', 'save', '/' . $saved['path'] . ': ' . ($previous !== null ? $this->targetLabel($previous) . ' → ' : '') . $this->targetLabel($saved));

        return $this->back('Short link saved.');
    }

    protected function actionDelete(): Response
    {
        $row = $this->request->isPost() ? $this->db->one('SELECT * FROM {short_links} WHERE short_link_id = ?', [$this->idParam('short_link_id')]) : null;
        if ($row !== null) {
            \Talea\Core\ShortLinks::delete($this->app, (int) $row['short_link_id']);
            ChangeLog::write($this->app, 'short_links', 'delete', '/' . $row['path']);
        }

        return $this->back($row !== null ? 'Short link deleted.' : '');
    }

    /** The QR code of a short address as an SVG download, qr-<path with / as ->.svg. */
    protected function actionQr(): Response
    {
        $row = $this->db->one('SELECT path FROM {short_links} WHERE short_link_id = ?', [$this->idParam('id')]);
        if ($row === null) {
            return $this->error('The short link does not exist.', 404);
        }

        return new Response(\Talea\Core\ShortLinks::qrSvg($this->app, $row['path']), 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="qr-' . str_replace('/', '-', $row['path']) . '.svg"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
