<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Front\NewsText;
use Talea\Builder\Context;
use Talea\Builder\Element;

/** Video from YouTube or Vimeo, or a file from Media. The third-party player loads only after a click (privacy, speed). */
final class Video extends Element
{
    public const string TYPE = 'video';
    public const string NAME = 'Video';
    public const string DESCRIPTION = 'YouTube, Vimeo or a video from Media – loads only after a click.';
    public const string ICON = 'video';
    public const array HTML_TAGS = ['figure'];

    public static function properties(): array
    {
        return [
            'url' => ['type' => 'link', 'label' => 'Video address', 'default' => ''],
            'title' => ['type' => 'text', 'label' => 'Video title (for screen readers)', 'default' => '', 'max' => 200],
            'poster' => ['type' => 'image', 'label' => 'Poster (image before playing)', 'default' => ''],
        ];
    }

    public static function baseCss(): string
    {
        return '.tl-media-start:has(.tl-media-poster) { position: relative; isolation: isolate; overflow: hidden; color: #fff; }
.tl-media-poster { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; filter: brightness(0.7); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = NewsText::player($p['content']['url'], $k->app->request->basePath(), $p['content']['title']);
        if ($html === '') {
            return $k->editor ? '<figure' . $a . ' class="tl-media"></figure>' : '';
        }

        $poster = self::image((string) ($p['content']['poster'] ?? ''), $k);
        if ($poster !== '') {
            // a file from Media: poster; YouTube and Vimeo: a custom image behind the button (the service's thumbnail would have to be downloaded from its servers)
            $html = str_contains($html, '<video ')
                ? str_replace('<video ', '<video poster="' . e($poster) . '" ', $html)
                : (string) preg_replace('/(<button type="button" class="tl-media-start"[^>]*>)/', '$1<img class="tl-media-poster" src="' . e($poster) . '" alt="" loading="lazy">', $html, 1);
        }

        // the element's attributes (id, classes) are added to the player's first tag
        return (string) preg_replace_callback('/^<(figure|div) class="([^"]*)"/', fn (array $m): string => '<' . $m[1] . Text::withClass($a, $m[2]), $html, 1);
    }

    /** Image url from Media (from the installation root) or https. */
    private static function image(string $url, Context $k): string
    {
        if (preg_match('#^https://#i', $url)) {
            return $url;
        }

        return preg_match('#^/?(media/[A-Za-z0-9/_.-]{1,300})$#', $url, $m) && !str_contains($m[1], '..') ? $k->app->request->basePath() . '/' . $m[1] : '';
    }
}
