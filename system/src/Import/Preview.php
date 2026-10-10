<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * The overview of an export before anything is imported (step 2 of the admin flow): counts per kind, the first TITLES
 * titles of posts and pages, and warnings – content blocks the source could not convert, images without a downloadable
 * address, duplicate slugs (two posts would fight for one address; the second gets a number). It is a plain array in the
 * import state, filled record by record by Import\Batch::analyze, so it survives the batches.
 */
final class Preview
{
    public const int TITLES = 5;

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return ['authors' => 0, 'categories' => 0, 'tags' => 0, 'articles' => [], 'pages' => [], 'media' => 0, 'images' => 0,
            'titles' => ['post' => [], 'page' => []], 'warnings' => [], 'blocks' => [], 'urls' => [], 'notes' => []];
    }

    /** @param array<string, mixed> $p */
    public static function tally(array &$p, Author|Category|Tag|Post|Media $record): void
    {
        if ($record instanceof Author) {
            $p['authors']++;
        } elseif ($record instanceof Category) {
            $p['categories']++;
        } elseif ($record instanceof Tag) {
            $p['tags']++;
        } elseif ($record instanceof Media) {
            $p['media']++;
        } else {
            $kind = $record->type === 'page' ? 'pages' : 'articles';
            $p[$kind][$record->status] = ($p[$kind][$record->status] ?? 0) + 1;
            if (count($p['titles'][$record->type]) < self::TITLES) {
                $p['titles'][$record->type][] = mb_substr($record->title !== '' ? $record->title : t('(untitled)'), 0, 120);
            }
            preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']*)#i', $record->html, $m);
            $p['images'] += count($m[1]);
            $images = $record->featureImageUrl !== '' ? [...$m[1], $record->featureImageUrl] : $m[1];
            $missing = count(array_filter($images, fn (string $url): bool => !preg_match('#^https?://#i', html_entity_decode($url, ENT_QUOTES | ENT_HTML5))));
            if ($missing > 0) {
                $p['warnings']['missing_images'] = ($p['warnings']['missing_images'] ?? 0) + $missing;
            }
            foreach ($record->warnings as $code) {
                if (str_starts_with($code, 'block:')) {
                    $block = mb_substr(substr($code, 6), 0, 40);
                    $p['blocks'][$block] = ($p['blocks'][$block] ?? 0) + 1;
                } else {
                    $p['warnings'][mb_substr($code, 0, 40)] = ($p['warnings'][mb_substr($code, 0, 40)] ?? 0) + 1;
                }
            }
            // the slug a post or page will fight for; a second one with the same gets a number (Core\Slug::makeUnique)
            $slug = $record->type . ':' . mb_substr(slugify($record->slug !== '' ? $record->slug : $record->title, 150), 0, 150);
            if (count($p['urls']) < 20000 || isset($p['urls'][$slug])) {
                $p['urls'][$slug] = ($p['urls'][$slug] ?? 0) + 1;
            }
        }
    }

    /**
     * When the whole file has been read: the duplicate slugs become one warning, the source adds its footnotes.
     *
     * @param array<string, mixed> $p
     */
    public static function finish(array &$p, Source $source): void
    {
        $duplicates = array_sum(array_map(fn (int $n): int => $n - 1, $p['urls']));
        if ($duplicates > 0) {
            $p['warnings']['duplicate_slugs'] = $duplicates;
        }
        $p['urls'] = [];
        if ($p['blocks'] !== []) {
            arsort($p['blocks']);
            $p['blocks'] = array_slice($p['blocks'], 0, 15, true);
        }
        $p['notes'] = $source->notes();
        if ($source->site()['url'] === '') {
            $p['warnings']['no_site_url'] = 1;
        }
    }

    /** A warning code in words, for the preview. */
    public static function describe(string $code, int $count): string
    {
        return match ($code) {
            'missing_images' => t('%s images have no absolute address (http…) and cannot be downloaded; they stay as they are.', $count),
            'duplicate_slugs' => t('%s posts or pages share an address with another one – the second gets a number (slug-2).', $count),
            'no_site_url' => t('The export does not say the address of the old site. Enter it below – images are downloaded from it and the old addresses redirect from it.'),
            default => $code . ' (' . $count . ')',
        };
    }
}
