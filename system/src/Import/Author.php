<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * An author of the old site (Import\Source). No account is created for them: the mapping says which of our users their
 * posts belong to, by default the administrator who runs the import.
 */
final readonly class Author
{
    public function __construct(public string $key, public string $name, public string $email = '')
    {
    }
}
