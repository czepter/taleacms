<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Formatted text from the editor: paragraphs, lists, subheadings, links, tables. */
final class Text extends Element
{
    public const string TYPE = 'text';
    public const string NAME = 'Text';
    public const string DESCRIPTION = 'Paragraphs, lists, links and tables from the editor.';
    public const string ICON = 'text';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return ['html' => ['type' => 'html', 'label' => 'Text', 'default' => '<p>' . t('Write your text here. A few sentences telling visitors what they will find here are enough.') . '</p>']];
    }

    public static function baseCss(): string
    {
        return '.ka-text > :first-child { margin-block-start: 0; }
.ka-text > :last-child { margin-block-end: 0; }
.ka-text img { max-width: 100%; height: auto; }
.ka-text pre { max-width: 100%; overflow-x: auto; }
.ka-text :is(h2, h3)[id] { scroll-margin-top: 6rem; }
:where(.build) mark { background: none; color: var(--ka-color-secondary); }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $html = $k->inLoop === 0 ? self::anchors($p['content']['html'], $k) : $p['content']['html'];

        return '<div' . self::withClass($a, 'ka-text') . '>' . $html . '</div>';
    }

    /**
     * Subheadings h2 and h3 get an anchor from their text ("#what-you-need"), so that one can link to a specific part of the page.
     * A heading with its own id stays as it is; in collection list cards no anchors are added (they would repeat).
     */
    private static function anchors(string $html, Context $k): string
    {
        if (!preg_match('/<h[23]\b/i', $html)) {
            return $html;
        }

        return preg_replace_callback('#<(h[23])\b([^>]*)>(.*?)</\1>#is', function (array $m) use ($k): string {
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5));
            if ($text === '' || preg_match('/\sid\s*=/i', $m[2])) {
                return $m[0];
            }
            $id = $base = slugify($text, 60);
            if ($id === '') {
                return $m[0];
            }
            for ($i = 2; isset($k->anchors[$id]); $i++) {
                $id = $base . '-' . $i;
            }
            $k->anchors[$id] = true;

            return '<' . $m[1] . $m[2] . ' id="' . e($id) . '">' . $m[3] . '</' . $m[1] . '>';
        }, $html) ?? $html;
    }

    /** Adds the type's base class to the finished attributes (before the user's classes). */
    public static function withClass(string $a, string $className): string
    {
        return str_contains($a, ' class="') ? str_replace(' class="', ' class="' . $className . ' ', $a) : $a . ' class="' . $className . '"';
    }
}
