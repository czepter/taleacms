<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * A page of the website import has used up the time of this step for its downloads: what was brought over is remembered
 * (tl_import_map), the page is not finished and the next step continues it. Not a RuntimeException: it is no failure.
 */
final class WebImportPaused extends \Exception
{
}
