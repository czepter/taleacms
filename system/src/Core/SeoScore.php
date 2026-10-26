<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\Collections;

/**
 * The stored SEO score of a page, news item or collection item: input() reads the row into ContentCheck::analyse(),
 * refresh() keeps seo_score / seo_checked_at current after a save or a publish. A score never breaks a save.
 */
final class SeoScore
{
    /** @var array<string, array{0: string, 1: string}> kind => table, key column */
    private const array TABLES = ['page' => ['pages', 'page_id'], 'news' => ['news', 'news_id'], 'item' => ['collection_items', 'item_id']];

    /** @param array<string, mixed> $row the full row @return array<string, mixed> analyse()'s input */
    public static function input(App $app, string $kind, array $row): array
    {
        $db = $app->db();
        [$table, $key] = self::TABLES[$kind];
        $outline = false;
        if ($kind === 'page') {
            $input = ContentCheck::pageInput($row);
            $outline = self::hasOutline(Build::fromJson($row['build_draft'] ?? $row['build'] ?? null)['children'] ?? []);
        } elseif ($kind === 'news') {
            $input = ContentCheck::newsInput($row);
            $outline = $app->settings()->get('article_outline') === '1';
        } else {
            $collection = Collections::byId($db, (int) $row['collection_id']);
            $data = json_decode((string) $row['data'], true) ?: [];
            $html = '';
            foreach ($collection['fields'] ?? [] as $field) {
                $value = (string) ($data[$field['key']] ?? '');
                $html .= match ($field['type']) {
                    'html' => $value . "\n",
                    'lines' => '<p>' . nl2br(htmlspecialchars($value)) . "</p>\n",
                    default => '',
                };
            }
            $input = ['title' => (string) ($row['seo_title'] !== '' ? $row['seo_title'] : $row['name']), 'description' => (string) $row['description'], 'html' => $html, 'title_is_h1' => true];
        }
        $keywords = SeoFields::keywords((string) $row['focus_keywords']);
        $host = (string) parse_url($app->settings()->get('site_url') ?: $app->request->origin(), PHP_URL_HOST);

        return $input + ['keywords' => $keywords, 'slug' => (string) $row['slug'], 'site_host' => $host, 'has_outline' => $outline,
            'keyword_taken' => $keywords !== [] && self::taken($db, $keywords[0], $table, (int) $row[$key])];
    }

    /**
     * What seo/checks.php shows of a saved row: the scored analysis, the placeholders still in the content and the keywords.
     *
     * @param array<string, mixed> $row the full row @return array<string, mixed>
     */
    public static function panel(App $app, string $kind, array $row): array
    {
        $input = self::input($app, $kind, $row);

        return ['analysis' => ContentCheck::analyse($input), 'keywords' => $input['keywords'],
            'samples' => array_values(array_filter(ContentCheck::run($input), fn (array $r): bool => $r['check'] === 'sample_content' && !$r['ok']))];
    }

    /** Stores the score (null without a focus keyword) and the time of the check; never throws. */
    public static function refresh(App $app, string $kind, int $id): ?int
    {
        [$table, $key] = self::TABLES[$kind];
        $db = $app->db();
        $score = null;
        try {
            $row = $db->one("SELECT * FROM {{$table}} WHERE $key = ?", [$id]);
            if ($row === null) {
                return null;
            }
            $score = ContentCheck::analyse(self::input($app, $kind, $row))['score'];
        } catch (\Throwable $e) {
            error_log('Talea: SEO score of ' . $kind . ' ' . $id . ' failed: ' . $e->getMessage());
        }
        $db->run("UPDATE {{$table}} SET seo_score = ?, seo_checked_at = ? WHERE $key = ?", [$score, date('Y-m-d H:i:s'), $id]);

        return $score;
    }

    /** Every live row, for the site check. @return int rows updated */
    public static function refreshAll(App $app): int
    {
        $count = 0;
        foreach (self::TABLES as $kind => [$table, $key]) {
            foreach ($app->db()->all("SELECT $key AS id FROM {{$table}} WHERE deleted_at IS NULL") as $row) {
                self::refresh($app, $kind, (int) $row['id']);
                $count++;
            }
        }

        return $count;
    }

    /** Another live row whose FIRST focus keyword is the same (case-insensitive). */
    private static function taken(Db $db, string $keyword, string $ownTable, int $ownId): bool
    {
        $like = $db->dialect()->likeInsensitive('focus_keywords');
        $pattern = addcslashes($keyword, '%_\\') . '%';
        foreach (self::TABLES as [$table, $key]) {
            $rows = $db->all("SELECT focus_keywords FROM {{$table}} WHERE deleted_at IS NULL AND $like" . ($table === $ownTable ? " AND $key <> ?" : ''), $table === $ownTable ? [$pattern, $ownId] : [$pattern]);
            foreach ($rows as $row) {
                if (mb_strtolower(trim(explode("\n", (string) $row['focus_keywords'])[0])) === mb_strtolower($keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $elements */
    private static function hasOutline(array $elements): bool
    {
        foreach ($elements as $element) {
            if (($element['type'] ?? '') === 'outline' || self::hasOutline($element['children'] ?? [])) {
                return true;
            }
        }

        return false;
    }
}
