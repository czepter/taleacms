<?php

declare(strict_types=1);

namespace Talea\Builder\Elements;

use Talea\Builder\Context;
use Talea\Builder\Element;

/**
 * A "back to top" button in the bottom right corner. It appears once the visitor scrolls down (a scroll-driven animation, no script);
 * where the browser cannot do that, it is always visible. Best placed in the footer – then it is on all pages.
 */
final class BackToTop extends Element
{
    public const string TYPE = 'back_to_top';
    public const string NAME = 'Back-to-top button';
    public const string DESCRIPTION = 'A floating arrow back to the top of the page – put it in the footer.';
    public const string ICON = 'back-to-top';
    public const string GROUP = 'Advanced';
    public const array HTML_TAGS = ['a'];

    public static function baseCss(): string
    {
        return '.tl-back-to-top { position: fixed; inset: auto 1rem 1rem auto; z-index: 50; display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border-radius: 50%; background: var(--tl-color-text); color: var(--tl-color-background); box-shadow: var(--tl-shadow-m); }
.tl-back-to-top svg { width: 1.2rem; height: 1.2rem; }
@supports (animation-timeline: scroll()) {
	.tl-back-to-top { animation: tl-back-to-top linear both; animation-timeline: scroll(); animation-range: 0 40vh; }
	@keyframes tl-back-to-top { from { opacity: 0; visibility: hidden; translate: 0 1rem; } }
}';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        if ($k->editor) {
            return '<span' . $a . ' style="display:inline-block;padding:.4rem .8rem;border:1px dashed currentColor;border-radius:999px;font-size:.85rem">↑ ' . e(t('Back-to-top button (bottom right on the website)')) . '</span>';
        }

        return '<a' . Text::withClass($a, 'tl-back-to-top') . ' href="#" aria-label="' . e(t('Back to top')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></a>';
    }
}
