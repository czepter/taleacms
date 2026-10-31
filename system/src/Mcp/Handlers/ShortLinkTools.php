<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Core\ShortLinks;

/**
 * MCP tools of the short links (Core\ShortLinks): list, create or retarget, delete. A short link has no draft state and a change is
 * live at once, so a connection limited to drafts may list but not change. Part of Mcp\Tools; the change log is written by Mcp\Server.
 *
 * @phpstan-ignore trait.unused
 */
trait ShortLinkTools
{
    /** list_short_links */
    private function toolListShortLinks(string $name, array $a): mixed
    {
        $this->shortLinkAccess();
        $rows = $this->app->db()->all('SELECT * FROM {short_links} ORDER BY created_at DESC, short_link_id DESC');

        return ['short_links' => array_map(fn (array $row): array => $this->shortLinkView($row), $rows)];
    }

    /** save_short_link */
    private function toolSaveShortLink(string $name, array $a): mixed
    {
        $this->shortLinkAccess(true);
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $old = null;
        if ($id > 0) {
            $old = $db->one('SELECT * FROM {short_links} WHERE short_link_id = ?', [$id]);
            if ($old === null) {
                throw new \InvalidArgumentException('The short link does not exist. Use list_short_links.');
            }
            if (isset($a['name']) && (string) $a['name'] !== $old['path']) {
                throw new \InvalidArgumentException('The short part of a short link never changes: delete it and create a new one.');
            }
        }
        $input = $old ?? [];
        if (isset($a['target'])) {
            $target = (array) $a['target'];
            $type = (string) ($target['type'] ?? '');
            $input['target_type'] = $type;
            $input['target_id'] = $type === 'url' ? null : (int) ($target['id'] ?? 0);
            $input['target_url'] = $type === 'url' ? (string) ($target['url'] ?? '') : null;
        } elseif ($old === null) {
            throw new \InvalidArgumentException('Say where the short link leads: target {type: page|news|item, id} or {type: "url", url}.');
        }
        foreach (ShortLinks::UTM as $key) {
            $given = ((array) ($a['utm'] ?? []))[substr($key, 4)] ?? null;
            if ($given !== null) {
                $input[$key] = (string) $given;
            }
        }
        if (isset($a['label'])) {
            $input['label'] = (string) $a['label'];
        }
        if ($old === null && isset($a['name'])) {
            $input['name'] = (string) $a['name'];
        }
        $row = ShortLinks::save($this->app, $input, $id);
        if (is_string($row)) {
            throw new \InvalidArgumentException($row);
        }

        return $this->shortLinkView($row) + ['qr_svg' => ShortLinks::qrSvg($this->app, (string) $row['path'])];
    }

    /** delete_short_link */
    private function toolDeleteShortLink(string $name, array $a): mixed
    {
        $this->shortLinkAccess(true);
        $id = (int) ($a['id'] ?? 0);
        if ($this->app->db()->value('SELECT 1 FROM {short_links} WHERE short_link_id = ?', [$id]) === null) {
            throw new \InvalidArgumentException('The short link does not exist. Use list_short_links.');
        }
        ShortLinks::delete($this->app, $id);

        return ['deleted' => true];
    }

    private function shortLinkAccess(bool $change = false): void
    {
        $auth = $this->app->auth();
        if (!$auth->hasModule('short_links')) {
            throw new \DomainException('This user has no access to the Short links section.');
        }
        if ($change && $auth->draftsOnly()) {
            throw new \DomainException('A connection limited to drafts cannot change short links: a change is live at once.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shortLinkView(array $row): array
    {
        $utm = [];
        foreach (ShortLinks::UTM as $key) {
            $utm[substr($key, 4)] = (string) $row[$key];
        }

        return [
            'id' => (int) $row['short_link_id'],
            'url' => ShortLinks::address($this->app, (string) $row['path']),
            'path' => (string) $row['path'],
            'label' => (string) $row['label'],
            'target' => $row['target_type'] === 'url' ? ['type' => 'url', 'url' => (string) $row['target_url']] : ['type' => (string) $row['target_type'], 'id' => (int) $row['target_id']],
            'utm' => $utm,
            'hits' => (int) $row['hits'],
            'last_hit_at' => $row['last_hit_at'],
            'target_public' => ShortLinks::targetUrl($this->app, $row) !== null,
        ];
    }
}
