<?php

declare(strict_types=1);

namespace Kaleta\Extension;

/**
 * An add-on for Kaleta (3.0). The class named in the add-on's extension.json implements this; Kaleta creates it once per
 * request and calls register() with the add-on's Api – the only surface an add-on should use (docs/EXTENSIONS.md). What
 * register() hands to the Api is kept for the request; register() itself must be quick and must not write anything.
 */
interface ExtensionInterface
{
    public function register(Api $api): void;
}
