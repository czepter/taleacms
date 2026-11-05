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

    /**
     * What a new page can start from (the cards of New page): a blank builder page, a page template of the library, or the sections a
     * look suggests (Appearance → Looks, "New page with these sections"). value => [name, section keys]; the text page is ''.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function startOptions(string $look = ''): array
    {
        $options = ['blank' => ['Blank builder page', []]] + Library::PAGE_TEMPLATES;
        if (($definition = Looks::get($look)) !== null && $definition['sections'] !== []) {
            $options = ['look:' . $look => [t('Sections of the look %s', $definition['name']), $definition['sections']]] + $options;
        }

        return $options;
    }

    /** The start a posted template value names, or null (a text page, or a value that is not offered). @return array{0: string, 1: list<string>}|null */
    public static function startSections(string $value): ?array
    {
        return self::startOptions(str_starts_with($value, 'look:') ? substr($value, 5) : '')[$value] ?? null;
    }

    /**
     * The draft a new page starts with in the builder, or null for a text page (and the privacy policy outline, which is text): the sections
     * of a template or a look (in the page's language, classes created), a blank build, a copy of one of the site's pages ("copy" with
     * $copyOf) or one of your own templates ("mine:<id>").
     *
     * @return array<string, mixed>|null
     */
    public static function startBuild(Db $db, string $value, string $copyOf, string $title, string $language, string $contentLanguage, bool $admin): ?array
    {
        if ($value === 'copy') {
            return self::copyOf($db, $copyOf, $language, $admin);
        }
        if (str_starts_with($value, 'mine:')) {
            return self::build($db, substr($value, 5));
        }
        $start = self::startSections($value);

        return $start !== null && ($start[1] !== [] || $value === 'blank') ? Library::page($db, $start[1], $title, $contentLanguage) : null;
    }

    /**
     * "A copy of a page": the build of a builder page of the same language version – its draft, otherwise the published one – with new
     * element ids, or null when the posted page is not one.
     *
     * @return array<string, mixed>|null
     */
    public static function copyOf(Db $db, string $publicId, string $language, bool $admin): ?array
    {
        $row = $publicId === '' ? null : $db->one('SELECT build, build_draft FROM {pages} WHERE public_id = ? AND language = ? AND deleted_at IS NULL', [$publicId, $language]);
        $json = $row['build_draft'] ?? $row['build'] ?? null;
        if ($json === null) {
            return null;
        }
        [$build] = Build::sanitize(Build::fromJson((string) $json) ?? ['v' => Build::VERSION, 'children' => []], $admin);
        $build['children'] = array_map(Library::withNewIds(...), $build['children']);

        return $build;
    }

    /**
     * What the New page form shows: the starts (?template=look:<key> from the looks gallery adds that look's sections and picks them),
     * the start asked for, the names of the sections, your own templates and the builder pages of this language version to copy.
     *
     * @return array{starts: array<string, array{0: string, 1: list<string>}>, start: string, sectionNames: array<string, string>, mine: list<array<string, mixed>>, copyable: array<string, string>}
     */
    public static function newPage(Db $db, string $language, string $start): array
    {
        return ['starts' => self::startOptions(str_starts_with($start, 'look:') ? substr($start, 5) : ''), 'start' => $start,
            'sectionNames' => array_column(Library::listAll(), 'name', 'key'), 'mine' => self::all($db),
            'copyable' => $db->pairs('SELECT public_id, title FROM {pages} WHERE language = ? AND deleted_at IS NULL AND (build IS NOT NULL OR build_draft IS NOT NULL) ORDER BY title', [$language])];
    }

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
