<?php

declare(strict_types=1);

namespace Talea\Core;

use Dom\Element;
use Talea\Builder\HtmlWidgets;

/**
 * A section of an old page as clean HTML for build_from_html (read_old_page with format "semantic"): the widgets (Builder\HtmlWidgets) in the
 * shapes the converter reads, no scripts, icons, tracking pixels, builder wrappers, classes or styles – just the headings, text, lists, images and links
 * in a <section> (<header> or <footer> for those roles) with absolute addresses.
 */
final class SemanticHtml
{
    /** Elements that are dropped with everything inside. */
    private const string DROP = 'script, style, noscript, template, svg, link, meta, nav, button, iframe, canvas, object, embed';

    /** What a wrapper is called when it stands for nothing but its children. */
    private const array WRAPPERS = ['div', 'span'];

    /** The words that stand in for what cannot be read (the owner is told to add it). */
    private const array PLACEHOLDER = [
        'unreadable_form' => '[form whose fields a script draws – add a form]',
        'video_file' => '[video file – upload it to Media and add a Video element]',
        'frame' => '[embedded content from %s – add an Embed element]',
    ];

    public static function section(Element $section, string $url, string $role = 'section'): string
    {
        $copy = $section->cloneNode(true);
        if (!$copy instanceof Element) {
            return '';
        }
        $widgets = HtmlWidgets::extract($copy);
        foreach (iterator_to_array($copy->querySelectorAll(self::DROP)) as $drop) {
            if ($copy->contains($drop)) {
                $drop->remove();
            }
        }
        self::icons($copy);
        self::pixels($copy);
        self::flatten($copy);
        foreach (iterator_to_array($copy->querySelectorAll('*')) as $node) {
            $keep = strtolower($node->localName) === 'talea-widget' ? ['title'] : ['href', 'src', 'alt', 'title'];
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), $keep, true)) {
                    $node->removeAttribute($attribute->name);
                } elseif (in_array(strtolower($attribute->name), ['href', 'src'], true) && strtolower($node->localName) !== 'talea-widget') {
                    $node->setAttribute($attribute->name, WebImport::absolute(trim($attribute->value), $url) ?: $attribute->value);
                }
            }
        }
        $html = trim((string) preg_replace(['/<!--.*?-->/s', '/\s+/u'], ['', ' '], $copy->innerHTML));
        foreach ($widgets as $i => $widget) {
            $replacement = $widget['html'] !== '' ? $widget['html'] : '<p>' . e(sprintf(self::PLACEHOLDER[$widget['kind']] ?? '[' . $widget['kind'] . ']', $widget['host'])) . '</p>';
            $html = str_replace('<talea-widget title="' . $i . '"></talea-widget>', $replacement, $html);
        }
        $tag = in_array($role, ['header', 'footer'], true) ? $role : 'section';

        return '<' . $tag . '>' . $html . '</' . $tag . '>';
    }

    /** Icons: <i> elements and spans without text (an icon font draws them). */
    private static function icons(Element $root): void
    {
        foreach (iterator_to_array($root->querySelectorAll('i, span, em')) as $el) {
            if ($root->contains($el) && trim($el->textContent) === '' && $el->querySelector('img, picture') === null) {
                $el->remove();
            }
        }
    }

    /** Tracking pixels: an image of two pixels or less, one that is hidden, or one whose address has no path. */
    private static function pixels(Element $root): void
    {
        foreach (iterator_to_array($root->querySelectorAll('img')) as $img) {
            $src = trim((string) $img->getAttribute('src'));
            $size = fn (string $a): bool => $img->hasAttribute($a) && is_numeric($img->getAttribute($a)) && (float) $img->getAttribute($a) <= 2;
            $hidden = preg_match('/display\s*:\s*none|visibility\s*:\s*hidden/i', (string) $img->getAttribute('style')) === 1;
            if ($src === '' || $size('width') || $size('height') || $hidden || ((string) parse_url($src, PHP_URL_PATH) === '' || parse_url($src, PHP_URL_PATH) === '/') && preg_match('#^(https?:)?//#', $src) === 1) {
                $img->remove();
            }
        }
    }

    /** Wrappers without text of their own are replaced by their children, empty elements go; repeated until nothing changes. */
    private static function flatten(Element $root): void
    {
        for ($pass = 0; $pass < 20; $pass++) {
            $changed = false;
            foreach (iterator_to_array($root->querySelectorAll('*')) as $el) {
                if (!$root->contains($el) || $el->isSameNode($root)) {
                    continue;
                }
                $tag = strtolower($el->localName);
                if ($tag === 'talea-widget' || in_array($tag, ['img', 'br', 'hr', 'video', 'audio', 'source', 'picture'], true)) {
                    continue;
                }
                if (trim($el->textContent) === '' && $el->querySelector('img, talea-widget, picture, video, audio, hr') === null) {
                    $el->remove();
                    $changed = true;
                } elseif (in_array($tag, self::WRAPPERS, true) && !self::hasOwnText($el)) {
                    $el->replaceWith(...iterator_to_array($el->childNodes));
                    $changed = true;
                }
            }
            if (!$changed) {
                return;
            }
        }
    }

    private static function hasOwnText(Element $el): bool
    {
        foreach ($el->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '') {
                return true;
            }
        }

        return false;
    }
}
