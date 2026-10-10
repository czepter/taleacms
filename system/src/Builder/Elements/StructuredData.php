<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;
use Talea\Builder\StructuredData as Vocabulary;

/**
 * Typed schema.org structured data for the page (HF-11). Renders nothing into the body: the cleaned node goes to the page's
 * single JSON-LD @graph (Front\Seo), linked to the company and website nodes. The content {type, fields} is validated against
 * the curated vocabulary (Builder\StructuredData), the only gate.
 */
final class StructuredData extends Element
{
    public const string TYPE = 'structured_data';
    public const string NAME = 'Structured data';
    public const string DESCRIPTION = 'Markup for search engines (a product, an event, a recipe …): not visible on the page, it can earn rich results.';
    public const string ICON = 'code';
    public const string GROUP = 'Advanced';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'data' => ['type' => 'structured', 'label' => 'Structured data'],
        ];
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $node = $p['content']['data'] ?? null;
        [$clean] = Vocabulary::sanitize($node);
        if ($clean !== null && $clean['fields'] !== []) {
            $k->structured[] = $clean;
        }
        if ($k->editor) {
            $missing = $clean !== null ? Vocabulary::missing($clean)['required'] : [];

            return '<span' . $a . ' style="display:inline-block;padding:.4rem .8rem;border:1px dashed currentColor;border-radius:999px;font-size:.85rem">{ } '
                . e($clean !== null ? t('Structured data: %s', $clean['type']) : t('Structured data – choose a type'))
                . ($missing !== [] ? ' · ' . e(t('missing: %s', implode(', ', $missing))) : '') . '</span>';
        }

        return '';
    }
}
