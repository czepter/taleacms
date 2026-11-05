<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\Db;

/**
 * Your own page templates: a page saved as a template starts new pages (New page, MCP create_page with page_template). The template is
 * a copy of the build made when it was saved – changing or deleting the page later does not change it. Every build passes
 * Build::sanitize on the way in, and a page started from it gets new element ids.
 */
final class PageTemplates
{
    /** How many templates a site keeps. */
    public const int LIMIT = 50;

    /** @return list<array{id: string, name: string, sections: int, updated_at: string}> newest first */
    public static function all(Db $db): array
    {
        return array_map(fn (array $r): array => ['id' => (string) $r['public_id'], 'name' => (string) $r['name'],
            'sections' => count(Build::fromJson((string) $r['build'])['children'] ?? []), 'updated_at' => (string) ($r['updated_at'] ?? $r['created_at'])],
            $db->all('SELECT public_id, name, build, created_at, updated_at FROM {page_templates} ORDER BY COALESCE(updated_at, created_at) DESC, page_template_id DESC'));
    }

    /**
     * Saves a build as a new template; returns its public id, or a refusal (empty build, no name, the limit reached).
     *
     * @param array<string, mixed> $build
     */
    public static function save(Db $db, string $name, array $build, bool $admin): string|array
    {
        $name = trim(mb_substr(strip_tags($name), 0, 100));
        [$clean] = Build::sanitize($build, $admin);
        if ($name === '') {
            return ['error' => 'Give the template a name.'];
        }
        if ($clean['children'] === []) {
            return ['error' => 'The page has nothing to save yet – build it in the builder first.'];
        }
        if ((int) $db->value('SELECT COUNT(*) FROM {page_templates}') >= self::LIMIT) {
            return ['error' => 'There are already 50 templates – delete one you no longer need first.'];
        }
        $id = $db->insert('page_templates', ['name' => $name, 'build' => Build::toJson($clean), 'created_at' => date('Y-m-d H:i:s')]);

        return $db->publicId('page_templates', $id);
    }

    public static function rename(Db $db, string $publicId, string $name): bool
    {
        $name = trim(mb_substr(strip_tags($name), 0, 100));

        return $name !== '' && $db->update('page_templates', ['name' => $name, 'updated_at' => date('Y-m-d H:i:s')], ['public_id' => $publicId]) > 0;
    }

    public static function delete(Db $db, string $publicId): bool
    {
        return $db->delete('page_templates', ['public_id' => $publicId]) > 0;
    }

    /**
     * The build a new page starts with: the template's, with new element ids (two pages from one template must not share them), or
     * null for an unknown template.
     *
     * @return array<string, mixed>|null
     */
    public static function build(Db $db, string $publicId): ?array
    {
        $json = $publicId === '' ? null : $db->value('SELECT build FROM {page_templates} WHERE public_id = ?', [$publicId]);
        $build = is_string($json) ? Build::fromJson($json) : null;
        if ($build === null) {
            return null;
        }
        $build['children'] = array_map(Library::withNewIds(...), $build['children'] ?? []);

        return $build;
    }
}
