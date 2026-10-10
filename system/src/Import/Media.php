<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * A file of the old site's media library (Import\Source) – optional: the preview counts them, images used in posts are
 * found in the posts' HTML and the featured image in Post::$featureImageUrl anyway.
 */
final readonly class Media
{
    public function __construct(public string $url, public string $alt = '')
    {
    }
}
