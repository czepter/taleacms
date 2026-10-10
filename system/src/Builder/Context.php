<?php

declare(strict_types=1);

namespace Talea\Builder;

use Talea\Core\App;

/**
 * Render state of one page of the site: what was used (so that the CSS covers only what is needed) across the page build and
 * the site parts (header, footer, wrapper), so that the page gets a single CSS block. Editor mode switches with the build being rendered.
 */
final class Context
{
    /** The page has an element with a display condition – it must not go into the page cache. */
    public bool $withoutCache = false;

    /** The page has a Compose section: Build::css adds its grid CSS (a page without one weighs nothing extra). */
    public bool $compose = false;

    /** @var array<string, true> element types on the page */
    public array $types = [];

    /** @var array<string, true> classes on the page */
    public array $classes = [];

    /** CSS of elements with their own style (layer „prvky“). */
    public string $css = '';

    /** @var list<array{0:string, 1:string}> questions and answers from FAQ elements – for the page's structured data */
    public array $faq = [];

    /** @var list<array{type: string, fields: array<string, mixed>}> cleaned nodes of Structured data elements – for the page's JSON-LD graph */
    public array $structured = [];

    /** @var list<array{title:string, slug:string}> pages of the main navigation (supplied by the site) */
    public array $menu = [];

    /** Path of the displayed page (for aria-current in the navigation). */
    public string $path = '';

    /** Finished switcher of the site's language versions (empty on a single-language site). */
    public string $languages = '';

    /** @var array<string, array{name:string, url:string, active:bool, translated:bool}> language versions for the Language switcher element */
    public array $languageList = [];

    /** Light and dark color scheme switcher for visitors (empty when disabled); the Navigation element adds it after the menu. */
    public string $colorScheme = '';

    /** @var array<string, array{0: string, 1: string}>|null values of the collection item for {{tags}} (inside a Collection list and on the item page) */
    public ?array $item = null;

    /** Collection list depth: the elements inside repeat, so they get their style through a class, not through the id. */
    public int $inLoop = 0;

    /** @var array<int, array<string, mixed>|null> loaded components (one component is often on a page several times) */
    public array $components = [];

    /** @var list<int> components being rendered right now (protection against a component inside itself) */
    public array $nesting = [];

    /** @var array<string, array{pred: string, za: string}> controls around an element (filters and pagination of a collection list) by id */
    public array $surroundings = [];

    /** @var array<string, true> elements whose CSS is already on the page */
    public array $styles = [];

    /** Where the build being rendered comes from: „page:<id>“ or „part:<typ>:<jazyk>“ (the form finds its fields by it). */
    public string $source = '';

    /** @var list<array{0: string, 1: string}> breadcrumbs of the displayed page: [text, url]; the last one is the page itself (url '') */
    public array $breadcrumbs = [];

    /** Content the system inserts into the wrapper (the "Page content" element): news item, list, 404 page. */
    public string $content = '';

    /** Heading anchors from texts on the page (Elements\Text) – so that they do not repeat on one page. @var array<string, true> */
    public array $anchors = [];

    /** Comment mode of a shared preview (2.15, Core\DraftComments): elements carry data-tl-id so a comment can point at one, nothing else of the editor. */
    public bool $markIds = false;

    /** @var array<string, string> element id => attributes the element gets (A/B tests: data-experiment and data-variant, Builder\Experiments) */
    public array $marks = [];

    public function __construct(public readonly App $app, public bool $editor = false)
    {
    }

    public function url(string $path): string
    {
        return $this->app->url($path);
    }

    /** Image url from media/ completed with the installation path; a foreign url stays. */
    public function image(string $src): string
    {
        return preg_match('#^(https?:)?//|^/#', $src) ? $src : $this->app->request->basePath() . '/' . $src;
    }
}
