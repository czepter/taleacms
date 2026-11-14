<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;
use Talea\Builder\HtmlWidgets;

/**
 * What the website import (Core\WebImport) does with the widgets Builder\HtmlWidgets took out of an old page: the ones the builder cannot
 * make (a form a script draws, a frame of an unknown service, a video file) get a visible bracketed placeholder in the site language
 * and a line under "attention"; the others are counted when they arrived as elements; the page's stored text gets their text.
 */
final class WebImportWidgets
{
    /** Widget kinds that become a placeholder and need the owner. */
    private const array PLACEHOLDER = ['unreadable_form' => 'unreadable_form', 'video_file' => 'video_file', 'frame' => 'frame'];

    /** The lines under "attention", by kind (English; the admin shows them through t(), MCP as they are). */
    public const array ATTENTION = [
        'unreadable_form' => 'A form whose fields a script draws could not be read: a placeholder marks its place – add a form with the same fields.',
        'map' => 'A map could not be read: add a Map element with the address.',
        'frame' => 'An embedded page from %s was left out: a placeholder marks its place.',
        'video_file' => 'A video file was not brought over: upload it to Media and add a Video element.',
        'document' => 'The document %s could not be brought over: its link keeps the old address.',
        'form_off' => 'A form was found, but Enquiries are switched off: a placeholder marks its place.',
    ];

    /** The element types of a build that count as "kept" from an old page. */
    private const array KEPT = ['form', 'faq', 'tabs', 'video', 'map', 'embed', 'counter'];

    /**
     * The widgets of a page ready for the converter: placeholders in their place, and what needs the owner noted in the state.
     *
     * @param list<array{kind: string, html: string, host: string}> $widgets
     * @param string $content the page's HTML with the markers – only widgets whose marker is still there count
     * @param array<string, mixed> $state the import state (attention)
     * @return list<array{kind: string, html: string, host: string}>
     */
    public static function prepare(array $widgets, string $content, string $language, bool $enquiries, string $oldPath, array &$state): array
    {
        foreach ($widgets as $i => $w) {
            if (!str_contains($content, '<talea-widget title="' . $i . '"></talea-widget>')) {
                $widgets[$i]['html'] = ''; // taken out with a part of the page that is left out
                $widgets[$i]['kind'] = '';
                continue;
            }
            if (in_array($w['kind'], ['accordion', 'tabs'], true)) {
                // a form or frame inside an answer or a panel is met like one on the page itself
                [$widgets[$i]['html'], $replaced] = self::nested($widgets[$i]['html'], $enquiries, $language);
                foreach ($replaced as $found) {
                    self::note($state, $found['reason'], $oldPath, $found['host']);
                }
                continue;
            }
            $reason = $w['kind'] === 'form' && !$enquiries ? 'form_off' : (self::PLACEHOLDER[$w['kind']] ?? null);
            if ($reason === null) {
                continue;
            }
            $widgets[$i]['html'] = '<p>' . e(self::placeholder($reason, $w['host'], $language)) . '</p>';
            self::note($state, $reason, $oldPath, $w['host']);
        }

        return $widgets;
    }

    /** @param array<string, mixed> $state */
    private static function note(array &$state, string $reason, string $oldPath, string $host): void
    {
        if (count($state['attention'] ?? []) < 100) {
            $state['attention'][] = ['kind' => $reason, 'path' => $oldPath, 'host' => $host];
        }
    }

    /**
     * The forms and frames inside the HTML of an accordion or tabs widget that cannot be brought over, each replaced by its placeholder.
     *
     * @return array{0: string, 1: list<array{reason: string, host: string}>} the HTML, and what was replaced
     */
    private static function nested(string $html, bool $enquiries, string $language): array
    {
        if (preg_match('/<(form|iframe|video)\b/i', $html) !== 1) {
            return [$html, []];
        }
        $found = [];
        $result = Html::transform($html, function (\Dom\HTMLElement $body, \Dom\HTMLDocument $doc) use (&$found, $enquiries, $language): void {
            foreach (iterator_to_array($body->querySelectorAll('form, iframe, video')) as $el) {
                if (!$body->contains($el)) {
                    continue;
                }
                $kind = HtmlWidgets::kind($el);
                $reason = $kind === 'form' && !$enquiries ? 'form_off' : ($kind !== null ? (self::PLACEHOLDER[$kind] ?? null) : null);
                if ($reason === null) {
                    continue;
                }
                $src = trim((string) ($el->getAttribute('src') ?: $el->getAttribute('data-src') ?: $el->getAttribute('data-lazy-src')));
                $host = (string) preg_replace('/[^a-z0-9.-]/', '', strtolower((string) parse_url($src, PHP_URL_HOST)));
                $placeholder = $doc->createElement('p');
                $placeholder->textContent = self::placeholder($reason, $host, $language);
                $el->replaceWith($placeholder);
                $found[] = ['reason' => $reason, 'host' => $host];
            }
        });

        return [$found === [] ? $html : $result, $found];
    }

    /** The bracketed text that marks the place of a widget that could not be brought over, in the language of the site. */
    private static function placeholder(string $reason, string $host, string $language): string
    {
        return Language::runWith($language, fn (): string => match ($reason) {
            'unreadable_form' => t('[Form from the old page: its fields could not be read. Add a form with the same fields.]'),
            'frame' => t('[Embedded content from %s: add it again with an Embed element.]', $host),
            'video_file' => t('[Video from the old page: upload it to Media and add a Video element.]'),
            default => t('[A form was here: switch on Enquiries and add a form.]'),
        });
    }

    /**
     * The page text with each marker replaced by the text of its widget (the stored text is the content without layout: search, the
     * return to text and the news text use it).
     *
     * @param list<array{kind: string, html: string, host: string}> $widgets
     */
    public static function text(string $html, array $widgets): string
    {
        foreach ($widgets as $i => $w) {
            $marker = '<talea-widget title="' . $i . '"></talea-widget>';
            if (!str_contains($html, $marker)) {
                continue;
            }
            $html = str_replace($marker, $w['html'] === '' ? '' : '<p>' . e(Build::asText(HtmlConverter::convert($w['html'], false)['build'])) . '</p>', $html);
        }

        return $html;
    }

    /**
     * What arrived as elements, and the maps that did not: counts by element type for the result, and the number of map widgets without a Map element.
     *
     * @param array<string, mixed> $build the sanitized build
     * @param list<array{kind: string, html: string, host: string}> $widgets
     * @return array{kept: array<string, int>, lostMaps: int}
     */
    public static function kept(array $build, array $widgets): array
    {
        $kept = [];
        $walk = function (array $children) use (&$walk, &$kept): void {
            foreach ($children as $el) {
                if (in_array($el['type'] ?? '', self::KEPT, true)) {
                    $kept[$el['type']] = ($kept[$el['type']] ?? 0) + 1;
                }
                $walk(is_array($el['children'] ?? null) ? $el['children'] : []);
            }
        };
        $walk($build['children'] ?? []);
        $maps = count(array_filter($widgets, fn (array $w): bool => $w['kind'] === 'map'));

        return ['kept' => $kept, 'lostMaps' => max(0, $maps - ($kept['map'] ?? 0))];
    }
}
