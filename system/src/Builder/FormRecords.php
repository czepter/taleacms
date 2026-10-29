<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Builder\Elements\Form;
use Talea\Builder\Elements\FormEmbed;
use Talea\Core\App;
use Talea\Core\Db;

/**
 * Forms as records (table tl_forms, docs/specs/form-builder.md). A form's build holds exactly one Form element – the fields, the
 * logic and everything after sending; pages, site parts, pop-ups and item templates place it with a Form placement element
 * (Elements\FormEmbed). resolve() turns a placement into the Form element the rest of the code knows; adopt() turns a Form
 * element written inside another build (a starter section, MCP, an import) into a record when that build is published.
 */
final class FormRecords
{
    /** Widths a placement may give a field, in columns of six: full, two thirds, half, one third. */
    public const array WIDTHS = [6 => 'Full width', 4 => 'Two thirds', 3 => 'Half', 2 => 'One third'];

    /** @var array<string, array<string, mixed>|null> forms loaded in this request, by public id */
    private static array $loaded = [];

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return $db->all('SELECT * FROM {forms} ORDER BY name, form_id');
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $id): ?array
    {
        return $id > 0 ? $db->one('SELECT * FROM {forms} WHERE form_id = ?', [$id]) : null;
    }

    /** @return array<string, mixed>|null */
    public static function byPublicId(Db $db, string $publicId): ?array
    {
        if (!array_key_exists($publicId, self::$loaded)) {
            self::$loaded[$publicId] = \Talea\Core\Uuid::valid($publicId) ? $db->one('SELECT * FROM {forms} WHERE public_id = ?', [$publicId]) : null;
        }

        return self::$loaded[$publicId];
    }

    /** Forgets what this request loaded (after a write, and between the tests' requests). */
    public static function forget(): void
    {
        self::$loaded = [];
    }

    /**
     * The form's one Form element: the first one in the build, wherever it is. @param array<string, mixed>|null $build
     *
     * @return array<string, mixed>|null
     */
    public static function definition(?array $build): ?array
    {
        $find = function (array $children) use (&$find): ?array {
            foreach ($children as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['type'] ?? '') === Form::TYPE) {
                    return $node;
                }
                if (($found = $find((array) ($node['children'] ?? []))) !== null) {
                    return $found;
                }
            }

            return null;
        };

        return $find((array) ($build['children'] ?? []));
    }

    /** What a form's build may hold: its first Form element alone (nothing else is saved). @return array<string, mixed> */
    public static function definitionBuild(?array $build): array
    {
        $form = self::definition($build);

        return ['v' => Build::VERSION, 'children' => $form === null ? [] : [$form]];
    }

    /** A new form's build from a starter (Library sections offer the same ones). @return array<string, mixed> */
    public static function starterBuild(string $starter): array
    {
        $field = static fn (string $label, string $type, bool $required = false, array $more = []): array => ['label' => t($label), 'type' => $type, 'required' => $required] + $more;
        $consent = $field('I agree to the processing of my personal data for the purpose of handling this enquiry.', 'checkbox', true);
        $fields = match ($starter) {
            'download' => [$field('Your name', 'text', true), $field('Email', 'email', true), $consent],
            'empty' => [$field('Email', 'email', true)],
            default => [$field('Your name', 'text', true), $field('Email', 'email', true), $field('Phone', 'tel'), $field('How can we help you?', 'textarea', true), $consent],
        };
        [$build] = Build::sanitize(['v' => Build::VERSION, 'children' => [Build::fresh(Form::TYPE, ['fields' => $fields] + ($starter === 'download' ? ['button_text' => t('Send me the file'), 'confirmation' => true] : []))]], true);

        return $build;
    }

    /**
     * A placement as the Form element it shows: the form's content (published, or its draft for a draft preview and the builder's
     * canvas) with the placement's thank-you page, button text and field widths, under the placement's id, anchor and style – so
     * the page posts the placement's id and the answer returns to it. Null when the form does not exist or has nothing to show.
     *
     * @param array<string, mixed> $placement a form_embed element
     * @return array<string, mixed>|null
     */
    public static function resolve(Db $db, array $placement, bool $draft = false): ?array
    {
        $row = self::byPublicId($db, (string) ($placement['content']['form'] ?? ''));
        $definition = $row === null ? null : self::definition(Build::fromJson($draft ? ($row['build_draft'] ?? $row['build']) : $row['build']));
        if ($definition === null) {
            return null;
        }
        $own = (array) ($placement['content'] ?? []);
        $content = (array) ($definition['content'] ?? []);
        if (trim((string) ($own['thank_you_page'] ?? '')) !== '') {
            $content['thank_you_page'] = (string) $own['thank_you_page'];
        }
        if (trim((string) ($own['button_text'] ?? '')) !== '') {
            $content['button_text'] = (string) $own['button_text'];
        }
        $content['widths'] = self::widths($own['widths'] ?? []);

        return ['id' => (string) ($placement['id'] ?? ''), 'type' => Form::TYPE, 'tag' => 'form', 'content' => $content, 'form_id' => (int) $row['form_id']]
            + array_intersect_key($placement, array_flip(['anchor', 'style', 'classes', 'label']));
    }

    /** A placement's widths as stored: field key => 2|3|4 (full width is no entry). @return array<string, int> */
    public static function widths(mixed $input): array
    {
        $out = [];
        foreach (is_array($input) ? $input : [] as $key => $span) {
            $span = (int) $span;
            if (preg_match('/^k[a-z0-9]{4}$/', (string) $key) === 1 && isset(self::WIDTHS[$span]) && $span !== 6 && count($out) < 40) {
                $out[(string) $key] = $span;
            }
        }

        return $out;
    }

    /**
     * Where a form is placed: [the build it is in (as the admin names it), its source, the placement's id, published or only in a
     * draft], read from the builds of pages, site parts, pop-ups, item templates and components.
     *
     * @return list<array{where: string, source: string, element: string, state: string, edit: string, widths: array<string, int>}>
     */
    public static function placements(App $app, string $publicId): array
    {
        $out = [];
        foreach (self::stored($app->db(), '%"form":"' . $publicId . '"%') as $place) {
            foreach (['live' => $place['build'], 'draft' => $place['build_draft']] as $state => $json) {
                foreach (\Talea\Front\Forms::elements($app->db(), Build::fromJson($json), FormEmbed::TYPE, false) as $p) {
                    if (($p['content']['form'] ?? '') === $publicId) {
                        $key = $place['source'] . '|' . $p['id'];
                        $out[$key] ??= ['where' => $place['where'], 'source' => $place['source'], 'element' => (string) $p['id'], 'state' => $state, 'edit' => $place['edit'], 'widths' => self::widths($p['content']['widths'] ?? [])];
                    }
                }
            }
        }

        return array_values($out);
    }

    /**
     * Turns the Form elements written inside a build into records and puts placements in their place (published builds only:
     * Publisher calls it with the build it is about to publish). The same element adopted before updates its record. Returns
     * the build as JSON (unchanged when it had no Form element).
     *
     * @param string $origin where the build is: page:12, part:header::, popup:3, collection:5, collection:5:de, component:7
     * @param string $source the source its enquiries carry ('' for a component: they carry the page's)
     */
    public static function adopt(App $app, string $origin, string $source, ?string $json): ?string
    {
        if ($json === null || !str_contains($json, '"type":"' . Form::TYPE . '"')) {
            return $json;
        }
        $build = Build::fromJson($json);
        if ($build === null) {
            return $json;
        }
        $db = $app->db();
        $build['children'] = self::replace($build['children'] ?? [], function (array $node) use ($app, $db, $origin, $source): array {
            $record = self::store($app, $origin . '|' . $node['id'], $node, true);
            if ($source !== '') {
                $db->run('UPDATE {enquiries} SET form_id = ? WHERE source = ? AND element = ? AND form_id IS NULL', [$record['form_id'], $source, (string) $node['id']]);
            }

            return self::placement($node, (string) $record['public_id']);
        });

        return Build::toJson($build);
    }

    /**
     * adopt() for a build as it is stored, with its draft: the draft's Form elements that are already published (the same id)
     * become the same placement, their changes the form's draft. For builds written without Publisher (an import, a kit): the
     * Forms list calls it.
     *
     * @return array{0: ?string, 1: ?string} [published, draft]
     */
    public static function adoptStored(App $app, string $origin, string $source, ?string $published, ?string $draft): array
    {
        $before = Build::fromJson($published);
        $after = self::adopt($app, $origin, $source, $published);
        if ($after === $published || $draft === null || !str_contains($draft, '"type":"' . Form::TYPE . '"')) {
            return [$after, $draft];
        }
        $adopted = [];
        foreach (\Talea\Front\Forms::elements($app->db(), $before, Form::TYPE, false) as $p) {
            $adopted[(string) $p['id']] = true;
        }
        $build = Build::fromJson($draft);
        if ($build === null) {
            return [$after, $draft];
        }
        $build['children'] = self::replace($build['children'] ?? [], function (array $node) use ($app, $origin, $adopted): ?array {
            if (!isset($adopted[(string) $node['id']])) {
                return null; // only in the draft: adopted when it is published
            }
            $record = self::store($app, $origin . '|' . $node['id'], $node, false);

            return self::placement($node, (string) $record['public_id']);
        });

        return [$after, Build::toJson($build)];
    }

    /**
     * Every stored build that may hold a form, with its origin, the source its enquiries carry, how the admin names it and the
     * address of its builder. $like narrows the rows (build or draft LIKE it).
     *
     * @return list<array{table: string, key: array<string, mixed>, origin: string, source: string, where: string, edit: string, build: ?string, build_draft: ?string}>
     */
    public static function stored(Db $db, string $like): array
    {
        $out = [];
        $where = ' WHERE (build LIKE ? OR build_draft LIKE ?)';
        $params = [$like, $like];
        $url = static fn (string $query): string => 'admin.php?' . $query;
        foreach ($db->all('SELECT page_id, public_id, title, build, build_draft FROM {pages}' . $where . ' AND deleted_at IS NULL ORDER BY sort_order, title', $params) as $r) {
            $out[] = ['table' => 'pages', 'key' => ['page_id' => $r['page_id']], 'origin' => 'page:' . $r['page_id'], 'source' => 'page:' . $r['page_id'], 'where' => (string) $r['title'],
                'edit' => $url('module=pages&action=builder&id=' . $r['public_id']), 'build' => $r['build'], 'build_draft' => $r['build_draft']];
        }
        foreach ($db->all('SELECT type, language, variant, name, build, build_draft FROM {site_parts}' . $where . ' ORDER BY type, language, variant', $params) as $r) {
            $source = 'part:' . $r['type'] . ':' . $r['language'] . ($r['variant'] !== '' ? ':' . $r['variant'] : '');
            $out[] = ['table' => 'site_parts', 'key' => ['type' => $r['type'], 'language' => $r['language'], 'variant' => $r['variant']], 'origin' => $source, 'source' => $source,
                'where' => trim(t(SiteParts::TYPES[$r['type']][0] ?? (string) $r['type']) . ' ' . (string) $r['name'] . ' ' . strtoupper((string) $r['language'])),
                'edit' => $url('module=parts&action=builder&type=' . rawurlencode((string) $r['type']) . ($r['variant'] !== '' ? '&variant=' . rawurlencode((string) $r['variant']) : '') . '&language=' . rawurlencode((string) $r['language'])),
                'build' => $r['build'], 'build_draft' => $r['build_draft']];
        }
        foreach ($db->all('SELECT popup_id, public_id, name, build, build_draft FROM {popups}' . $where . ' ORDER BY sort_order, name', $params) as $r) {
            $out[] = ['table' => 'popups', 'key' => ['popup_id' => $r['popup_id']], 'origin' => 'popup:' . $r['popup_id'], 'source' => 'popup:' . $r['popup_id'], 'where' => t('Pop-up') . ': ' . $r['name'],
                'edit' => $url('module=popups&action=builder&id=' . $r['public_id']), 'build' => $r['build'], 'build_draft' => $r['build_draft']];
        }
        foreach ($db->all('SELECT collection_id, public_id, name, build, build_draft FROM {collections}' . $where . ' ORDER BY name', $params) as $r) {
            $out[] = ['table' => 'collections', 'key' => ['collection_id' => $r['collection_id']], 'origin' => 'collection:' . $r['collection_id'], 'source' => 'collection:' . $r['collection_id'],
                'where' => t('Item template') . ': ' . $r['name'], 'edit' => $url('module=collections&action=builder&id=' . $r['public_id']), 'build' => $r['build'], 'build_draft' => $r['build_draft']];
        }
        foreach ($db->all('SELECT t.collection_id, t.language, c.public_id, c.name, t.build, t.build_draft FROM {collection_templates} t JOIN {collections} c ON c.collection_id = t.collection_id WHERE (t.build LIKE ? OR t.build_draft LIKE ?) ORDER BY c.name, t.language', $params) as $r) {
            $out[] = ['table' => 'collection_templates', 'key' => ['collection_id' => $r['collection_id'], 'language' => $r['language']], 'origin' => 'collection:' . $r['collection_id'] . ':' . $r['language'],
                'source' => 'collection:' . $r['collection_id'], 'where' => t('Item template') . ': ' . $r['name'] . ' ' . strtoupper((string) $r['language']),
                'edit' => $url('module=collections&action=builder&id=' . $r['public_id'] . '&language=' . rawurlencode((string) $r['language'])), 'build' => $r['build'], 'build_draft' => $r['build_draft']];
        }
        foreach ($db->all('SELECT component_id, public_id, name, build, build_draft FROM {components}' . $where . ' ORDER BY name', $params) as $r) {
            $out[] = ['table' => 'components', 'key' => ['component_id' => $r['component_id']], 'origin' => 'component:' . $r['component_id'], 'source' => '', 'where' => t('Component') . ': ' . $r['name'],
                'edit' => $url('module=components&action=builder&id=' . $r['public_id']), 'build' => $r['build'], 'build_draft' => $r['build_draft']];
        }

        return $out;
    }

    /** Adopts the Form elements of every stored published build (the Forms list calls it). Returns how many builds changed. */
    public static function adoptAll(App $app): int
    {
        $changed = 0;
        foreach (self::stored($app->db(), '%"type":"' . Form::TYPE . '"%') as $place) {
            [$published, $draft] = self::adoptStored($app, $place['origin'], $place['source'], $place['build'], $place['build_draft']);
            if ($published !== $place['build'] || $draft !== $place['build_draft']) {
                $app->db()->update($place['table'], ['build' => $published, 'build_draft' => $draft], $place['key']);
                $changed++;
            }
        }
        if ($changed > 0) {
            \Talea\Front\Cache::clear();
        }

        return $changed;
    }

    /** The placement that takes a written Form element's place: the same id, anchor, style and classes. @return array<string, mixed> */
    private static function placement(array $node, string $publicId): array
    {
        return ['id' => (string) $node['id'], 'type' => FormEmbed::TYPE, 'tag' => 'div',
            'content' => ['form' => $publicId, 'thank_you_page' => (string) ($node['content']['thank_you_page'] ?? ''), 'button_text' => '', 'widths' => []]]
            + array_intersect_key($node, array_flip(['anchor', 'style', 'classes', 'label']));
    }

    /**
     * Creates or updates the record a written Form element becomes: published ($published) or as the form's draft.
     *
     * @return array<string, mixed> the record
     */
    private static function store(App $app, string $origin, array $node, bool $published): array
    {
        $db = $app->db();
        $element = ['id' => (string) $node['id'], 'type' => Form::TYPE, 'tag' => 'form', 'content' => (array) ($node['content'] ?? [])];
        $json = Build::toJson(['v' => Build::VERSION, 'children' => [$element]]);
        $name = mb_substr(trim((string) ($node['content']['name'] ?? '')), 0, 120) ?: t('Form');
        $row = $db->one('SELECT * FROM {forms} WHERE origin = ?', [mb_substr($origin, 0, 80)]);
        $now = date('Y-m-d H:i:s');
        if ($row === null) {
            $id = $db->insert('forms', ['name' => $name, 'origin' => mb_substr($origin, 0, 80), 'created_at' => $now, 'updated_at' => $now] + ($published ? ['build' => $json] : ['build_draft' => $json]));
            $row = (array) self::byId($db, $id);
        } elseif ($published && $row['build'] !== $json) {
            Publisher::version($app, ['part' => 'form:' . (int) $row['form_id']], $row['build'], $json, $row['updated_at']);
            $db->update('forms', ['build' => $json, 'build_draft' => null, 'name' => $name, 'updated_at' => $now], ['form_id' => $row['form_id']]);
        } elseif (!$published && $row['build'] !== $json) {
            $db->update('forms', ['build_draft' => $json], ['form_id' => $row['form_id']]);
        }
        self::forget();

        return $row;
    }

    /**
     * Walks a build's children and replaces each Form element with what $with returns for it (null keeps it).
     *
     * @param list<mixed> $children
     * @return list<mixed>
     */
    private static function replace(array $children, callable $with): array
    {
        foreach ($children as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === Form::TYPE && (string) ($node['id'] ?? '') !== '') {
                $children[$i] = $with($node) ?? $node;
                continue;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $children[$i]['children'] = self::replace($node['children'], $with);
            }
        }

        return $children;
    }
}
