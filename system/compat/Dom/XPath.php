<?php

declare(strict_types=1);

namespace Dom;

/** PHP 8.3 only – see Node.php. XPath over a document parsed with Dom\HTML_NO_DEFAULT_NS (tools/find-czech.php). */
final class XPath extends \DOMXPath
{
    public function __construct(HTMLDocument $document, bool $registerNodeNS = true)
    {
        parent::__construct($document, $registerNodeNS);
    }
}
