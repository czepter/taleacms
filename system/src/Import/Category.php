<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * A category of the old site (Import\Source). Our categories have no tree, so $parent is only informative. Only
 * categories with posts are created, on first use, like in the WordPress import.
 */
final readonly class Category
{
    public function __construct(public string $key, public string $name, public string $slug, public string $parent = '')
    {
    }
}
