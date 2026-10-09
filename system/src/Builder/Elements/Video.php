<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Front\NewsText;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

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
            'url' => ['type' => 'odkaz', 'popisek' => 'Video address', 'vychozi' => ''],
            'title' => ['type' => 'text', 'popisek' => 'Video title (for screen readers)', 'vychozi' => '', 'max' => 200],
            'plakat' => ['type' => 'image', 'popisek' => 'Poster (image before playing)', 'vychozi' => ''],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-medium-spustit:has(.ka-medium-plakat) { position: relative; isolation: isolate; overflow: hidden; color: #fff; }
.ka-medium-plakat { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; object-fit: cover; filter: brightness(0.7); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = NewsText::player($p['obsah']['url'], $k->app->request->basePath(), $p['obsah']['title']);
        if ($html === '') {
            return $k->editor ? '<figure' . $a . ' class="ka-medium"></figure>' : '';
        }

        $poster = self::image((string) ($p['obsah']['plakat'] ?? ''), $k);
        if ($poster !== '') {
            // a file from Media: poster; YouTube and Vimeo: a custom image behind the button (the service's thumbnail would have to be downloaded from its servers)
            $html = str_contains($html, '<video ')
                ? str_replace('<video ', '<video poster="' . e($poster) . '" ', $html)
                : (string) preg_replace('/(<button type="button" class="ka-medium-spustit"[^>]*>)/', '$1<img class="ka-medium-plakat" src="' . e($poster) . '" alt="" loading="lazy">', $html, 1);
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
