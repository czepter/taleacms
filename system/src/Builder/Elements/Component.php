<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Components;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Use of a component: inserts its published build and fills in its own values of its {{properties}}.
 * On the site it has no tag of its own (it outputs the component content directly) unless the use has its own style, class or anchor;
 * in the editor an element wraps it so that it can be selected as a whole.
 */
final class Component extends Element
{
    public const string TYPE = 'component';
    public const string NAME = 'Component';
    public const string DESCRIPTION = 'A reusable block – editing the component updates it everywhere it is used.';
    public const string ICON = 'component';
    public const string GROUP = 'Advanced';
    public const array HTML_TAGS = ['div'];

    public static function properties(): array
    {
        return [
            'component' => ['type' => 'text', 'label' => 'Component', 'default' => '', 'max' => 12],
            'values' => ['type' => 'values', 'label' => 'Properties', 'default' => []],
        ];
    }

    /** Content of the component with the values of this use (called by Build when rendering). */
    public static function inner(array $p, Context $k, callable $render): string
    {
        $id = (int) $p['content']['component'];
        if (!array_key_exists($id, $k->components)) {
            $k->components[$id] = $id > 0 ? Components::byId($k->app->db(), $id) : null;
        }
        $component = $k->components[$id];
        $build = $component === null ? null : \Kaleta\Builder\Build::fromJson($component['build'] ?? $component['build_draft']);
        if ($build === null) {
            return $k->editor ? '<p>' . e(t('Choose a component in the Content panel.')) . '</p>' : '';
        }
        if (in_array($id, $k->nesting, true) || count($k->nesting) >= Components::MAX_NESTING) {
            return ''; // a component inside itself would render forever
        }
        // inside, only the published version is used, without editor markers (the component is selected as a whole);
        // a component can be on a page several times, hence the style through a class as in a collection list
        [$item, $editor, $loop] = [$k->item, $k->editor, $k->inLoop];
        $k->nesting[] = $id;
        $k->item = Components::values($component, is_array($p['content']['values'] ?? null) ? $p['content']['values'] : []);
        $k->editor = false;
        $k->inLoop++;
        try {
            return $render($build);
        } finally {
            array_pop($k->nesting);
            [$k->item, $k->editor, $k->inLoop] = [$item, $editor, $loop];
        }
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        // a wrapper only where the use has its own style, class or anchor (otherwise it would add a needless level to grids and flex)
        $hasWrapper = str_contains($a, ' id="') || str_contains($a, ' class="');
        if ($hasWrapper) {
            return '<div' . $a . '>' . $children . '</div>';
        }

        return $k->editor ? '<div' . $a . ' style="display:contents">' . $children . '</div>' : $children;
    }
}
