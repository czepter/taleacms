<?php

declare(strict_types=1);

namespace Kaleta\Import;

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
        return ['autori' => 0, 'rubriky' => 0, 'stitky' => 0, 'clanky' => [], 'pages' => [], 'media' => 0, 'obrazky' => 0,
            'tituly' => ['post' => [], 'page' => []], 'varovani' => [], 'bloky' => [], 'adresy' => [], 'poznamky' => []];
    }

    /** @param array<string, mixed> $p */
    public static function tally(array &$p, Author|Category|Tag|Post|Media $record): void
    {
        if ($record instanceof Author) {
            $p['autori']++;
        } elseif ($record instanceof Category) {
            $p['rubriky']++;
        } elseif ($record instanceof Tag) {
            $p['stitky']++;
        } elseif ($record instanceof Media) {
            $p['media']++;
        } else {
            $kind = $record->type === 'page' ? 'pages' : 'clanky';
            $p[$kind][$record->status] = ($p[$kind][$record->status] ?? 0) + 1;
            if (count($p['tituly'][$record->type]) < self::TITLES) {
                $p['tituly'][$record->type][] = mb_substr($record->title !== '' ? $record->title : t('(untitled)'), 0, 120);
            }
            preg_match_all('#<img\b[^>]*\bsrc=["\']([^"\']*)#i', $record->html, $m);
            $p['obrazky'] += count($m[1]);
            $images = $record->featureImageUrl !== '' ? [...$m[1], $record->featureImageUrl] : $m[1];
            $missing = count(array_filter($images, fn (string $url): bool => !preg_match('#^https?://#i', html_entity_decode($url, ENT_QUOTES | ENT_HTML5))));
            if ($missing > 0) {
                $p['varovani']['missing_images'] = ($p['varovani']['missing_images'] ?? 0) + $missing;
            }
            foreach ($record->warnings as $code) {
                if (str_starts_with($code, 'block:')) {
                    $block = mb_substr(substr($code, 6), 0, 40);
                    $p['bloky'][$block] = ($p['bloky'][$block] ?? 0) + 1;
                } else {
                    $p['varovani'][mb_substr($code, 0, 40)] = ($p['varovani'][mb_substr($code, 0, 40)] ?? 0) + 1;
                }
            }
            // the slug a post or page will fight for; a second one with the same gets a number (Core\Slug::makeUnique)
            $slug = $record->type . ':' . mb_substr(slugify($record->slug !== '' ? $record->slug : $record->title, 150), 0, 150);
            if (count($p['adresy']) < 20000 || isset($p['adresy'][$slug])) {
                $p['adresy'][$slug] = ($p['adresy'][$slug] ?? 0) + 1;
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
        $duplicates = array_sum(array_map(fn (int $n): int => $n - 1, $p['adresy']));
        if ($duplicates > 0) {
            $p['varovani']['duplicate_slugs'] = $duplicates;
        }
        $p['adresy'] = [];
        if ($p['bloky'] !== []) {
            arsort($p['bloky']);
            $p['bloky'] = array_slice($p['bloky'], 0, 15, true);
        }
        $p['poznamky'] = $source->notes();
        if ($source->site()['adresa'] === '') {
            $p['varovani']['no_site_url'] = 1;
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
