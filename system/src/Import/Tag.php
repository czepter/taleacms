<?php

declare(strict_types=1);

namespace Kaleta\Import;

/** A tag (label) of the old site (Import\Source). Created on first use by a news item; looked up by the slug of its name. */
final readonly class Tag
{
    public function __construct(public string $key, public string $name, public string $slug)
    {
    }
}
